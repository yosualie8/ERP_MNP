<?php

namespace App\Http\Controllers;

use App\Models\MutasiPt;
use App\Support\ImporMutasiPt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Mutasi Rekening PT (BCA): hanya dilihat — per bulan, atau cari keterangan di semua bulan. Data dari file Drive (mnp:impor-mutasi-pt). */
class MutasiPtController extends Controller
{
    public function index(Request $request): View
    {
        $bulanAda = MutasiPt::selectRaw("DATE_FORMAT(tanggal, '%Y-%m') ym, count(*) n")->groupBy('ym')->orderByDesc('ym')->pluck('n', 'ym');
        $q = trim((string) $request->query('q'));
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? $request->query('bulan') : $bulanAda->keys()->first();
        $baris = MutasiPt::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => collect(preg_split('/\s+/', $q))->each(fn ($kata) => $x->where('keterangan', 'like', '%'.$kata.'%'))))
            ->when($q === '' && $bulan, fn ($w) => $w->whereRaw("DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulan]))
            ->orderBy('tanggal')->orderBy('baris')->limit(3000)->get();

        return view('mutasi-pt.index', [
            'baris' => $baris, 'bulan' => $bulan, 'bulanAda' => $bulanAda, 'q' => $q,
            'impor' => Cache::get(ImporMutasiPt::KUNCI),
            'saldoAkhir' => MutasiPt::orderByDesc('tanggal')->orderByDesc('baris')->first(),
        ]);
    }

    public function perbarui(): RedirectResponse
    {
        try {
            $h = ImporMutasiPt::jalankan(true);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal memperbarui dari Drive: '.$e->getMessage());
        }

        return back()->with('success', "Mutasi Rekening PT diperbarui dari Drive: {$h['baris']} baris.");
    }
}
