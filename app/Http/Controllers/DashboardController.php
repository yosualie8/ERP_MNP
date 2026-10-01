<?php

namespace App\Http\Controllers;

use App\Support\GoogleSheets;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $sheets = GoogleSheets::terhubung();
        $daftar = [];
        $galat = null;

        if ($sheets) {
            try {
                $daftar = $sheets->daftarSpreadsheet(15);
            } catch (\Throwable $e) {
                $galat = $e->getMessage();
            }
        }

        return view('dashboard', [
            'emailGoogle' => $sheets?->email(),
            'daftar' => $daftar,
            'galat' => $galat,
        ]);
    }
}
