<?php

namespace App\Http\Controllers;

use App\Models\GoogleIntegrasi;
use App\Models\KasBulan;
use App\Models\KasFoto;
use App\Support\GoogleSheets;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'emailGoogle' => GoogleSheets::terhubung()?->email(),
            'emailDriveFoto' => GoogleIntegrasi::foto()?->email,
            'fotoStatus' => KasFoto::selectRaw('status_drive, COUNT(*) as n')->groupBy('status_drive')->pluck('n', 'status_drive'),
            'bulanTerakhir' => KasBulan::orderByDesc('bulan')->first(),
            'jumlahBulan' => KasBulan::count(),
            'bulanBercatatan' => KasBulan::where('arsip', false)->get()->reject->cocok()->pluck('lembar'),
            // Kesehatan server (sisa disk, foto tertahan) hanya untuk Super Admin.
            'server' => auth()->user()?->isSuperAdmin() ? \App\Support\CekServer::periksa() : null,
            // Pengingat perpanjang langganan Tuya (Kendali Rumah) — hanya akun owner, mulai 30 hari sebelum habis.
            'masaTuya' => auth()->user()?->bolehRumah() && \App\Support\Tuya::siap() ? \App\Support\Rumah::masaBerlaku() : null,
            // Gaji Kas Harian yang belum jelas tahap proyeknya (owner 9 Nov 2026) — untuk akun yang boleh Pilah Transaksi.
            'gajiTanpaTahap' => \App\Support\MenuAkses::boleh(auth()->user(), 'pilah-transaksi') ? \App\Support\GajiTanpaTahap::daftar() : collect(),
        ]);
    }
}
