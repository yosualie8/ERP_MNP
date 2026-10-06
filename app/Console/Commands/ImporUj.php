<?php

namespace App\Console\Commands;

use App\Support\GoogleSheets;
use App\Support\KasSeabank;
use Illuminate\Console\Command;

/** Impor ulang lembar "Kas Seabank" (kas uang jalan) ke aplikasi; dijadwalkan tiap jam supaya ketikan admin di sheet ikut masuk. */
class ImporUj extends Command
{
    protected $signature = 'mnp:impor-uj';

    protected $description = 'Impor lembar Kas Seabank (uang jalan dump truck) dari Google Sheets';

    public function handle(): int
    {
        $sheets = GoogleSheets::terhubung();
        if (! $sheets) {
            $this->line('Google Sheets belum dihubungkan.');

            return self::SUCCESS;
        }
        $t0 = microtime(true);
        $h = KasSeabank::impor($sheets);
        $this->info(sprintf('Kas Seabank: %d transaksi, %d detail (%.1f dtk).', $h['transaksi'], $h['detail'], microtime(true) - $t0));

        return self::SUCCESS;
    }
}
