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
