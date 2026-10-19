<?php

namespace App\Http\Controllers;

use App\Support\BiayaDt;
use App\Support\NomorMobil;
use App\Support\Periode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Dashboard → Monitoring Biaya DT (owner 19 Okt 2026): biaya dump truck per bulan & per kategori (Kas UJ + Kas Harian
 * ber-No Mobil), per truk, jumlah rit & biaya per rit, serta rincian transaksi satu truk. Lihat App\Support\BiayaDt.
 */
class DashboardBiayaDtController extends Controller
{
    public function __invoke(Request $request): View
    {
        $p = Periode::dari($request, BiayaDt::bulanAda());
        // Bawaan: 6 bulan terakhir (dalam tahun yang sama); tahun dipilih tanpa bulan → Januari s.d. bulan terakhir.
        if (! $request->filled('dari') && ! $request->filled('lembar') && ! $request->filled('bulan')) {
            $p = new Periode($p->tahun, $request->filled('tahun') ? 1 : max(1, $p->sampai - 5), $p->sampai, $p->daftarTahun, $p->bulanAda);
        }
        $awal = $p->awal();
        $akhir = $p->akhir();
        $bulan = collect(range($p->dari, $p->sampai))->map(fn ($m) => Carbon::create($p->tahun, $m, 1)->format('Y-m'))->all();

        $baris = BiayaDt::baris($awal, $akhir);
        $rit = BiayaDt::rit($awal, $akhir);
        $aset = BiayaDt::aset();

        // Kategori: urut kelompok (Perjalanan, Perawatan, Material, Lain-lain), lalu nilai terbesar.
        $urutKelompok = array_flip([...array_keys(BiayaDt::KELOMPOK), 'Lain-lain']);
        $kategori = $baris->groupBy('kategori')->map(fn ($g, $k) => [
            'nama' => $k, 'kelompok' => BiayaDt::kelompok($k), 'rp' => (int) $g->sum('rp'), 'n' => (int) $g->sum('n'),
            'sumber' => $g->pluck('sumber')->unique()->sort()->values()->all(),
            'bulan' => $g->groupBy('bulan')->map(fn ($x) => (int) $x->sum('rp'))->all(),
        ])->sort(fn ($a, $b) => [$urutKelompok[$a['kelompok']], $b['rp']] <=> [$urutKelompok[$b['kelompok']], $a['rp']])->values();

        // Saring kategori (?kat[]=… dari centang, atau ?kat=A|B dari tautan); kosong = semua. "?tanpa=material" = semua kecuali kelompok Material.
        $kat = $request->query('kat');
        $pilih = collect(is_array($kat) ? $kat : explode('|', (string) $kat))->filter(fn ($k) => is_string($k) && $kategori->contains('nama', $k))->values();
        if ($pilih->isEmpty() && $request->query('tanpa') === 'material') {
            $pilih = $kategori->reject(fn ($k) => $k['kelompok'] === 'Material')->pluck('nama')->values();
        }
        $semua = $pilih->isEmpty() || $pilih->count() === $kategori->count();
        $terpilih = $semua ? $baris : $baris->whereIn('kategori', $pilih->all());
        $katTampil = $semua ? $kategori : $kategori->whereIn('nama', $pilih->all())->values();

        // Per truk.
        $truk = $terpilih->groupBy('mobil')->map(function ($g, $m) use ($rit, $aset) {
            $nRit = array_sum($rit[$m] ?? []);
            $rp = (int) $g->sum('rp');

            return ['mobil' => $m, 'aset' => $aset[$m] ?? null, 'rp' => $rp, 'rit' => $nRit, 'per_rit' => $nRit ? (int) round($rp / $nRit) : null,
                'bulan' => $g->groupBy('bulan')->map(fn ($x) => (int) $x->sum('rp'))->all(),
                'kategori' => $g->groupBy('kategori')->map(fn ($x) => (int) $x->sum('rp'))->all()];
        });
        // Truk yang ada rit tetapi tanpa biaya di kategori terpilih tetap tampil (biaya 0).
        foreach ($rit as $m => $x) {
            if (! $truk->has($m)) {
                $truk[$m] = ['mobil' => $m, 'aset' => $aset[$m] ?? null, 'rp' => 0, 'rit' => array_sum($x), 'per_rit' => 0, 'bulan' => [], 'kategori' => []];
            }
        }
        $truk = $truk->sortByDesc('rp')->values();

        $total = (int) $terpilih->sum('rp');
        $totalRit = (int) $truk->sum('rit');
        $perBulan = collect($bulan)->mapWithKeys(fn ($b) => [$b => (int) $terpilih->where('bulan', $b)->sum('rp')])->all();
        $ritBulan = collect($bulan)->mapWithKeys(fn ($b) => [$b => (int) collect($rit)->sum(fn ($x) => $x[$b] ?? 0)])->all();
        $bulanBerisi = max(1, count(array_filter($perBulan)));
        $ringkas = [
            'total' => $total, 'rata_bulan' => (int) round($total / $bulanBerisi), 'truk' => $truk->where('rp', '>', 0)->count(),
            'rata_truk_bulan' => $truk->where('rp', '>', 0)->count() ? (int) round($total / $bulanBerisi / $truk->where('rp', '>', 0)->count()) : 0,
            'rit' => $totalRit, 'per_rit' => $totalRit ? (int) round($total / $totalRit) : null,
            'sumber' => ['uj' => (int) $terpilih->where('sumber', 'uj')->sum('rp'), 'kas' => (int) $terpilih->where('sumber', 'kas')->sum('rp')],
        ];

        // Rincian satu truk (?mobil=DT 027).
        $mobil = NomorMobil::rapikan($request->query('mobil'));
        $rinci = null;
        if ($mobil && isset($aset[$mobil])) {
            $milik = $baris->where('mobil', $mobil);
            $tx = BiayaDt::transaksi($mobil, $awal, $akhir);
            $rinci = [
                'mobil' => $mobil, 'aset' => $aset[$mobil], 'rit' => $rit[$mobil] ?? [],
                'kategori' => $milik->groupBy('kategori')->map(fn ($g) => ['rp' => (int) $g->sum('rp'), 'bulan' => $g->groupBy('bulan')->map(fn ($x) => (int) $x->sum('rp'))->all()])
                    ->sortByDesc('rp')->all(),
                'transaksi' => $semua ? $tx : $tx->whereIn('kategori', $pilih->all())->values(),
            ];
        }

        return view('dashboard.biaya-dt', [
            'periode' => $p, 'bulan' => $bulan, 'kategori' => $kategori, 'katTampil' => $katTampil, 'pilih' => $pilih, 'semua' => $semua,
            'truk' => $truk, 'perBulan' => $perBulan, 'ritBulan' => $ritBulan, 'ringkas' => $ringkas, 'rinci' => $rinci,
            'tampil' => $tampil = $request->query('tampil') === 'kategori' ? 'kategori' : 'bulan',
            // Parameter yang dibawa tautan di halaman (periode ditambah sendiri).
            'bawa' => array_filter(['kat' => $semua ? null : $pilih->implode('|'), 'tampil' => $tampil === 'kategori' ? 'kategori' : null, 'mobil' => $rinci ? $mobil : null]),
        ]);
    }
}
