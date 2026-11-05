<?php

namespace App\Support;

use App\Models\JagoMutasi;
use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Potongan hutang sopir di Input UJ (owner 4 Nov 2026): transfer uang jalan yang catatannya / keterangannya menyebut "potong" (potong hutang,
 * potong MK) — Valen memilih di form itu juga potongannya masuk ke sopir mana, dari baris uang jalan (No DO) mana, berapa.
 *  - Potong hutang: hutang sopir berkurang, menjadi bagian uang jalan DO itu (buku: jenis "pakai" peruntukan "uj").
 *  - Muatan kurang (MK, sopir dinilai lalai muatan kurang): hutang sopir bertambah (jenis "muatan") dan langsung lunas karena uang jalan
 *    DO itu sudah ditransfer kurang sebesar potongannya (jenis "pakai" "uj") — uang jalan DO tetap utuh, sopir menanggung potongannya.
 * Catatan buku ditandai "[UJ-xxxxx]" (ID UJ baris uang jalannya) → ikut terhapus bila transaksi UJ-nya dihapus.
 * Catatan bank / keterangan yang menyebut "potong" / "MK" wajib diisi potongannya, atau dinyatakan bukan potongan hutang.
 */
class PotongHutangUj
{
    public const JENIS = ['hutang' => 'Potong hutang', 'muatan' => 'Muatan kurang (MK)'];

    private const PEMICU = '/potong|\bMK\b/i';

    /** Teks pemicu (catatan transfer bank + catatan baris biaya transfernya + keterangan detail) yang menyebut potongan; null bila tidak ada. */
    public static function pemicu(array $input, ?JagoMutasi $m): ?string
    {
        $teks = collect([$m?->catatan, $m ? MutasiJago::barisBiaya($m)?->catatan : null])->concat(array_column($input['detail'], 'keterangan'))
            ->filter(fn ($t) => $t && preg_match(self::PEMICU, $t))->unique()->values();

        return $teks->isEmpty() ? null : mb_strimwidth($teks->implode(' · '), 0, 200, '…');
    }

    /**
     * Baca & periksa isian potongan (potong[i][no_do|jenis|sopir|nominal]) sebelum transaksi ditulis.
     *
     * @return array<int, array{detail: int, jenis: string, sopir: string, nominal: int, no_do: string, no_mobil: ?string}>|string pesan tolak
     */
    public static function periksa(Request $request, array $input, ?JagoMutasi $m): array|string
    {
        $baris = collect((array) $request->input('potong', []))
            ->filter(fn ($p) => is_array($p) && (trim((string) ($p['nominal'] ?? '')) !== '' || trim((string) ($p['no_do'] ?? '')) !== ''))->values();
        if ($baris->isEmpty()) {
            $teks = self::pemicu($input, $m);

            return $teks && ! $request->boolean('potong_bukan')
                ? "Catatan transfer / keterangan menyebut potongan (\"{$teks}\"). Isi bagian Potongan hutang sopir: pilih baris uang jalannya, sopir, jenis & nominalnya — atau centang \"Bukan potongan hutang sopir\"."
                : [];
        }
        $siap = [];
        $perSopir = [];
        $perDo = [];
        foreach ($baris as $i => $p) {
            $no = 'Potongan hutang baris '.($i + 1).': ';
            $jenis = $p['jenis'] ?? '';
            if (! isset(self::JENIS[$jenis])) {
                return $no.'pilih jenisnya (potong hutang / muatan kurang).';
            }
            $nominal = (int) preg_replace('/\D/', '', (string) ($p['nominal'] ?? ''));
            if ($nominal <= 0) {
                return $no.'isi nominal potongannya.';
            }
            $k = SaldoSopir::kunciDo($p['no_do'] ?? null);
            $idx = $k === null ? false : collect($input['detail'])
                ->search(fn ($d) => SaldoSopir::kunciDo($d['no_do']) === $k && stripos((string) $d['kategori'], 'jalan') !== false);
            if ($idx === false) {
                return $no.'pilih baris Uang Jalan (ber-No DO) di transaksi ini yang uang jalannya dipotong. Potongan di luar uang jalan dicatat lewat menu Hutang Supir.';
            }
            $d = $input['detail'][$idx];
            $sopir = SaldoSopir::kunci($p['sopir'] ?? null) ?? SaldoSopir::kunci($d['nama'] ?? null);
            if (! $sopir) {
                return $no.'pilih sopirnya.';
            }
            // Total uang jalan DO itu (Kas UJ lain + potongan utang lain + baris ini + potongan ini) tetap dalam batas wajar.
            $perDo[$k] = ($perDo[$k] ?? 0) + $nominal;
            $lain = (float) UjDetail::where('biaya_transfer', false)->where('kategori', 'like', '%jalan%')->where('no_do', 'like', '%'.$k)->get(['no_do', 'nominal'])
                ->filter(fn ($u) => SaldoSopir::kunciDo($u->no_do) === $k)->sum('nominal')
                + (float) UjPengalihan::where('jenis', 'pakai')->where('peruntukan', 'uj')->where('no_do', 'like', '%'.$k)->get(['no_do', 'nominal'])
                    ->filter(fn ($u) => SaldoSopir::kunciDo($u->no_do) === $k)->sum('nominal');
            $total = $lain + (int) $d['nominal'] + $perDo[$k];
            if ($total > ValidasiUj::MAKS_UJ_PER_DO) {
                return $no.'total uang jalan DO '.$d['no_do'].' jadi '.rp((int) $total).' (lebih dari '.rp(ValidasiUj::MAKS_UJ_PER_DO).'). Periksa nominal potongannya.';
            }
            if ($jenis === 'hutang') {
                $perSopir[$sopir] = ($perSopir[$sopir] ?? 0) + $nominal;
            }
            $siap[] = ['detail' => $idx, 'jenis' => $jenis, 'sopir' => $sopir, 'nominal' => $nominal, 'no_do' => ltrim(trim((string) $d['no_do']), "`'"), 'no_mobil' => $d['no_mobil']];
        }
        foreach ($perSopir as $s => $n) {
            $sisa = SaldoSopir::sisa($s);
            if ($n > $sisa + 0.5) {
                return "Potong hutang {$s} ".rp($n).' melebihi sisa hutangnya di luar DO ('.rp((int) $sisa).'). Periksa sopir / nominalnya — bila itu potongan muatan kurang, pilih jenis Muatan kurang (MK).';
            }
        }

        return $siap;
    }

