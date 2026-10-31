<?php

namespace App\Http\Controllers;

use App\Support\PotonganMuatan;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Potongan Muatan (owner 31 Okt 2026): rekap harian rit Kendal/Jogja ber-LS yang volumenya di bawah batas standar jenis truk,
 * potongan per sopir, dan pencatatan ke buku Hutang Supir (dipotong dari uang jalan besoknya). Hitungan di App\Support\PotonganMuatan.
 */
class PotonganMuatanController extends Controller
{
    public function index(Request $request): View
    {
        $sampai = $this->tanggal($request->query('sampai')) ?? today();
        $dari = $this->tanggal($request->query('dari')) ?? $sampai->copy()->subDays(13);
        if ($dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $aturan = PotonganMuatan::aturan();
        $rit = PotonganMuatan::rit($dari, $sampai, $aturan);
        $harian = PotonganMuatan::harian($dari, $sampai, $aturan);
        $perHari = $harian->groupBy(fn ($h) => $h->tanggal->toDateString())->map(fn ($g) => (object) [
            'tanggal' => $g->first()->tanggal, 'rit' => $g->sum('rit'), 'rit_kurang' => $g->sum('rit_kurang'), 'sopir' => $g->where('potongan', '>', 0)->count(),
            'potongan' => $g->sum('potongan'), 'boleh' => $g->first()->boleh, 'tercatat' => $g->where('potongan', '>', 0)->every(fn ($h) => $h->catat && ! $h->beda),
            'beda' => $g->contains(fn ($h) => $h->beda || (! $h->potongan && $h->catat)),
        ])->sortKeysDesc();

        return view('ritasi.potongan-muatan', [
            'dari' => $dari, 'sampai' => $sampai, 'aturan' => $aturan, 'perHari' => $perHari, 'harian' => $harian->where('potongan', '>', 0)->sortByDesc(fn ($h) => $h->tanggal->format('Ymd'))->values(),
            'ringkas' => ['rit' => $rit->count(), 'kurang' => $rit->where('kurang', true)->count(), 'potongan' => $rit->sum('potongan'),
                'tanpa_standar' => $rit->whereNull('standar')->count(), 'rata' => $rit->groupBy('jenis')->map(fn ($g) => round($g->avg('volume'), 2))],
            'bolehAtur' => $request->user()->isSuperAdmin(),
        ]);
    }

    /** Ubah standar / toleransi / tarif / tanggal mulai (Super Admin). */
    public function aturan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $v = $request->validate([
            'standar' => ['required', 'array'], 'standar.*' => ['required', 'numeric', 'min:1', 'max:60'],
            'toleransi' => ['required', 'numeric', 'min:0', 'max:10'], 'tarif' => ['required', 'integer', 'min:0', 'max:1000000'], 'mulai' => ['nullable', 'date'],
        ]);
        PotonganMuatan::simpanAturan([
            'standar' => array_map('floatval', $v['standar']), 'toleransi' => (float) $v['toleransi'], 'tarif' => (int) $v['tarif'],
            'mulai' => ! empty($v['mulai']) ? Carbon::parse($v['mulai'])->toDateString() : null,
        ], $request->user()->id);

        return back()->with('success', 'Aturan potongan muatan disimpan.');
    }

    /** Catat potongan satu hari bongkar ke Hutang Supir. */
    public function catat(Request $request): RedirectResponse
    {
        $v = $request->validate(['tanggal' => ['required', 'date', 'before_or_equal:today']]);
        $hari = Carbon::parse($v['tanggal'])->startOfDay();
        try {
            $n = PotonganMuatan::catat($hari, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Potongan muatan bongkar '.$hari->translatedFormat('j M Y').' dicatat ke Hutang Supir (tanggal '.$hari->copy()->addDay()->translatedFormat('j M')
            ."): {$n['baru']} baru, {$n['ubah']} diperbarui".($n['hapus'] ? ", {$n['hapus']} dihapus" : '').', total Rp '.rp($n['total']).'.');
    }

    private function tanggal(?string $v): ?Carbon
    {
        return $v ? RitasiController::tanggal($v)?->copy()->startOfDay() : null;
    }
}
