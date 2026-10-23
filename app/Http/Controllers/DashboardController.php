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
        ]);
    }
}
