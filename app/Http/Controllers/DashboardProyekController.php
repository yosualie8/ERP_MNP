<?php

namespace App\Http\Controllers;

use App\Models\ProyekTahap;
use App\Support\Anggaran;
use App\Support\DaftarProyek;
use App\Support\Overhead;
use Illuminate\View\View;

/**
 * Dashboard Progres Proyek: tahap berstatus Progress — persen progres (fisik: kubikasi bongkaran ÷ m³ HPP tanah; bila belum
 * ada baris tanah m³: serapan biaya), biaya yang sudah keluar (realisasi HPP) & rinciannya per komponen.
 */
class DashboardProyekController extends Controller
{
    public function __invoke(): View
    {
        [$ritasi, $kas, $bbm] = [DaftarProyek::ritasi(), DaftarProyek::kas(), DaftarProyek::bbm()];
        $overhead = Overhead::teralokasi();
        $baris = ProyekTahap::with(['proyek', 'anggaran.komponen'])->where('status', 'progress')->whereNull('pos_overhead')->where('tipe', '!=', 'biaya')->get()
            ->map(function ($t) use ($ritasi, $kas, $bbm, $overhead) {
                $oh = $overhead[$t->id] ?? 0;
                $h = Anggaran::hitung($t->anggaran, $t);
                $realisasi = array_sum(array_column(Anggaran::rincianRealisasi($t, $ritasi, $kas, $bbm), 1)) + $oh;
                $volTanah = Anggaran::volumeTanah($t->anggaran);
                $m3 = $ritasi['per_tahap'][$t->id]['m3'] ?? 0;
                $r = $ritasi['per_tahap'][$t->id] ?? [];

                return (object) [
                    't' => $t, 'ada' => $t->anggaran->isNotEmpty(), 'hpp' => $h['hpp'], 'omset' => $h['omset'], 'realisasi' => $realisasi,
                    'fisik' => $volTanah > 0 ? $m3 / $volTanah * 100 : null, 'm3' => $m3, 'volTanah' => $volTanah,
                    'serapan' => $h['hpp'] > 0 ? $realisasi / $h['hpp'] * 100 : null,
                    'terakhir' => max($r['selesai'] ?? '', $bbm['per_tahap'][$t->id]['selesai'] ?? '', $kas['per_tahap'][$t->id]['selesai'] ?? '') ?: null,
                    'rincian' => Anggaran::realisasiKomponen($t, $t->anggaran, $ritasi, $bbm, $oh),
                ];
            })
            ->sortBy(fn ($x) => [$x->t->proyek->urutan, (int) preg_replace('/\D/', '', $x->t->nama) ?: 99999, $x->t->nama])->values();

        return view('dashboard.proyek', ['baris' => $baris]);
    }
}
