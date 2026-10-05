<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KasController;
use App\Http\Controllers\KasFotoController;
use App\Http\Controllers\KasInputController;
use App\Http\Controllers\PenggunaController;
use Illuminate\Support\Facades\Route;

// Halaman publik (tanpa login): dipakai sebagai Homepage, Privacy policy & Terms of service URL di Google Cloud.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('publik.beranda'))->name('beranda');
Route::view('/kebijakan-privasi', 'publik.privasi')->name('privasi');
Route::view('/syarat-layanan', 'publik.syarat')->name('syarat');

Route::middleware('guest')->group(function () {
    Route::get('/login', [GoogleController::class, 'show'])->name('login');
    Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
});
// Di luar grup guest: juga dipakai super admin yang sudah login untuk menghubungkan Google Sheets.
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

Route::middleware('auth')->group(function () {
    Route::get('/beranda', DashboardController::class)->name('dashboard');
    Route::get('/kas', [KasController::class, 'index'])->name('kas.index');
    Route::get('/kas/input', [KasInputController::class, 'create'])->name('kas.input');
    Route::post('/kas/input', [KasInputController::class, 'store'])->name('kas.input.store');
    Route::delete('/kas/transfer/{transfer}', [KasInputController::class, 'hapus'])->name('kas.hapus');
    Route::get('/kas/bon/{noId}', [KasFotoController::class, 'index'])->whereNumber('noId')->name('kas.bon');
    Route::post('/kas/bon/{noId}', [KasFotoController::class, 'store'])->whereNumber('noId')->name('kas.bon.store');
    Route::get('/kas/foto/{foto}', [KasFotoController::class, 'tampil'])->name('kas.foto');
    Route::delete('/kas/foto/{foto}', [KasFotoController::class, 'destroy'])->name('kas.foto.destroy');
    Route::post('/kas/sinkron', [KasInputController::class, 'sinkron'])->name('kas.sinkron');
    Route::get('/kas/rekap', [KasController::class, 'rekap'])->name('kas.rekap');
    Route::get('/kas/kode-gl', [KasController::class, 'kodeGl'])->name('kas.kode-gl');
    Route::post('/logout', [GoogleController::class, 'logout'])->name('logout');

    // Khusus super admin.
    Route::middleware('can:super-admin')->group(function () {
        Route::get('/auth/google/hubungkan-sheets', [GoogleController::class, 'hubungkanSheets'])->name('google.hubungkan-sheets');
        Route::get('/auth/google/hubungkan-drive-foto', [GoogleController::class, 'hubungkanDriveFoto'])->name('google.hubungkan-drive-foto');
        Route::get('/pengguna', [PenggunaController::class, 'index'])->name('pengguna.index');
        Route::post('/pengguna', [PenggunaController::class, 'store'])->name('pengguna.store');
        Route::patch('/pengguna/{user}', [PenggunaController::class, 'update'])->name('pengguna.update');
        Route::delete('/pengguna/{user}', [PenggunaController::class, 'destroy'])->name('pengguna.destroy');
    });
});
