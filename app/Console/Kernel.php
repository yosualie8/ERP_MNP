<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Perubahan yang diketik admin langsung di sheet ikut masuk ke aplikasi.
        // Tiap 5 menit: transaksi yang ditambah/diubah/dihapus langsung di sheet cepat terlihat di aplikasi.
        $schedule->command('mnp:impor-kas --bulan-berjalan --simpan')->everyFiveMinutes()->withoutOverlapping();
        // Foto bon yang baru diunggah dipindah ke Google Drive perusahaan; admin tidak perlu menunggu.
        $schedule->command('mnp:unggah-foto-drive')->everyMinute()->withoutOverlapping(10);
        // Foto Lapor Lapangan (absen / laporan kerja) juga dipindah ke Drive.
        $schedule->command('mnp:unggah-foto-lapor')->everyMinute()->withoutOverlapping(10);
        // Cadangan: transaksi dari aplikasi yang belum tercermin di Mutasi Reimburse ditambahkan.
        $schedule->command('mnp:sinkron-reimburse')->hourlyAt(10)->withoutOverlapping();
        // Status reimburse milik aplikasi → lembar Sudah Reimburse (cadangan bila penulisan sesudah simpan gagal; tanpa antrean = tidak membaca sheet).
        $schedule->command('mnp:status-reimburse')->everyFiveMinutes()->withoutOverlapping();
        // Ketikan/penghapusan admin langsung di lembar Kas Seabank (uang jalan) ikut ke aplikasi.
        $schedule->command('mnp:impor-uj')->everyFiveMinutes()->withoutOverlapping();
        // Lembar Kas Seabank & lembar bulanan Kas Harian dikunci (hanya akun aplikasi menulis); lembar bulan baru ikut dikunci.
        $schedule->command('mnp:kunci-sheet')->everyTenMinutes()->withoutOverlapping();
        // Lembar Ritasi (Proyek ASG - Gsheet) ikut ke aplikasi.
        $schedule->command('mnp:impor-ritasi')->everyFiveMinutes()->withoutOverlapping();
        // Uang tanah [SJ …] mendapat No DO begitu rit surat jalannya ber-DO di Ritasi (owner 14 Nov 2026) — lepas dari tahanan Reimburse UJ.
        $schedule->command('mnp:isi-do-sj')->everyFiveMinutes()->withoutOverlapping();
        // Lembar Penggunaan BBM ikut ke aplikasi.
        $schedule->command('mnp:impor-bbm')->everyFiveMinutes()->withoutOverlapping();
        // Mutasi Rekening PT (BCA): file Drive diperiksa tiap 30 menit, diimpor hanya bila berubah.
        $schedule->command('mnp:impor-mutasi-pt')->everyThirtyMinutes()->withoutOverlapping();
        // Rekening baru yang diketik langsung di sheet Kas Harian / Kas UJ ikut masuk Daftar Rekening (yang sudah ada tidak diubah).
        $schedule->command('mnp:isi-rekening')->hourlyAt(20)->withoutOverlapping();
        // Sisa disk & foto yang tertahan (unggahan Drive macet) — peringatan di Beranda Super Admin + riwayat.
        $schedule->command('mnp:cek-server')->hourlyAt(40)->withoutOverlapping();
        // Penawaran terkirim & SPK masuk baru dari Gmail MNP (SPK di-OCR) → menu Penawaran & SPK + banner Beranda.
        $schedule->command('mnp:sinkron-gmail')->everyFifteenMinutes()->withoutOverlapping(30);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
