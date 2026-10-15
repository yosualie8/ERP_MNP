<?php

namespace App\Support;

use App\Models\KomponenAnggaran;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use Illuminate\Support\Collection;

/**
 * Anggaran (rencana) HPP & omset per tahap. Nilai baris: volume × harga, atau (komisi/overhead) persen × total omset.
 * Progres fisik = kubikasi Ritasi terealisasi ÷ volume m³ baris omset bersumber kubikasi (keputusan owner 15 Okt 2026).
 */
class Anggaran
{
    /** Sumber realisasi tiap komponen (dipakai membandingkan rencana vs aktual). */
    public const SUMBER = [
        'omset' => ['kubikasi' => 'Kubikasi Ritasi (m³)', 'rit' => 'Jumlah rit Ritasi', 'manual' => 'Manual'],
        'hpp' => [
            'rit' => 'Ritasi (Harga Jual per rit)', 'ngepok' => 'Ngepok (Ritasi)', 'solar' => 'Solar (Data BBM)', 'rental' => 'Jam HM (Data BBM)',
            'kas_gaji' => 'Kas Harian — Gaji & Tunjangan', 'kas_operasional' => 'Kas Harian — biaya operasional', 'kas_hpp' => 'Kas Harian — akun HPP',
            'komisi' => 'Komisi (% omset)', 'overhead' => 'Overhead (bagian biaya umum)', 'manual' => 'Manual',
        ],
    ];

    public const CARA = ['volume' => 'Volume × harga satuan', 'persen' => '% dari total omset'];

    /**
     * Nilai tiap baris + total. $baris: iterable berisi komponen (KomponenAnggaran), volume, harga, persen.
     *
     * @return array{omset: float, hpp: float, margin: float, persen_margin: ?float, nilai: array<int, float>}
     */
    public static function hitung(iterable $baris): array
    {
        $baris = collect($baris)->values();
        $nilai = [];
        $omset = 0.0;
        foreach ($baris as $i => $b) {
            if ($b->komponen->jenis === 'omset') {
                $nilai[$i] = round(($b->volume ?? 0) * ($b->harga ?? 0), 2);
                $omset += $nilai[$i];
            }
        }
        $hpp = 0.0;
        foreach ($baris as $i => $b) {
            if ($b->komponen->jenis === 'hpp') {
                $nilai[$i] = round($b->komponen->cara === 'persen' ? ($b->persen ?? 0) / 100 * $omset : ($b->volume ?? 0) * ($b->harga ?? 0), 2);
                $hpp += $nilai[$i];
            }
        }
        ksort($nilai);

        return ['omset' => $omset, 'hpp' => $hpp, 'margin' => $omset - $hpp, 'persen_margin' => $omset > 0 ? ($omset - $hpp) / $omset * 100 : null, 'nilai' => $nilai];
    }

    /** Volume rencana (m³) = jumlah volume baris omset bersumber kubikasi. */
    public static function volumeRencana(iterable $baris): float
    {
        return (float) collect($baris)->filter(fn ($b) => $b->komponen->jenis === 'omset' && $b->komponen->sumber === 'kubikasi')->sum('volume');
    }

    /** Rata-rata kubikasi per rit urug 6 bulan terakhir (untuk memperkirakan jumlah rit dari volume m³). */
    public static function rataKubikasi(): float
    {
        return (float) (Ritasi::where('tanggal', '>=', now()->subMonths(6))->whereRaw("LOWER(COALESCE(jenis_buangan, '')) <> 'ngepok'")
            ->where('kubikasi', '>', 0)->avg('kubikasi') ?: 20);
    }

    /**
     * Baris awal dari template tipe tahap (komponen aktif yang tipe bawaannya memuat tipe ini), diisi dari data tahap
     * (volume, harga/m³, tarif BBM & rental, komisi). Belum disimpan — ditinjau dulu di form.
     *
     * @return Collection<int, object>
     */
    public static function template(ProyekTahap $t): Collection
    {
        $rata = self::rataKubikasi();
        $m3 = $t->volume ?: ($t->omset && $t->harga_m3 ? $t->omset / $t->harga_m3 : null);
        $rit = $m3 ? (int) ceil($m3 / $rata) : null;

        return KomponenAnggaran::where('aktif', true)->orderBy('jenis', 'desc')->orderBy('urutan')->get()
            ->filter(fn ($k) => in_array($t->tipe, $k->tipe_bawaan ?? [], true))
            ->values()
            ->map(function ($k, $i) use ($t, $m3, $rit) {
                [$volume, $harga, $persen] = match ($k->kode) {
                    'urugan_m3' => [$m3, $t->harga_m3 ?? $k->nilai_bawaan, null],
                    'tanah_rit' => [$rit, $k->nilai_bawaan, null],
                    'solar' => [null, $t->tarif_bbm ?? $k->nilai_bawaan, null],
                    'rental' => [null, $t->tarif_rental ?? $k->nilai_bawaan, null],
                    'komisi_lapangan', 'komisi_yosua', 'komisi_verlix' => [null, null, $t->{$k->kode} ?? $k->nilai_bawaan],
                    default => $k->cara === 'persen' ? [null, null, $k->nilai_bawaan] : [null, $k->nilai_bawaan, null],
                };

                return (object) ['komponen_id' => $k->id, 'komponen' => $k, 'uraian' => null, 'volume' => $volume, 'harga' => $harga, 'persen' => $persen, 'urutan' => $i];
            });
    }
}
