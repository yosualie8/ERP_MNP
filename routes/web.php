<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KasController;
use Illuminate\Support\Facades\Route;

// Halaman publik (tanpa login): dipakai sebagai Homepage, Privacy policy & Terms of service URL di Google Cloud.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('publik.beranda'))->name('beranda');
Route::view('/kebijakan-privasi', 'publik.privasi')->name('privasi');
Route::view('/syarat-layanan', 'publik.syarat')->name('syarat');

Route::middleware('guest')->group(function () {
    Route::get('/login', [GoogleController::class, 'show'])->name('login');
    Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware('auth')->group(function () {
    Route::get('/beranda', DashboardController::class)->name('dashboard');
    Route::get('/kas', [KasController::class, 'index'])->name('kas.index');
    Route::get('/kas/rekap', [KasController::class, 'rekap'])->name('kas.rekap');
    Route::get('/kas/kode-gl', [KasController::class, 'kodeGl'])->name('kas.kode-gl');
    Route::post('/logout', [GoogleController::class, 'logout'])->name('logout');
});
