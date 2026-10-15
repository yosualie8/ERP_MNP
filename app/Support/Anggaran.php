<?php

namespace App\Support;

use App\Models\KomponenAnggaran;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use Illuminate\Support\Collection;

/**
 * Anggaran (rencana) HPP & omset per tahap. Nilai baris: volume × harga, atau (komisi/overhead) persen × total omset.
 * Progres = realisasi HPP (biaya langsung + biaya umum teralokasi) ÷ HPP rencana (owner 15 Okt 2026 — bukan dari kubikasi/tagihan).
 */
class Anggaran
{
    /** Sumber realisasi tiap komponen (dipakai membandingkan rencana vs aktual). */
    public const SUMBER = [
        'omset' => ['kubikasi' => 'Kubikasi Ritasi (m³)', 'rit' => 'Jumlah rit Ritasi', 'manual' => 'Manual'],
        'hpp' => [
            'ritasi_m3' => 'Ritasi (kubikasi m³ & Harga Jual)', 'rit' => 'Ritasi (Harga Jual per rit)', 'ngepok' => 'Ngepok (Ritasi)', 'solar' => 'Solar (Data BBM)', 'rental' => 'Jam HM (Data BBM)',
            'kas_gaji' => 'Kas Harian — Gaji & Tunjangan', 'kas_operasional' => 'Kas Harian — biaya operasional', 'kas_hpp' => 'Kas Harian — akun HPP',
            'komisi' => 'Komisi (% omset)', 'overhead' => 'Overhead (bagian biaya umum)', 'manual' => 'Manual',
        ],
    ];

    public const CARA = ['volume' => 'Volume × harga satuan', 'persen' => '% dari total omset'];

    /** Komponen "tunda realisasi" (mis. komisi lapangan) dianggap terealisasi bila tanggal bayarnya di tahap sudah diisi. */
    public const TANGGAL_REALISASI = ['komisi_lapangan' => 'bayar_lapangan', 'komisi_yosua' => 'bayar_yosua', 'komisi_verlix' => 'bayar_verlix'];

    public static function ditunda($komponen, ?ProyekTahap $t): bool
    {
        return (bool) $komponen->tunda_realisasi && ! (($f = self::TANGGAL_REALISASI[$komponen->kode] ?? null) && $t?->{$f});
    }

    /**
     * Nilai tiap baris + total. $baris: iterable berisi komponen (KomponenAnggaran), volume, harga, persen.
     * Baris komponen "tunda realisasi" yang belum direalisasikan tetap dihitung nilainya tetapi TIDAK masuk total HPP.
     *
     * @return array{omset: float, hpp: float, margin: float, persen_margin: ?float, nilai: array<int, float>, tunda: array<int, true>}
     */
    public static function hitung(iterable $baris, ?ProyekTahap $t = null): array
    {
        $tunda = [];
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
                if (self::ditunda($b->komponen, $t)) {
                    $tunda[$i] = true;
                    continue;
                }
                $hpp += $nilai[$i];
            }
        }
        ksort($nilai);

        return ['omset' => $omset, 'hpp' => $hpp, 'margin' => $omset - $hpp, 'persen_margin' => $omset > 0 ? ($omset - $hpp) / $omset * 100 : null, 'nilai' => $nilai, 'tunda' => $tunda];
    }


    /** Rata-rata kubikasi per rit urug 6 bulan terakhir (untuk memperkirakan jumlah rit dari volume m³). */
    public static function rataKubikasi(): float
    {
        return (float) (Ritasi::where('tanggal', '>=', now()->subMonths(6))->whereRaw("LOWER(COALESCE(jenis_buangan, '')) <> 'ngepok'")
            ->where('kubikasi', '>', 0)->avg('kubikasi') ?: 20);
    }

    /** Perkiraan harga tanah & angkutan per m³ = Σ Harga Jual ÷ Σ kubikasi ritasi urug 6 bulan terakhir (dibulatkan ke ratusan). */
    public static function hargaTanahM3(): ?float
    {
        $r = Ritasi::where('tanggal', '>=', now()->subMonths(6))->whereRaw("LOWER(COALESCE(jenis_buangan, '')) <> 'ngepok'")
            ->where('kubikasi', '>', 0)->where('harga_jual', '>', 0)->selectRaw('sum(harga_jual) h, sum(kubikasi) k')->first();

        return $r && $r->k > 0 ? round($r->h / $r->k, -2) : null;
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
                    // luas & volume: tanah bersatuan m³ = volume urugan (owner 15 Okt 2026)
                    'tanah_m3' => [$m3, $k->nilai_bawaan ?? self::hargaTanahM3(), null],
                    'solar' => [null, $t->tarif_bbm ?? $k->nilai_bawaan, null],
                    'rental' => [null, $t->tarif_rental ?? $k->nilai_bawaan, null],
                    'komisi_lapangan', 'komisi_yosua', 'komisi_verlix' => [null, null, $t->{$k->kode} ?? $k->nilai_bawaan],
                    default => $k->cara === 'persen' ? [null, null, $k->nilai_bawaan] : [null, $k->nilai_bawaan, null],
                };

                return (object) ['komponen_id' => $k->id, 'komponen' => $k, 'uraian' => null, 'volume' => $volume, 'harga' => $harga, 'persen' => $persen, 'urutan' => $i];
            });
    }
}
