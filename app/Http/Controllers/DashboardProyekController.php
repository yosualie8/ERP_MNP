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
        $rincianOh = Overhead::rincianTeralokasi();
        $tahap = ProyekTahap::with(['proyek', 'anggaran.komponen'])->where('status', 'progress')->whereNull('pos_overhead')->where('tipe', '!=', 'biaya')->get();
        // Transaksi Kas Harian yang jelas untuk tahap ini (alokasi / Kode GL tahap), tanpa akun yang diabaikan (HPP ritasi, BBM, sparepart, DT).
        $trx = Anggaran::transaksiKas($tahap->pluck('id')->all());
        $baris = $tahap
            ->map(function ($t) use ($ritasi, $kas, $bbm, $overhead, $rincianOh, $trx) {
                $oh = $overhead[$t->id] ?? 0;
                if ($t->tipe === 'ritasi') {
                    // tipe ritasi: omset & biaya realisasi otomatis, tanpa progres anggaran
                    $rp = \App\Support\RitasiProyek::hitung($t, $bbm, $oh);
                    $r = $ritasi['per_tahap'][$t->id] ?? [];

                    return (object) ['t' => $t, 'ada' => true, 'hpp' => 0, 'omset' => $rp['omset'], 'realisasi' => $rp['total'], 'fisik' => null, 'fisikKet' => null, 'boq' => null,
                        'serapan' => null, 'ritasi' => $rp, 'awal' => $rp['mulai'], 'terakhir' => $rp['selesai'],
                        'rincian' => collect($rp['biaya'])->map(fn ($b) => ['nama' => $b[0], 'satuan' => $b[3], 'v_rencana' => null, 'rp_rencana' => 0.0, 'tunda' => false,
                            'v_real' => $b[1], 'rp_real' => $b[2], 'catatan' => null])->values()->all(),
                        'kas' => $trx[$t->id] ?? collect(), 'overheadBulan' => $rincianOh[$t->id] ?? []];
                }
                $h = Anggaran::hitung($t->anggaran, $t);
                $realisasi = array_sum(array_column(Anggaran::rincianRealisasi($t, $ritasi, $kas, $bbm), 1)) + $oh;
                $pf = Anggaran::progresFisik($t, $t->anggaran, $ritasi);
                $r = $ritasi['per_tahap'][$t->id] ?? [];

                return (object) [
                    't' => $t, 'ada' => $t->anggaran->isNotEmpty() || $h['boq'], 'hpp' => $h['hpp'], 'omset' => $h['omset'], 'realisasi' => $realisasi,
                    'fisik' => $pf['persen'] ?? null, 'fisikKet' => $pf['ket'] ?? null, 'boq' => $pf['boq'] ?? ($h['boq'] ? \App\Support\ProgresBoq::hitung($t) : null),
                    'serapan' => $h['hpp'] > 0 ? $realisasi / $h['hpp'] * 100 : null,
                    // Aktivitas awal/terakhir = ritasi, BBM, atau Kas Harian pertama/terakhir untuk tahap ini.
                    'awal' => collect([$r['mulai'] ?? null, $bbm['per_tahap'][$t->id]['mulai'] ?? null, $kas['per_tahap'][$t->id]['mulai'] ?? null])->filter()->min(),
                    'terakhir' => max($r['selesai'] ?? '', $bbm['per_tahap'][$t->id]['selesai'] ?? '', $kas['per_tahap'][$t->id]['selesai'] ?? '') ?: null,
                    'rincian' => Anggaran::realisasiKomponen($t, $t->anggaran, $ritasi, $bbm, $oh),
                    'kas' => $trx[$t->id] ?? collect(),
                    'overheadBulan' => $rincianOh[$t->id] ?? [],
                ];
            })
            ->sortBy(fn ($x) => [$x->t->proyek->urutan, (int) preg_replace('/\D/', '', $x->t->nama) ?: 99999, $x->t->nama])->values();

        return view('dashboard.proyek', ['baris' => $baris]);
    }
}
