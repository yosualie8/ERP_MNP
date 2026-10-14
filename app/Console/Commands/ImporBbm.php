<?php

namespace App\Console\Commands;

use App\Support\GoogleSheets;
use App\Support\LembarBbm;
use Illuminate\Console\Command;

/** Impor ulang lembar "Penggunaan BBM" ke aplikasi (dijadwalkan tiap 5 menit): baris yang ditambah/diubah/dihapus di sheet ikut. */
class ImporBbm extends Command
{
    protected $signature = 'mnp:impor-bbm';

    protected $description = 'Impor lembar Penggunaan BBM dari Google Sheets';

    public function handle(): int
    {
        $sheets = GoogleSheets::terhubung();
        if (! $sheets) {
            $this->line('Google Sheets belum dihubungkan.');

            return self::SUCCESS;
        }
        $t0 = microtime(true);
        $n = LembarBbm::impor($sheets);
        $this->info(sprintf('Penggunaan BBM: %d baris (%.1f dtk).', $n, microtime(true) - $t0));

        return self::SUCCESS;
    }
}
