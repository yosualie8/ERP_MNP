<?php

use App\Http\Controllers\AktivitasAdminController;
use App\Http\Controllers\AsetController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\BuanganTrukController;
use App\Http\Controllers\DaftarProyekController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardKasController;
use App\Http\Controllers\DashboardUjController;
use App\Http\Controllers\KasController;
use App\Http\Controllers\KasFotoController;
use App\Http\Controllers\KasInputController;
use App\Http\Controllers\OAuthMcpController;
use App\Http\Controllers\PengajuanUjController;
use App\Http\Controllers\PenggunaController;
use App\Http\Controllers\RapikanUjController;
use App\Http\Controllers\ReimburseKasController;
use App\Http\Controllers\ReimburseUjController;
use App\Http\Controllers\RiwayatReimburseController;
use App\Http\Controllers\RitasiController;
use App\Http\Controllers\TanyaAiController;
use App\Http\Controllers\UjController;
use Illuminate\Support\Facades\Route;

// Metadata OAuth untuk MCP server (ChatGPT).
Route::get('/.well-known/oauth-protected-resource/{jalur?}', [OAuthMcpController::class, 'metadataResource'])->where('jalur', '.*');
Route::get('/.well-known/oauth-authorization-server', [OAuthMcpController::class, 'metadataServer']);
Route::get('/.well-known/openid-configuration', [OAuthMcpController::class, 'metadataServer']);

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
    Route::get('/dashboard/kas', DashboardKasController::class)->name('dashboard.kas');
    Route::get('/dashboard/uj', DashboardUjController::class)->name('dashboard.uj');
    Route::get('/dashboard/proyek', \App\Http\Controllers\DashboardProyekController::class)->name('dashboard.proyek');
    Route::get('/dashboard/biaya-dt', \App\Http\Controllers\DashboardBiayaDtController::class)->name('dashboard.biaya-dt');
    Route::get('/dashboard/aktivitas', AktivitasAdminController::class)->name('dashboard.aktivitas')->middleware('can:super-admin');
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
    Route::get('/kas/rekap', [KasController::class, 'rekap'])->name('kas.rekap');
    Route::get('/uj/rapikan', [RapikanUjController::class, 'index'])->name('uj.rapikan');
    Route::post('/uj/rapikan', [RapikanUjController::class, 'simpan'])->name('uj.rapikan.simpan');
    // Daftar Proyek: proyek → tahap → alias (nama lama di Ritasi/BBM/Cost Project/Kas).
    Route::get('/proyek', [DaftarProyekController::class, 'index'])->name('proyek.index');
    Route::get('/proyek/pengaturan', [DaftarProyekController::class, 'pengaturan'])->name('pengaturan-proyek');
    Route::post('/proyek/pengaturan', [DaftarProyekController::class, 'simpanPengaturan'])->name('pengaturan-proyek.simpan');
    Route::get('/proyek/tahap/baru', [DaftarProyekController::class, 'create'])->name('proyek.create');
    Route::post('/proyek/tahap', [DaftarProyekController::class, 'store'])->name('proyek.store');
    Route::get('/proyek/tahap/{tahap}', [DaftarProyekController::class, 'show'])->whereNumber('tahap')->name('proyek.show');
    Route::get('/proyek/tahap/{tahap}/edit', [DaftarProyekController::class, 'edit'])->whereNumber('tahap')->name('proyek.edit');
    Route::put('/proyek/tahap/{tahap}', [DaftarProyekController::class, 'update'])->whereNumber('tahap')->name('proyek.update');
    Route::post('/proyek/alias', [DaftarProyekController::class, 'petakan'])->name('proyek.petakan');
    // Anggaran (rencana omset & HPP) per tahap + master komponennya.
    Route::get('/pilah-transaksi', [\App\Http\Controllers\PilahTransaksiController::class, 'index'])->name('pilah.index');
    Route::get('/pilah-transaksi/unduh', [\App\Http\Controllers\PilahTransaksiController::class, 'unduh'])->name('pilah.unduh');
    Route::post('/pilah-transaksi', [\App\Http\Controllers\PilahTransaksiController::class, 'simpan'])->name('pilah.simpan');
    Route::get('/boq', [\App\Http\Controllers\BoqController::class, 'index'])->name('boq.index');
    Route::post('/boq/tahap/{tahap}', [\App\Http\Controllers\BoqController::class, 'simpan'])->whereNumber('tahap')->name('boq.simpan');
    Route::post('/boq/tahap/{tahap}/impor', [\App\Http\Controllers\BoqController::class, 'impor'])->whereNumber('tahap')->name('boq.impor');
    Route::post('/boq/tahap/{tahap}/tagihan', [\App\Http\Controllers\BoqController::class, 'tagihan'])->whereNumber('tahap')->name('boq.tagihan');
    Route::delete('/boq/tagihan/{tagihan}', [\App\Http\Controllers\BoqController::class, 'hapusTagihan'])->whereNumber('tagihan')->name('boq.tagihan.hapus');
    Route::post('/boq/item/{item}/opname', [\App\Http\Controllers\BoqController::class, 'opname'])->whereNumber('item')->name('boq.opname');
    Route::get('/anggaran', [\App\Http\Controllers\AnggaranController::class, 'index'])->name('anggaran.index');
    Route::get('/anggaran/tahap/{tahap}', [\App\Http\Controllers\AnggaranController::class, 'edit'])->whereNumber('tahap')->name('anggaran.edit');
    Route::post('/anggaran/tahap/{tahap}', [\App\Http\Controllers\AnggaranController::class, 'simpan'])->whereNumber('tahap')->name('anggaran.simpan');
    Route::post('/anggaran/tahap/{tahap}/ritasi', [\App\Http\Controllers\AnggaranController::class, 'simpanRitasi'])->whereNumber('tahap')->name('anggaran.ritasi');
    Route::post('/anggaran/tahap/{tahap}/kunci', [\App\Http\Controllers\AnggaranController::class, 'kunci'])->whereNumber('tahap')->name('anggaran.kunci');
    Route::get('/alokasi-biaya-umum', [\App\Http\Controllers\AlokasiOverheadController::class, 'index'])->name('alokasi-overhead.index');
    Route::post('/alokasi-biaya-umum', [\App\Http\Controllers\AlokasiOverheadController::class, 'simpan'])->name('alokasi-overhead.simpan');
    Route::post('/alokasi-biaya-umum/pos', [\App\Http\Controllers\AlokasiOverheadController::class, 'aturPos'])->name('alokasi-overhead.pos');
    Route::get('/komponen-anggaran', [\App\Http\Controllers\KomponenAnggaranController::class, 'index'])->name('komponen-anggaran.index');
    Route::post('/komponen-anggaran', [\App\Http\Controllers\KomponenAnggaranController::class, 'store'])->name('komponen-anggaran.store');
    Route::patch('/komponen-anggaran/{komponen}', [\App\Http\Controllers\KomponenAnggaranController::class, 'update'])->whereNumber('komponen')->name('komponen-anggaran.update');
    Route::delete('/komponen-anggaran/{komponen}', [\App\Http\Controllers\KomponenAnggaranController::class, 'hapus'])->whereNumber('komponen')->name('komponen-anggaran.hapus');
    Route::delete('/proyek/alias/{alias}', [DaftarProyekController::class, 'hapusAlias'])->whereNumber('alias')->name('proyek.alias.hapus');
    // Menu Absen digantikan Lapor Lapangan (owner 19 Okt 2026); alamat lama diarahkan ke sana.
    Route::get('/absen', fn () => redirect()->route('lapor.index'))->name('absen.index');
    Route::get('/lapor', [\App\Http\Controllers\LaporController::class, 'index'])->name('lapor.index');
    Route::post('/lapor', [\App\Http\Controllers\LaporController::class, 'simpan'])->name('lapor.simpan');
    Route::post('/lapor/cek', [\App\Http\Controllers\LaporController::class, 'cek'])->name('lapor.cek');
    Route::get('/lapor/foto/{foto}', [\App\Http\Controllers\LaporController::class, 'foto'])->whereNumber('foto')->name('lapor.foto');
    Route::get('/lapor-data', [\App\Http\Controllers\LaporDataController::class, 'index'])->name('lapor-data.index');
    // Review Absen Harian: lengkap / belum absen masuk-pulang per karyawan, kirim review ke WhatsApp grup / pribadi.
    Route::get('/review-absen', [\App\Http\Controllers\ReviewAbsenController::class, 'index'])->name('review-absen.index');
    Route::post('/lapor-data/pengaturan', [\App\Http\Controllers\LaporDataController::class, 'pengaturan'])->name('lapor-data.pengaturan');
    Route::post('/lapor-data/{lapor}', [\App\Http\Controllers\LaporDataController::class, 'ubah'])->whereNumber('lapor')->name('lapor-data.ubah');
    Route::get('/absen/foto/{absensi}', [\App\Http\Controllers\AbsenController::class, 'foto'])->whereNumber('absensi')->name('absen.foto');
    // Data Absensi disatukan dengan Data Lapor Lapangan (owner 19 Okt 2026); alamat lama diarahkan ke sana.
    Route::get('/absensi', fn (\Illuminate\Http\Request $r) => redirect()->route('lapor-data.index', array_filter(['tanggal' => $r->query('tanggal')])))->name('absensi.index');
    Route::get('/lokasi-absen', [\App\Http\Controllers\LokasiAbsenController::class, 'index'])->name('lokasi-absen.index');
    Route::get('/lokasi-absen/tambah', [\App\Http\Controllers\LokasiAbsenController::class, 'create'])->name('lokasi-absen.create');
    Route::post('/lokasi-absen', [\App\Http\Controllers\LokasiAbsenController::class, 'store'])->name('lokasi-absen.store');
    Route::get('/lokasi-absen/{lokasi}/edit', [\App\Http\Controllers\LokasiAbsenController::class, 'edit'])->whereNumber('lokasi')->name('lokasi-absen.edit');
    Route::put('/lokasi-absen/{lokasi}', [\App\Http\Controllers\LokasiAbsenController::class, 'update'])->whereNumber('lokasi')->name('lokasi-absen.update');
    Route::get('/jabatan', [\App\Http\Controllers\JabatanController::class, 'index'])->name('jabatan.index');
    Route::post('/jabatan', [\App\Http\Controllers\JabatanController::class, 'store'])->name('jabatan.store');
    Route::patch('/jabatan/{jabatan}', [\App\Http\Controllers\JabatanController::class, 'update'])->whereNumber('jabatan')->name('jabatan.update');
    Route::delete('/jabatan/{jabatan}', [\App\Http\Controllers\JabatanController::class, 'hapus'])->whereNumber('jabatan')->name('jabatan.hapus');
    Route::get('/mutasi-pt', [\App\Http\Controllers\MutasiPtController::class, 'index'])->name('mutasi-pt.index');
    Route::post('/mutasi-pt/catatan', [\App\Http\Controllers\MutasiPtController::class, 'catatan'])->name('mutasi-pt.catatan');
    Route::post('/mutasi-pt/tandai', [\App\Http\Controllers\MutasiPtController::class, 'tandai'])->name('mutasi-pt.tandai');
    Route::post('/mutasi-pt/perbarui', [\App\Http\Controllers\MutasiPtController::class, 'perbarui'])->name('mutasi-pt.perbarui');
    Route::get('/karyawan', [\App\Http\Controllers\KaryawanController::class, 'index'])->name('karyawan.index');
    Route::get('/karyawan/tambah', [\App\Http\Controllers\KaryawanController::class, 'create'])->name('karyawan.create');
    Route::post('/karyawan', [\App\Http\Controllers\KaryawanController::class, 'store'])->name('karyawan.store');
    Route::get('/karyawan/{karyawan}/edit', [\App\Http\Controllers\KaryawanController::class, 'edit'])->whereNumber('karyawan')->name('karyawan.edit');
    Route::put('/karyawan/{karyawan}', [\App\Http\Controllers\KaryawanController::class, 'update'])->whereNumber('karyawan')->name('karyawan.update');
    Route::get('/aset', [AsetController::class, 'index'])->name('aset.index');
    Route::get('/aset/tambah', [AsetController::class, 'create'])->name('aset.create');
    Route::post('/aset', [AsetController::class, 'store'])->name('aset.store');
    Route::get('/aset/{aset}/edit', [AsetController::class, 'edit'])->whereNumber('aset')->name('aset.edit');
    Route::put('/aset/{aset}', [AsetController::class, 'update'])->whereNumber('aset')->name('aset.update');
    Route::get('/kas/validasi-reimburse', [ReimburseKasController::class, 'validasi'])->name('kas.validasi-reimburse');
    Route::post('/kas/validasi-reimburse', [ReimburseKasController::class, 'periksaValidasi'])->name('kas.validasi-reimburse.periksa');
    Route::post('/kas/validasi-reimburse/tandai', [ReimburseKasController::class, 'tandaiValidasi'])->name('kas.validasi-reimburse.tandai');
    Route::get('/kas/history-reimburse', [RiwayatReimburseController::class, 'index'])->name('kas.riwayat-reimburse');
    Route::get('/kas/belum-reimburse.xlsx', [ReimburseKasController::class, 'unduhBelum'])->name('kas.belum-reimburse');
    Route::get('/kas/kode-gl', [KasController::class, 'kodeGl'])->name('kas.kode-gl');
    // Kas uang jalan dump truck (lembar "Kas Seabank").
    // Daftar Rekening: database rekening bersama Kas Harian & Kas UJ (satu nomor = satu nama pemilik).
    Route::get('/rekening', [\App\Http\Controllers\RekeningController::class, 'index'])->name('rekening.index');
    Route::post('/rekening', [\App\Http\Controllers\RekeningController::class, 'simpan'])->name('rekening.simpan');
    Route::patch('/rekening/{rekening}', [\App\Http\Controllers\RekeningController::class, 'ubah'])->whereNumber('rekening')->name('rekening.ubah');
    Route::get('/uj', [UjController::class, 'index'])->name('uj.index');
    Route::get('/uj/input', [UjController::class, 'create'])->name('uj.input');
    Route::post('/uj/input', [UjController::class, 'store'])->name('uj.store');
    Route::post('/uj/periksa', [UjController::class, 'periksa'])->name('uj.periksa');
    Route::get('/uj/{noUj}/edit', [UjController::class, 'edit'])->whereNumber('noUj')->name('uj.edit');
    Route::put('/uj/{noUj}', [UjController::class, 'update'])->whereNumber('noUj')->name('uj.update');
    Route::delete('/uj/{noUj}', [UjController::class, 'hapus'])->whereNumber('noUj')->name('uj.hapus');
    // Pengajuan uang jalan (disimpan di aplikasi, direalisasikan lewat Input UJ).
    Route::get('/uj/pengalihan', [\App\Http\Controllers\PengalihanUjController::class, 'index'])->name('pengalihan-uj.index');
    Route::post('/uj/pengalihan', [\App\Http\Controllers\PengalihanUjController::class, 'simpan'])->name('pengalihan-uj.simpan');
    Route::post('/uj/pengalihan/pakai', [\App\Http\Controllers\PengalihanUjController::class, 'pakai'])->name('pengalihan-uj.pakai');
    Route::post('/uj/pengalihan/sopir', [\App\Http\Controllers\PengalihanUjController::class, 'sopir'])->name('pengalihan-uj.sopir');
    Route::delete('/uj/pengalihan/{pengalihan}', [\App\Http\Controllers\PengalihanUjController::class, 'hapus'])->whereNumber('pengalihan')->name('pengalihan-uj.hapus');
    Route::get('/uj/pengajuan', [PengajuanUjController::class, 'daftar'])->name('pengajuan-uj.daftar');
    Route::get('/uj/pengajuan/baru', [PengajuanUjController::class, 'buat'])->name('pengajuan-uj.buat');
    Route::post('/uj/pengajuan', [PengajuanUjController::class, 'simpan'])->name('pengajuan-uj.simpan');
    Route::get('/uj/input/bayar-tanah', [UjController::class, 'daftarBayarTanah'])->name('uj.input.bayar-tanah');
    Route::post('/uj/pengajuan/periksa', [PengajuanUjController::class, 'periksa'])->name('pengajuan-uj.periksa');
    Route::get('/uj/pengajuan/{pengajuan}/edit', [PengajuanUjController::class, 'ubah'])->whereNumber('pengajuan')->name('pengajuan-uj.ubah');
    Route::put('/uj/pengajuan/{pengajuan}', [PengajuanUjController::class, 'simpanUbah'])->whereNumber('pengajuan')->name('pengajuan-uj.simpan-ubah');
    Route::post('/uj/pengajuan/{pengajuan}/batal', [PengajuanUjController::class, 'batal'])->whereNumber('pengajuan')->name('pengajuan-uj.batal');
    Route::get('/uj/pengajuan/transfer/kandidat', [PengajuanUjController::class, 'kandidatTransfer'])->name('pengajuan-uj.transfer-kandidat')->middleware('can:super-admin');
    Route::post('/uj/pengajuan/transfer', [PengajuanUjController::class, 'tautkanTransfer'])->name('pengajuan-uj.transfer-tautkan')->middleware('can:super-admin');
    Route::delete('/uj/pengajuan/transfer/{transfer}', [PengajuanUjController::class, 'lepasTransfer'])->whereNumber('transfer')->name('pengajuan-uj.transfer-lepas')->middleware('can:super-admin');
    Route::post('/uj/pengajuan/tanda', [PengajuanUjController::class, 'simpanTanda'])->name('pengajuan-uj.tanda-simpan')->middleware('can:super-admin');
    Route::post('/uj/pengajuan/tanda/tautkan', [PengajuanUjController::class, 'tautkanTanda'])->name('pengajuan-uj.tanda-tautkan')->middleware('can:super-admin');
    Route::delete('/uj/pengajuan/tanda/{tanda}', [PengajuanUjController::class, 'hapusTanda'])->whereNumber('tanda')->name('pengajuan-uj.tanda-hapus')->middleware('can:super-admin');
    // Ritasi dump truck (lembar "Ritasi").
    Route::get('/ritasi', [RitasiController::class, 'index'])->name('ritasi.index');
    Route::get('/ritasi/monitor', [RitasiController::class, 'monitor'])->name('ritasi.monitor');
    Route::post('/ritasi/monitor', [RitasiController::class, 'simpanBongkar'])->name('ritasi.monitor.bongkar');
    Route::post('/ritasi/monitor/unggah', [RitasiController::class, 'unggahBongkar'])->name('ritasi.monitor.unggah');
    Route::get('/ritasi/monitor/templat', [RitasiController::class, 'templatBongkar'])->name('ritasi.monitor.templat');
    Route::get('/ritasi/monitor/sinkron', [RitasiController::class, 'sinkronPengajuan'])->name('ritasi.monitor.sinkron');
    Route::post('/ritasi/monitor/sinkron', [RitasiController::class, 'jalankanSinkron'])->name('ritasi.monitor.sinkron.jalankan');
    Route::post('/ritasi/monitor/tutup', [\App\Http\Controllers\PengalihanUjController::class, 'tutup'])->name('ritasi.monitor.tutup');
    Route::get('/ritasi/bayar-tanah', [RitasiController::class, 'bayarTanah'])->name('ritasi.bayar-tanah');
    Route::post('/ritasi/bayar-tanah/rekap', [\App\Http\Controllers\BayarTanahRekapController::class, 'simpan'])->name('ritasi.bayar-tanah.rekap');
    Route::post('/ritasi/bayar-tanah/rekap/{rekap}/batal', [\App\Http\Controllers\BayarTanahRekapController::class, 'batal'])->whereNumber('rekap')->name('ritasi.bayar-tanah.batal');
    Route::get('/ritasi/performa', [RitasiController::class, 'performa'])->name('ritasi.performa');
    Route::get('/ritasi/kubikasi', [\App\Http\Controllers\KubikasiKendaraanController::class, 'index'])->name('kubikasi.index');
    Route::post('/ritasi/kubikasi', [\App\Http\Controllers\KubikasiKendaraanController::class, 'simpan'])->name('kubikasi.simpan');
    Route::get('/ritasi/buangan', [BuanganTrukController::class, 'index'])->name('ritasi.buangan');
    Route::post('/ritasi/buangan', [BuanganTrukController::class, 'simpan'])->name('ritasi.buangan.simpan');
    Route::get('/bbm', [\App\Http\Controllers\BbmController::class, 'index'])->name('bbm.index');
    Route::get('/bbm/dashboard', [\App\Http\Controllers\BbmController::class, 'dashboard'])->name('bbm.dashboard');
    Route::get('/bbm/input', [\App\Http\Controllers\BbmController::class, 'create'])->name('bbm.input');
    Route::post('/bbm/input', [\App\Http\Controllers\BbmController::class, 'store'])->name('bbm.store');
    Route::post('/bbm/opname', [\App\Http\Controllers\BbmController::class, 'opname'])->name('bbm.opname');
    Route::get('/bbm/{baris}/edit', [\App\Http\Controllers\BbmController::class, 'edit'])->whereNumber('baris')->name('bbm.edit');
    Route::put('/bbm/{baris}', [\App\Http\Controllers\BbmController::class, 'update'])->whereNumber('baris')->name('bbm.update');
    Route::delete('/bbm/{baris}', [\App\Http\Controllers\BbmController::class, 'hapus'])->whereNumber('baris')->name('bbm.hapus');
    Route::get('/ritasi/input', [RitasiController::class, 'create'])->name('ritasi.input');
    Route::post('/ritasi/input', [RitasiController::class, 'store'])->name('ritasi.store');
    Route::post('/ritasi/periksa', [RitasiController::class, 'periksa'])->name('ritasi.periksa');
    Route::get('/ritasi/{baris}/edit', [RitasiController::class, 'edit'])->whereNumber('baris')->name('ritasi.edit');
    Route::put('/ritasi/{baris}', [RitasiController::class, 'update'])->whereNumber('baris')->name('ritasi.update');
    Route::delete('/ritasi/{baris}', [RitasiController::class, 'hapus'])->whereNumber('baris')->name('ritasi.hapus');
    Route::get('/uj/reimburse', [ReimburseUjController::class, 'index'])->name('reimburse.index');
    Route::post('/uj/reimburse', [ReimburseUjController::class, 'simpan'])->name('reimburse.simpan');
    Route::get('/uj/reimburse/{reimburse}/excel', [ReimburseUjController::class, 'unduh'])->name('reimburse.unduh');
    Route::post('/logout', [GoogleController::class, 'logout'])->name('logout');
    // Tanya AI (OpenAI): chat data ERP hanya baca + speech to text; log lengkap per pengguna.
    Route::get('/tanya-ai', [TanyaAiController::class, 'index'])->name('tanya-ai');
    Route::post('/tanya-ai/kirim', [TanyaAiController::class, 'kirim'])->name('tanya-ai.kirim');
    Route::post('/tanya-ai/transkripsi', [TanyaAiController::class, 'transkripsi'])->name('tanya-ai.transkripsi');
    Route::post('/tanya-ai/pengaturan', [TanyaAiController::class, 'pengaturan'])->name('tanya-ai.pengaturan');
    Route::get('/tanya-ai/model', [TanyaAiController::class, 'modelTersedia'])->name('tanya-ai.model');
    // Halaman izin OAuth MCP (ChatGPT): wajib login dengan email terdaftar.
    Route::get('/oauth/authorize', [OAuthMcpController::class, 'izin'])->name('oauth.izin');
    Route::post('/oauth/authorize', [OAuthMcpController::class, 'putuskan'])->name('oauth.putuskan');

    // Khusus super admin.
    Route::middleware('can:super-admin')->group(function () {
        Route::get('/auth/google/hubungkan-sheets', [GoogleController::class, 'hubungkanSheets'])->name('google.hubungkan-sheets');
        Route::get('/auth/google/hubungkan-drive-foto', [GoogleController::class, 'hubungkanDriveFoto'])->name('google.hubungkan-drive-foto');
        Route::get('/pengguna', [PenggunaController::class, 'index'])->name('pengguna.index');
        Route::post('/pengguna', [PenggunaController::class, 'store'])->name('pengguna.store');
        Route::patch('/pengguna/{user}', [PenggunaController::class, 'update'])->name('pengguna.update');
        Route::get('/hak-akses-jabatan', [\App\Http\Controllers\JabatanMenuController::class, 'index'])->name('jabatan-menu.index');
        Route::patch('/hak-akses-jabatan', [\App\Http\Controllers\JabatanMenuController::class, 'update'])->name('jabatan-menu.update');
        Route::delete('/pengguna/{user}', [PenggunaController::class, 'destroy'])->name('pengguna.destroy');
    });
});
