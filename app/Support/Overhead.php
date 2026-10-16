<?php

namespace App\Support;

use App\Models\AlokasiOverhead;
use App\Models\Bbm;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use Illuminate\Support\Collection;

/**
 * Pembagian biaya umum (pos overhead: tahap "tanpa tahap", kantor, dll.) ke tahap yang berjalan, per bulan.
 * Rekomendasi tujuan = tahap (bukan pos) yang ada aktivitasnya (Ritasi / BBM / Kas) di bulan itu, dibatasi proyek induk
 * yang sama bila cakupan pos = "induk". Owner memilih manual dari rekomendasi; hanya alokasi yang disimpan yang dihitung.
 * Biaya pos tidak memuat akun yang diabaikan (HPP ritasi, BBM, sparepart & keperluan DT) — lihat diabaikan(); komisi tetap ikut.
 * Metode: proporsional omset (omset rencana anggaran → omset tahap → volume × harga/m³) atau rata per tahap.
 */
class Overhead
{
    public const CAKUPAN = ['induk' => 'Proyek induk yang sama', 'semua' => 'Semua proyek berjalan'];

    private static ?array $cache = null;

    /** @return array{kas: array<int, array<string, float>>, aktif: array<int, array<string, true>>} */
    public static function data(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $kas = [];
        $aktif = [];
        $baris = DaftarProyek::queryKas()->selectRaw('c.kode cc, k.ref, a.nama akun, a.kelompok kel, b.tanggal, al.proyek_tahap_id alokasi, sum(b.nominal) s')
            ->groupBy('c.kode', 'k.ref', 'a.nama', 'a.kelompok', 'b.tanggal', 'al.proyek_tahap_id')->get();
        foreach ($baris as $r) {
            $t = substr((string) $r->tanggal, 0, 10);
            if ($id = $r->alokasi ?: DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, $t)) {
                $ym = substr($t, 0, 7);
                $aktif[$id][$ym] = true;
                // Biaya umum = Kas pos SELAIN akun yang diabaikan (lihat diabaikan()). Komisi tetap termasuk.
                if (! self::diabaikan($r->kel, $r->akun)) {
                    $kas[$id][$ym] = ($kas[$id][$ym] ?? 0) + (float) $r->s;
                }
            }
        }
        foreach (Ritasi::selectRaw("tahap, DATE_FORMAT(tanggal, '%Y-%m') ym")->distinct()->get() as $r) {
            if ($id = DaftarProyek::tahapId('ritasi', $r->tahap)) {
                $aktif[$id][$r->ym] = true;
            }
        }
        foreach (Bbm::whereNotNull('tahap_ids')->get(['tanggal', 'tahap_ids']) as $b) {
            foreach ((array) $b->tahap_ids as $id) {
                if ($id && $b->tanggal) {
                    $aktif[$id][$b->tanggal->format('Y-m')] = true;
                }
            }
        }

        // Mutasi BCA PT yang dihitung tanpa tahap → biaya pos biaya umum cost center-nya (ikut dibagi di Alokasi Biaya Umum).
        foreach (TandaMutasiPt::dihitung() as $b) {
            if ($b->pos_id) {
                $kas[$b->pos_id][$b->ym] = ($kas[$b->pos_id][$b->ym] ?? 0) + $b->rp;
            }
        }

