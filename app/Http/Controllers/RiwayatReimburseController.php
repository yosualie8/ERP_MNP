<?php

namespace App\Http\Controllers;

use App\Support\RiwayatReimburse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** History Reimburse: rekap transaksi Kas Harian yang sudah direimburse per tanggal reimburse; klik untuk melihat rinciannya. */
class RiwayatReimburseController extends Controller
{
    public function index(Request $request): View
    {
        $daftarBulan = RiwayatReimburse::bulan();
        $cari = trim((string) $request->query('q', ''));
        $bulan = (string) $request->query('bulan', '');
        if (! $daftarBulan->has($bulan)) {
            $bulan = (string) $daftarBulan->keys()->first();
        }

        return view('kas.riwayat-reimburse', [
            'daftarBulan' => $daftarBulan,
            'bulan' => $bulan,
            'cari' => $cari,
            'grup' => RiwayatReimburse::grup($bulan ?: null, $cari),
        ]);
    }
}
