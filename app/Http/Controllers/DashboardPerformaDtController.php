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
 * (Monitoring Biaya DT + transfer BCA ber-DT). Grid master per DT; baris dibuka → rincian (pendapatan per proyek, pengeluaran per
 * kategori, semua transaksi biaya) dimuat lewat rinci(); nomor DT → halaman rincian satu truk (truk()). Lihat App\Support\LabaRugiDt.
 */
class DashboardPerformaDtController extends Controller
{
    /** Periode terpilih; bawaan sama dengan Monitoring Biaya DT: 6 bulan terakhir dalam tahun yang sama. */
    private function periode(Request $request): Periode
    {
        $p = Periode::dari($request, LabaRugiDt::bulanAda());
        if (! $request->filled('dari') && ! $request->filled('lembar') && ! $request->filled('bulan')) {
            $p = new Periode($p->tahun, $request->filled('tahun') ? 1 : max(1, $p->sampai - 5), $p->sampai, $p->daftarTahun, $p->bulanAda);
        }

        return $p;
    }

    /** @return string[] bulan Y-m dalam periode */
    private function bulan(Periode $p): array
    {
        return collect(range($p->dari, $p->sampai))->map(fn ($m) => Carbon::create($p->tahun, $m, 1)->format('Y-m'))->all();
    }

    public function __invoke(Request $request): View
    {
        $p = $this->periode($request);
        $bulan = $this->bulan($p);

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

        return view('dashboard.performa-dt', compact('bulan', 'truk', 'perBulan', 'ringkas') + ['periode' => $p]);
    }

    /** Rincian satu DT (potongan HTML untuk baris yang dibuka): ?mobil=DT 026 + periode. */
    public function rinci(Request $request): View
    {
        $p = $this->periode($request);
        $mobil = NomorMobil::rapikan($request->query('mobil'));
        $t = $mobil ? LabaRugiDt::perTruk($p->awal(), $p->akhir())->get($mobil) : null;
        abort_unless($t, 404);

        return view('dashboard.performa-dt-rinci', ['t' => $t] + LabaRugiDt::rinci($mobil, $p->awal(), $p->akhir()));
    }

    /** Halaman rincian satu DT (dibuka dari nomor DT, tab baru): ringkasan, laba rugi per bulan, detail 1 & 2 terbuka. */
    public function truk(Request $request): View
    {
        $p = $this->periode($request);
        $mobil = NomorMobil::rapikan($request->query('mobil'));
        abort_unless($mobil && isset(BiayaDt::aset()[$mobil]), 404);
        $t = LabaRugiDt::perTruk($p->awal(), $p->akhir())->get($mobil) ?? ['mobil' => $mobil, 'aset' => BiayaDt::aset()[$mobil], 'rit' => 0, 'kubik' => 0.0,
            'pendapatan' => 0, 'tanpa_harga' => 0, 'perkiraan' => 0, 'biaya' => 0, 'kelompok' => [], 'kategori' => [], 'laba' => 0, 'bulan' => []];

        return view('dashboard.performa-dt-truk', ['t' => $t, 'periode' => $p, 'bulan' => $this->bulan($p)] + LabaRugiDt::rinci($mobil, $p->awal(), $p->akhir()));
    }
}
