<?php

namespace App\Http\Controllers;

use App\Support\BiayaDt;
use App\Support\LabaRugiDt;
use App\Support\NomorMobil;
use App\Support\Periode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Dashboard → Performa DT (owner 30 Okt 2026): laba rugi per dump truck = Harga Jual rit (lembar Ritasi) − biaya langsung truk
 * (Monitoring Biaya DT). Per DT, per bulan, dan rincian satu truk. Lihat App\Support\LabaRugiDt.
 */
class DashboardPerformaDtController extends Controller
{
    public function __invoke(Request $request): View
    {
        $p = Periode::dari($request, LabaRugiDt::bulanAda());
        // Bawaan sama dengan Monitoring Biaya DT: 6 bulan terakhir dalam tahun yang sama.
        if (! $request->filled('dari') && ! $request->filled('lembar') && ! $request->filled('bulan')) {
            $p = new Periode($p->tahun, $request->filled('tahun') ? 1 : max(1, $p->sampai - 5), $p->sampai, $p->daftarTahun, $p->bulanAda);
        }
        $bulan = collect(range($p->dari, $p->sampai))->map(fn ($m) => Carbon::create($p->tahun, $m, 1)->format('Y-m'))->all();

        $truk = LabaRugiDt::perTruk($p->awal(), $p->akhir());
        // Truk tidak aktif tanpa rit & tanpa biaya di periode ini tidak perlu tampil (sudah tersaring: hanya yang punya data).
        $truk = $truk->sortByDesc('laba')->values();

        $perBulan = collect($bulan)->mapWithKeys(function ($b) use ($truk) {
            $x = $truk->map(fn ($t) => $t['bulan'][$b] ?? null)->filter();

            return [$b => ['rit' => (int) $x->sum('rit'), 'pendapatan' => (int) $x->sum('pendapatan'), 'biaya' => (int) $x->sum('biaya'), 'laba' => (int) $x->sum('laba')]];
        })->all();

        $pendapatan = (int) $truk->sum('pendapatan');
        $biaya = (int) $truk->sum('biaya');
        $rit = (int) $truk->sum('rit');
        $ringkas = [
            'pendapatan' => $pendapatan, 'biaya' => $biaya, 'laba' => $pendapatan - $biaya, 'rit' => $rit, 'kubik' => (float) $truk->sum('kubik'),
            'margin' => $pendapatan ? ($pendapatan - $biaya) / $pendapatan * 100 : null,
            'per_rit' => $rit ? ['pendapatan' => $pendapatan / $rit, 'biaya' => $biaya / $rit, 'laba' => ($pendapatan - $biaya) / $rit] : null,
            'untung' => $truk->where('laba', '>', 0)->count(), 'rugi' => $truk->where('laba', '<', 0)->count(),
            'kelompok' => collect([...array_keys(BiayaDt::KELOMPOK), 'Lain-lain'])->mapWithKeys(fn ($k) => [$k => (int) $truk->sum(fn ($t) => $t['kelompok'][$k] ?? 0)])->filter()->all(),
            'tanpa_harga' => (int) $truk->sum('tanpa_harga'), 'perkiraan' => (int) $truk->sum('perkiraan'),
        ];

        $mobil = NomorMobil::rapikan($request->query('mobil'));
        $rinci = $mobil ? $truk->firstWhere('mobil', $mobil) : null;

        return view('dashboard.performa-dt', compact('p', 'bulan', 'truk', 'perBulan', 'ringkas', 'rinci') + ['periode' => $p]);
    }
}
