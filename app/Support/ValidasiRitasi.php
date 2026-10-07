<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pemeriksaan rit saat input.
 * GALAT (memblokir, tidak bisa dikonfirmasi — permintaan user 7 Okt 2026): No DO wajib & harus ada di Kas UJ; satu No DO dan
 * satu No Seri hanya boleh dipakai SATU kali di seluruh data ritasi (tanpa melihat tahap).
 * DT, driver & jenis kendaraan tidak diketik admin: diambil dari Kas UJ lewat No DO ({@see dariUj}).
 * FLAG (boleh dikonfirmasi): R6 jenis kendaraan beda dari histori ritasi DT itu.
 */
class ValidasiRitasi
{
    /** Ekspresi SQL kunci nomor ("0039" = "39", spasi diabaikan) — sama dengan {@see LembarRitasi::kunciAngka}. */
    private static function sqlKunci(string $kolom): string
    {
        return "TRIM(LEADING '0' FROM REPLACE({$kolom}, ' ', ''))";
    }

    /**
     * Data truk per No DO dari Kas UJ. Bila satu DO tercatat untuk beberapa DT (biasanya baris Sparepart truk lain memakai
     * nomor DO yang sama), yang dipakai baris "Uang Jalan" terbaru; tanpa itu, DT yang paling sering muncul.
     *
     * @param  array<int, string|null>  $daftarDo
     * @return array<string, array{tempat: ?string, no_lambung: ?string, driver: ?string, jenis_kendaraan: ?string, id_uj: string}> kunci = kunciAngka(DO)
     */
    public static function dariUj(array $daftarDo): array
    {
        $kunci = collect($daftarDo)->map(fn ($d) => LembarRitasi::kunciAngka($d))->filter()->unique()->values();
        if ($kunci->isEmpty()) {
            return [];
        }
        $baris = UjDetail::query()->where('biaya_transfer', false)->whereNotNull('no_do')
            ->whereIn(DB::raw(self::sqlKunci('no_do')), $kunci->all())
            ->orderBy('baris')->get(['id_uj', 'tanggal', 'nama', 'no_mobil', 'no_do', 'kategori', 'keterangan', 'jenis_kendaraan'])
            ->groupBy(fn ($d) => LembarRitasi::kunciAngka($d->no_do));

        $hasil = [];
        foreach ($baris as $k => $g) {
            $ber = $g->filter(fn ($d) => preg_match('/^DT \d{3}$/', (string) $d->no_mobil));
            $pilih = $ber->filter(fn ($d) => strcasecmp(trim((string) $d->kategori), 'Uang Jalan') === 0)->last();
            if (! $pilih && $ber->isNotEmpty()) {
                $dt = $ber->countBy('no_mobil')->sortDesc()->keys()->first();
                $pilih = $ber->where('no_mobil', $dt)->last();
            }
            $pilih ??= $g->last();
            $sama = $g->where('no_mobil', $pilih->no_mobil);
            $hasil[$k] = [
                'tempat' => TebakGalian::tempat($sama->whereIn('kategori', ['Uang Jalan', 'Uang Tanah'])->pluck('keterangan')),
                'no_lambung' => $pilih->no_mobil ?: null,
                'driver' => $pilih->nama ?: $sama->pluck('nama')->filter()->last(),
                'jenis_kendaraan' => NomorMobil::rapikanJenis($pilih->jenis_kendaraan ?: $sama->pluck('jenis_kendaraan')->filter()->last()),
                'id_uj' => $sama->pluck('id_uj')->filter()->unique()->take(3)->implode(', '),
            ];
        }

        return $hasil;
    }

