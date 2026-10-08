<?php

use App\Http\Controllers\AsetController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KasController;
use App\Http\Controllers\KasFotoController;
use App\Http\Controllers\KasInputController;
use App\Http\Controllers\PengajuanUjController;
use App\Http\Controllers\PenggunaController;
use App\Http\Controllers\RapikanUjController;
use App\Http\Controllers\ReimburseKasController;
use App\Http\Controllers\ReimburseUjController;
use App\Http\Controllers\RitasiController;
use App\Http\Controllers\UjController;
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

// "menu": tiap halaman/aksi hanya untuk akun yang diberi menunya (App\Support\MenuAkses, diatur di halaman Pengguna).
Route::middleware(['auth', 'menu'])->group(function () {
    Route::get('/beranda', DashboardController::class)->name('dashboard');
    Route::get('/kas', [KasController::class, 'index'])->name('kas.index');
    Route::get('/kas/input', [KasInputController::class, 'create'])->name('kas.input');
    Route::post('/kas/input', [KasInputController::class, 'store'])->name('kas.input.store');
    Route::get('/kas/input/reimburse-uj', [KasInputController::class, 'reimburseUj'])->name('kas.input.reimburse-uj');
    Route::get('/kas/input/reimburse-uj/{tanggal}', [KasInputController::class, 'reimburseUjIsi'])->name('kas.input.reimburse-uj.isi');
    Route::delete('/kas/transfer/{transfer}', [KasInputController::class, 'hapus'])->name('kas.hapus');
    Route::get('/kas/transaksi/{noId}/edit', [KasInputController::class, 'edit'])->whereNumber('noId')->name('kas.edit');
    Route::put('/kas/transaksi/{noId}', [KasInputController::class, 'update'])->whereNumber('noId')->name('kas.update');
    Route::get('/kas/bon/{noId}', [KasFotoController::class, 'index'])->whereNumber('noId')->name('kas.bon');
    Route::post('/kas/bon/{noId}', [KasFotoController::class, 'store'])->whereNumber('noId')->name('kas.bon.store');
    Route::get('/kas/foto/{foto}', [KasFotoController::class, 'tampil'])->name('kas.foto');
    Route::delete('/kas/foto/{foto}', [KasFotoController::class, 'destroy'])->name('kas.foto.destroy');
    Route::post('/kas/sinkron', [KasInputController::class, 'sinkron'])->name('kas.sinkron');
    Route::get('/kas/rekap', [KasController::class, 'rekap'])->name('kas.rekap');
    Route::get('/uj/rapikan', [RapikanUjController::class, 'index'])->name('uj.rapikan');
    Route::post('/uj/rapikan', [RapikanUjController::class, 'simpan'])->name('uj.rapikan.simpan');
    Route::get('/aset', [AsetController::class, 'index'])->name('aset.index');
    Route::get('/aset/tambah', [AsetController::class, 'create'])->name('aset.create');
    Route::post('/aset', [AsetController::class, 'store'])->name('aset.store');
    Route::get('/aset/{aset}/edit', [AsetController::class, 'edit'])->whereNumber('aset')->name('aset.edit');
    Route::put('/aset/{aset}', [AsetController::class, 'update'])->whereNumber('aset')->name('aset.update');
    Route::get('/kas/validasi-reimburse', [ReimburseKasController::class, 'validasi'])->name('kas.validasi-reimburse');
    Route::post('/kas/validasi-reimburse', [ReimburseKasController::class, 'periksaValidasi'])->name('kas.validasi-reimburse.periksa');
    Route::get('/kas/belum-reimburse.xlsx', [ReimburseKasController::class, 'unduhBelum'])->name('kas.belum-reimburse');
    Route::get('/kas/kode-gl', [KasController::class, 'kodeGl'])->name('kas.kode-gl');
    // Kas uang jalan dump truck (lembar "Kas Seabank").
    Route::get('/uj', [UjController::class, 'index'])->name('uj.index');
    Route::get('/uj/input', [UjController::class, 'create'])->name('uj.input');
    Route::post('/uj/input', [UjController::class, 'store'])->name('uj.store');
    Route::post('/uj/periksa', [UjController::class, 'periksa'])->name('uj.periksa');
    Route::get('/uj/{noUj}/edit', [UjController::class, 'edit'])->whereNumber('noUj')->name('uj.edit');
    Route::put('/uj/{noUj}', [UjController::class, 'update'])->whereNumber('noUj')->name('uj.update');
    Route::delete('/uj/{noUj}', [UjController::class, 'hapus'])->whereNumber('noUj')->name('uj.hapus');
    Route::post('/uj/sinkron', [UjController::class, 'sinkron'])->name('uj.sinkron');
    Route::get('/uj/input/pengajuan', [PengajuanUjController::class, 'terbuka'])->name('uj.pengajuan-terbuka');
    // Pengajuan uang jalan (disimpan di aplikasi, direalisasikan lewat Input UJ).
    Route::get('/uj/pengajuan', [PengajuanUjController::class, 'daftar'])->name('pengajuan-uj.daftar');
    Route::get('/uj/pengajuan/baru', [PengajuanUjController::class, 'buat'])->name('pengajuan-uj.buat');
    Route::post('/uj/pengajuan', [PengajuanUjController::class, 'simpan'])->name('pengajuan-uj.simpan');
    Route::post('/uj/pengajuan/periksa', [PengajuanUjController::class, 'periksa'])->name('pengajuan-uj.periksa');
    Route::get('/uj/pengajuan/{pengajuan}/edit', [PengajuanUjController::class, 'ubah'])->whereNumber('pengajuan')->name('pengajuan-uj.ubah');
    Route::put('/uj/pengajuan/{pengajuan}', [PengajuanUjController::class, 'simpanUbah'])->whereNumber('pengajuan')->name('pengajuan-uj.simpan-ubah');
    Route::post('/uj/pengajuan/{pengajuan}/batal', [PengajuanUjController::class, 'batal'])->whereNumber('pengajuan')->name('pengajuan-uj.batal');
    // Ritasi dump truck (lembar "Ritasi").
    Route::get('/ritasi', [RitasiController::class, 'index'])->name('ritasi.index');
    Route::get('/ritasi/input', [RitasiController::class, 'create'])->name('ritasi.input');
    Route::post('/ritasi/input', [RitasiController::class, 'store'])->name('ritasi.store');
    Route::post('/ritasi/periksa', [RitasiController::class, 'periksa'])->name('ritasi.periksa');
    Route::get('/ritasi/{baris}/edit', [RitasiController::class, 'edit'])->whereNumber('baris')->name('ritasi.edit');
    Route::put('/ritasi/{baris}', [RitasiController::class, 'update'])->whereNumber('baris')->name('ritasi.update');
    Route::delete('/ritasi/{baris}', [RitasiController::class, 'hapus'])->whereNumber('baris')->name('ritasi.hapus');
    Route::post('/ritasi/sinkron', [RitasiController::class, 'sinkron'])->name('ritasi.sinkron');
    Route::get('/uj/reimburse', [ReimburseUjController::class, 'index'])->name('reimburse.index');
    Route::post('/uj/reimburse', [ReimburseUjController::class, 'simpan'])->name('reimburse.simpan');
    Route::get('/uj/reimburse/{reimburse}/excel', [ReimburseUjController::class, 'unduh'])->name('reimburse.unduh');
    Route::post('/logout', [GoogleController::class, 'logout'])->name('logout');

    // Khusus super admin.
    Route::middleware('can:super-admin')->group(function () {
        Route::get('/auth/google/hubungkan-sheets', [GoogleController::class, 'hubungkanSheets'])->name('google.hubungkan-sheets');
        Route::get('/auth/google/hubungkan-drive-foto', [GoogleController::class, 'hubungkanDriveFoto'])->name('google.hubungkan-drive-foto');
        Route::get('/pengguna', [PenggunaController::class, 'index'])->name('pengguna.index');
        Route::post('/pengguna', [PenggunaController::class, 'store'])->name('pengguna.store');
        Route::patch('/pengguna/{user}', [PenggunaController::class, 'update'])->name('pengguna.update');
        Route::patch('/pengguna/{user}/menu', [PenggunaController::class, 'menu'])->name('pengguna.menu');
        Route::delete('/pengguna/{user}', [PenggunaController::class, 'destroy'])->name('pengguna.destroy');
    });
});