    /**
     * Catat potongan ke buku Hutang Supir sesudah transaksi tertulis ($ids = nomor ID UJ per detail).
     *
     * @return string ringkasan untuk pesan sukses ('' bila tidak ada)
     */
    public static function catat(array $siap, array $input, array $ids, int $user): string
    {
        if (! $siap) {
            return '';
        }
        $teks = [];
        DB::transaction(function () use ($siap, $input, $ids, $user, &$teks) {
            foreach ($siap as $x) {
                $idUj = 'UJ-'.$ids[$x['detail']];
                $dasar = ['no_do' => $x['no_do'], 'no_mobil' => $x['no_mobil'], 'nama' => $x['sopir'], 'sopir' => $x['sopir'], 'tanggal' => $input['tanggal']->toDateString(),
                    'nominal' => $x['nominal'], 'user_id' => $user];
                $buat = [];
                if ($x['jenis'] === 'muatan') {
                    $buat[] = [...$dasar, 'jenis' => 'muatan', 'no_do' => null, 'keterangan' => "[{$idUj}] Muatan kurang (MK) — dipotong dari uang jalan DO {$x['no_do']}"];
                    $buat[] = [...$dasar, 'jenis' => 'pakai', 'peruntukan' => 'uj', 'keterangan' => "[{$idUj}] Potongan muatan kurang (MK) lunas: uang jalan DO {$x['no_do']} ditransfer kurang ".rp($x['nominal'])];
                } else {
                    $buat[] = [...$dasar, 'jenis' => 'pakai', 'peruntukan' => 'uj', 'keterangan' => "[{$idUj}] Potong hutang: bagian uang jalan DO {$x['no_do']} dari hutang sopir"];
                }
                foreach ($buat as $isi) {
                    $p = UjPengalihan::create($isi);
                    KasRiwayat::create(['aksi' => 'uj-alih-pakai', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
                        'ringkasan' => mb_strimwidth("Hutang sopir {$x['sopir']} (Input UJ): {$isi['keterangan']} Rp ".number_format($x['nominal'], 0, ',', '.'), 0, 490, '…'),
                        'isi' => $p->toArray(), 'user_id' => $user]);
                }
                $teks[] = self::JENIS[$x['jenis']].' '.$x['sopir'].' '.rp($x['nominal']).' (DO '.$x['no_do'].')';
            }
        });
        SaldoSopir::lupakan();

        return ' Hutang Supir: '.implode('; ', $teks).'.';
    }

    /** Hapus potongan milik ID UJ ini (transaksi UJ-nya dihapus); jumlah catatan yang dihapus. */
    public static function hapusMilik(array $idUj): int
    {
        $idUj = array_values(array_filter($idUj));
        if (! $idUj) {
            return 0;
        }
        $n = UjPengalihan::whereIn('jenis', ['pakai', 'muatan'])
            ->where(fn ($q) => collect($idUj)->each(fn ($id) => $q->orWhere('keterangan', 'like', '['.$id.']%')))->delete();
        SaldoSopir::lupakan();

        return $n;
    }
}