        return self::$cache = ['kas' => $kas, 'aktif' => $aktif];
    }

    /**
     * Akun Kas yang TIDAK dihitung sebagai biaya proyek / biaya umum (owner 15 Okt 2026) karena sudah tertutup sumber lain:
     * - akun HPP (pembayaran ritasi/tanah) → biaya ritasi hanya dari Harga Jual ritasi bongkaran (Rp/rit menutup biaya truk);
     * - BBM/solar/bensin → solar hanya dari Data BBM & HM;
     * - sparepart (DT & alat berat) & keperluan DT → tertutup Harga Jual per rit dan tarif rental per jam HM.
     * Tahap tipe INFRA (owner 16 Okt 2026): akun HPP = material → DIHITUNG; BBM di luar alat berat (carry, pickup, genset)
     * → DIHITUNG; BBM alat berat (lihat ALAT_BERAT di keterangan) tetap dari Data BBM & HM; sparepart/DT tetap diabaikan.
     */
    public static function diabaikan(?string $kelompok, ?string $akun, ?string $tipe = null, ?string $keterangan = null): bool
    {
        if ($tipe === 'infra') {
            return (bool) preg_match('/spare\s*part|\bDT\b/i', (string) $akun)
                || (self::akunBbm($akun) && preg_match(self::ALAT_BERAT, (string) $keterangan));
        }

        return $kelompok === 'HPP' || self::akunBbm($akun) || (bool) preg_match('/spare\s*part|\bDT\b/i', (string) $akun);
    }

    /** Keterangan Kas yang menunjukkan BBM untuk alat berat (solarnya tercatat di Data BBM & HM). */
    public const ALAT_BERAT = '/exca|excavator|beko|vibro|dozer|loader|grader|alat berat|\bPC ?\d{2,3}\b|\bDT\b|dump/i';

    public static function akunBbm(?string $akun): bool
    {
        return (bool) preg_match('/bbm|solar|bensin/i', (string) $akun);
    }

    /** Basis omset per tahap: omset rencana anggaran → kolom omset tahap → volume × harga/m³ → 0. */
    public static function basisOmset(Collection $tahap): array
    {
        $tahap->loadMissing('anggaran.komponen');

        return $tahap->mapWithKeys(fn (ProyekTahap $t) => [$t->id => ($a = Anggaran::hitung($t->anggaran, $t)['omset']) > 0 ? $a
            : (float) ($t->omset ?: ($t->volume && $t->harga_m3 ? $t->volume * $t->harga_m3 : 0))])->all();
    }

    /** Tahap yang berjalan di bulan itu & boleh menerima alokasi (bukan pos biaya umum, bukan tipe biaya). */
    public static function berjalan(Collection $semua, string $ym): Collection
    {
        $aktif = self::data()['aktif'];

        return $semua->filter(fn ($t) => ! $t->pos_overhead && $t->tipe !== 'biaya' && isset($aktif[$t->id][$ym]))->values();
    }

    /** Rekomendasi tujuan pos di bulan itu. */
    public static function rekomendasi(ProyekTahap $pos, Collection $berjalan): array
    {
        return $berjalan->filter(fn ($t) => $pos->pos_overhead === 'semua' || $t->proyek_id === $pos->proyek_id)->pluck('id')->all();
    }

    /**
     * Bagian tiap tahap tujuan: [tahap_id => rupiah]. Metode omset dengan semua basis 0 → dibagi rata.
     *
     * @return array<int, float>
     */
    public static function bagi(float $biaya, array $tujuan, string $metode, array $basis): array
    {
        if (! $tujuan || $biaya == 0) {
            return [];
        }
        $bobot = collect($tujuan)->mapWithKeys(fn ($id) => [$id => $metode === 'omset' ? max(0, $basis[$id] ?? 0) : 1]);
        if ($bobot->sum() <= 0) {
            $bobot = $bobot->map(fn () => 1);
        }
        $total = $bobot->sum();

        return $bobot->map(fn ($b) => round($biaya * $b / $total, 2))->all();
    }

    /**
     * Total overhead teralokasi per tahap dari alokasi yang sudah disimpan (biaya pos bulan itu dihitung ulang dari Kas,
     * jadi transaksi susulan ikut terbagi).
     *
     * @return array<int, float>
     */
    public static function teralokasi(): array
    {
        return collect(self::rincianTeralokasi())->map(fn ($baris) => array_sum(array_column($baris, 'rp')))->all();
    }

    private static ?array $rincian = null;

    /**
     * Rincian biaya umum teralokasi per tahap: satu baris per bulan per pos (bukan per transaksi) — [tahap_id => [{bulan, pos, metode, porsi, rp}]].
     *
     * @return array<int, array<int, array{bulan: string, pos: string, metode: string, porsi: float, rp: float}>>
     */
    public static function rincianTeralokasi(): array
    {
        if (self::$rincian !== null) {
            return self::$rincian;
        }
        $alokasi = AlokasiOverhead::orderBy('bulan')->get();
        if ($alokasi->isEmpty()) {
            return self::$rincian = [];
        }
        $kas = self::data()['kas'];
        $basis = self::basisOmset(ProyekTahap::whereIn('id', $alokasi->pluck('tahap_ids')->flatten()->unique())->get());
        $namaPos = ProyekTahap::whereIn('id', $alokasi->pluck('pos_tahap_id')->unique())->pluck('nama', 'id');
        $hasil = [];
        foreach ($alokasi as $a) {
            $biaya = $kas[$a->pos_tahap_id][$a->bulan] ?? 0;
            foreach (self::bagi($biaya, $a->tahap_ids, $a->metode, $basis) as $id => $rp) {
                $hasil[$id][] = ['bulan' => $a->bulan, 'pos' => $namaPos[$a->pos_tahap_id] ?? '-', 'metode' => $a->metode, 'porsi' => $biaya ? $rp / $biaya * 100 : 0, 'rp' => $rp];
            }
        }

        return self::$rincian = $hasil;
    }
}
