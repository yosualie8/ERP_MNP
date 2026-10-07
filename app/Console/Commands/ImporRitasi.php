<?php

namespace App\Console\Commands;

use App\Support\GoogleSheets;
use App\Support\LembarRitasi;
use Illuminate\Console\Command;

/** Impor ulang lembar "Ritasi" ke aplikasi (dijadwalkan tiap 5 menit): rit yang ditambah/diubah/dihapus di sheet ikut. */
class ImporRitasi extends Command
{
    protected $signature = 'mnp:impor-ritasi';

    protected $description = 'Impor lembar Ritasi (Proyek ASG - Gsheet) dari Google Sheets';

    public function handle(): int
    {
        $sheets = GoogleSheets::terhubung();
        if (! $sheets) {
            $this->line('Google Sheets belum dihubungkan.');

            return self::SUCCESS;
        }
        $t0 = microtime(true);
        $n = LembarRitasi::impor($sheets);
        $this->info(sprintf('Ritasi: %d rit (%.1f dtk).', $n, microtime(true) - $t0));

        return self::SUCCESS;
    }
}
