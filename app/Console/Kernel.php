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
        $schedule->command('mnp:impor-kas --bulan-berjalan --simpan')->hourly()->withoutOverlapping();
        // Foto bon yang baru diunggah dipindah ke Google Drive perusahaan; admin tidak perlu menunggu.
        $schedule->command('mnp:unggah-foto-drive')->everyMinute()->withoutOverlapping(10);
        // Cadangan: transaksi dari aplikasi yang belum tercermin di Mutasi Reimburse ditambahkan.
        $schedule->command('mnp:sinkron-reimburse')->hourlyAt(10)->withoutOverlapping();
        // Ketikan admin langsung di lembar Kas Seabank (uang jalan) ikut masuk ke aplikasi.
        $schedule->command('mnp:impor-uj')->hourlyAt(20)->withoutOverlapping();
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
