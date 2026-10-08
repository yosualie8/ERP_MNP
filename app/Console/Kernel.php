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
        // Cadangan: transaksi dari aplikasi yang belum tercermin di Mutasi Reimburse ditambahkan.
        $schedule->command('mnp:sinkron-reimburse')->hourlyAt(10)->withoutOverlapping();
        // Status reimburse milik aplikasi → lembar Sudah Reimburse (cadangan bila penulisan sesudah simpan gagal; tanpa antrean = tidak membaca sheet).
        $schedule->command('mnp:status-reimburse')->everyFiveMinutes()->withoutOverlapping();
        // Ketikan/penghapusan admin langsung di lembar Kas Seabank (uang jalan) ikut ke aplikasi.
        $schedule->command('mnp:impor-uj')->everyFiveMinutes()->withoutOverlapping();
        // Lembar Ritasi (Proyek ASG - Gsheet) ikut ke aplikasi.
        $schedule->command('mnp:impor-ritasi')->everyFiveMinutes()->withoutOverlapping();
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