    /**
     * Kesalahan yang memblokir penyimpanan (tidak bisa dikonfirmasi).
     *
     * @param  array<int, array>  $rit  indeks dipertahankan
     * @param  array<string, array>  $uj  hasil {@see dariUj}
     * @return array<int, array<int, string>>
     */
    public static function galat(array $rit, array $uj, ?int $kecualiBaris = null): array
    {
        $kumpul = fn (string $kolom) => collect($rit)->pluck($kolom)->map(fn ($v) => LembarRitasi::kunciAngka($v))->filter()->unique()->values()->all();
        $dipakai = fn (string $kolom, array $nilai) => $nilai ? Ritasi::query()->whereIn(DB::raw(self::sqlKunci($kolom)), $nilai)
            ->when($kecualiBaris, fn ($q) => $q->where('baris', '!=', $kecualiBaris))
            ->orderBy('baris')->get(['baris', 'tahap', 'no_seri', 'no_do', 'tanggal', 'no_lambung'])
            ->groupBy(fn ($r) => LembarRitasi::kunciAngka($r->{$kolom})) : collect();
        $doLama = $dipakai('no_do', $kumpul('no_do'));
        $seriLama = $dipakai('no_seri', $kumpul('no_seri'));
        $sebut = fn ($x) => 'baris '.$x->baris.' ('.$x->tahap.', '.$x->tanggal?->translatedFormat('j M Y').', No Seri '.$x->no_seri.', DO '.$x->no_do.')';

        $hasil = [];
        $doDiInput = [];
        $seriDiInput = [];
        foreach ($rit as $i => $r) {
            $g = [];
            $do = LembarRitasi::kunciAngka($r['no_do'] ?? null);
            $seri = LembarRitasi::kunciAngka($r['no_seri'] ?? null);
            if (! $do) {
                $g[] = 'No DO wajib diisi.';
            } else {
                if (! preg_match('/^\d{1,10}$/', preg_replace('/\s+/', '', (string) $r['no_do']))) {
                    $g[] = 'No DO "'.$r['no_do'].'" harus berupa angka.';
                }
                if ($lama = $doLama->get($do)) {
                    $g[] = 'No DO '.$r['no_do'].' sudah pernah diinput: '.$lama->take(3)->map($sebut)->implode('; ').'. Satu No DO hanya boleh satu kali.';
                }
                if (isset($doDiInput[$do])) {
                    $g[] = 'No DO '.$r['no_do'].' sama dengan rit baris '.($doDiInput[$do] + 1).' di input ini.';
                }
                if (! isset($uj[$do])) {
                    $g[] = 'No DO '.$r['no_do'].' tidak ditemukan di Kas Uang Jalan — catat dulu uang jalannya (dengan No DO ini) di Input UJ, lalu ulangi.';
                } elseif (! $uj[$do]['no_lambung']) {
                    $g[] = 'No DO '.$r['no_do'].' ada di Kas UJ ('.$uj[$do]['id_uj'].') tapi No Mobil-nya kosong — lengkapi dulu di Kas UJ.';
                }
                $doDiInput[$do] ??= $i;
            }
            if ($seri) {
                if ($lama = $seriLama->get($seri)) {
                    $g[] = 'No Seri '.$r['no_seri'].' sudah pernah diinput: '.$lama->take(3)->map($sebut)->implode('; ').'. Satu No Seri hanya boleh satu kali.';
                }
                if (isset($seriDiInput[$seri])) {
                    $g[] = 'No Seri '.$r['no_seri'].' sama dengan rit baris '.($seriDiInput[$seri] + 1).' di input ini.';
                }
                $seriDiInput[$seri] ??= $i;
            }
            if ($g) {
                $hasil[$i] = $g;
            }
        }

        return $hasil;
    }

    /**
     * FLAG yang boleh dikonfirmasi admin.
     *
     * @param  array<int, array>  $rit  indeks dipertahankan (DT & jenis sudah diisi dari Kas UJ)
     * @return array<int, array<int, array{kode: string, prioritas: string, pesan: string}>>
     */
    public static function periksa(array $rit): array
    {
        $dt = collect($rit)->pluck('no_lambung')->filter()->unique()->all();
        /** @var Collection $jenisDt */
        $jenisDt = Ritasi::whereIn('no_lambung', $dt)->whereNotNull('jenis_kendaraan')->where('tanggal', '>=', now()->subYear())
            ->selectRaw('no_lambung, jenis_kendaraan, COUNT(*) n')->groupBy('no_lambung', 'jenis_kendaraan')->get()
            ->groupBy('no_lambung')->map(fn ($g) => $g->sortByDesc('n')->first()->jenis_kendaraan);

        $temuan = [];
        foreach ($rit as $i => $r) {
            if ($r['no_lambung'] && $r['jenis_kendaraan'] && ($seharusnya = $jenisDt[$r['no_lambung']] ?? null) && $seharusnya !== $r['jenis_kendaraan']) {
                $temuan[$i][] = ['kode' => 'R6', 'prioritas' => 'sedang', 'pesan' => "{$r['no_lambung']} di histori ritasi tercatat {$seharusnya}, di Kas UJ (DO {$r['no_do']}) {$r['jenis_kendaraan']}."];
            }
        }

        return $temuan;
    }
}
