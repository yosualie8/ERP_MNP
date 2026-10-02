<?php

namespace App\Http\Controllers;

use App\Models\KasBulan;
use App\Support\GoogleSheets;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'emailGoogle' => GoogleSheets::terhubung()?->email(),
            'bulanTerakhir' => KasBulan::orderByDesc('bulan')->first(),
            'jumlahBulan' => KasBulan::count(),
            'bulanBercatatan' => KasBulan::get()->reject->cocok()->pluck('lembar'),
        ]);
    }
}
