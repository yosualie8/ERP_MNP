<?php

namespace App\Http\Controllers;

use App\Models\AsetMeter;
use App\Models\AsetTruk;
use App\Support\MeterAset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Dashboard HM & KM (owner 16 Nov 2026): HM alat berat, KM dump truck, dan HM / KM kendaraan & aset lain di Data Aset —
 * angka terakhir, kapan dicatat, pemakaian per bulan & rata-rata per hari. HM alat berat otomatis dari Data BBM; KM / HM lain dari
 * tabel aset_meter. Halaman ini HANYA untuk melihat (owner 16 Nov 2026: tidak ada tombol catat di dashboard).
 */
class DashboardHmKmController extends Controller
{
    public function index(Request $request): View
    {
        $kelompok = array_key_exists($request->query('kelompok'), MeterAset::KELOMPOK) ? $request->query('kelompok') : 'alat';
        $semuaStatus = $request->boolean('semua');
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? $request->query('bulan') : now()->format('Y-m');
        $awal = Carbon::parse($bulan.'-01');
        $lalu = $awal->copy()->subMonth();

        $semua = AsetTruk::orderBy('no_lambung')->get();
        $aset = $semua->whereIn('kategori', MeterAset::KELOMPOK[$kelompok][1])
            ->when(! $semuaStatus, fn ($c) => $c->whereIn('status', ['aktif', 'perbaikan']))
            ->sortBy(fn ($a) => [array_search($a->kategori, MeterAset::KELOMPOK[$kelompok][1], true), $a->no_lambung])->values();
        $bacaan = MeterAset::bacaan($aset);
        $hariIni = now()->toDateString();

        $baris = $aset->map(function (AsetTruk $a) use ($bacaan, $awal, $lalu, $hariIni) {
            $satuan = AsetMeter::satuan($a->kategori);
            $langkah = MeterAset::langkah($bacaan[$a->id], $satuan);
            $akhir = $langkah->reject(fn ($b) => $b->janggal)->last();

            return (object) [
                'aset' => $a, 'satuan' => $satuan, 'akhir' => $akhir, 'n' => $langkah->count(),
                'umur' => $akhir ? Carbon::parse($akhir->tanggal)->diffInDays(Carbon::parse($hariIni)) : null,
                'ini' => MeterAset::pakai($langkah, $awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()),
                'lalu' => MeterAset::pakai($langkah, $lalu->toDateString(), $lalu->copy()->endOfMonth()->toDateString()),
                'janggal' => $langkah->filter(fn ($b) => $b->janggal)->count(),
            ];
        });

        $jumlahPer = collect(MeterAset::KELOMPOK)->map(fn ($k) => $semua->whereIn('kategori', $k[1])
            ->when(! $semuaStatus, fn ($c) => $c->whereIn('status', ['aktif', 'perbaikan']))->count());

        return view('dashboard.hm-km', [
            'kelompok' => $kelompok, 'semuaStatus' => $semuaStatus, 'bulan' => $bulan, 'awal' => $awal, 'lalu' => $lalu,
            'baris' => $baris, 'jumlahPer' => $jumlahPer,
            'daftarBulan' => collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->all(),
        ]);
    }

    /** Riwayat bacaan satu aset (dialog). */
    public function riwayat(Request $request): JsonResponse
    {
        $a = AsetTruk::findOrFail((int) $request->query('aset'));
        $satuan = AsetMeter::satuan($a->kategori);
        $langkah = MeterAset::langkah(MeterAset::bacaan(collect([$a]))[$a->id], $satuan);

        return response()->json([
            'aset' => $a->no_lambung, 'kategori' => $a->labelKategori(), 'jenis' => $a->jenis, 'plat' => $a->plat, 'satuan' => $satuan,
            'bacaan' => $langkah->reverse()->values()->map(fn ($b) => [
                'tanggal' => $b->tanggal, 'tgl' => Carbon::parse($b->tanggal)->translatedFormat('D, j M Y'), 'nilai' => $b->nilai, 'selisih' => $b->selisih,
                'hari' => $b->hari, 'janggal' => $b->janggal, 'reset' => $b->reset, 'reset_otomatis' => $b->reset_otomatis, 'sumber' => $b->sumber, 'catatan' => $b->catatan, 'oleh' => $b->oleh,
            ]),
        ]);
    }
}
