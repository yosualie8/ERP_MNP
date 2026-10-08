<?php

namespace App\Console\Commands;

use App\Support\GoogleSheets;
use App\Support\StatusReimburse as Status;
use Illuminate\Console\Command;

class StatusReimburse extends Command
{
    protected $signature = 'mnp:status-reimburse';

    protected $description = 'Salin lembar "Sudah Reimburse" ke database (status reimburse di Kas Harian)';

    public function handle(): int
    {
        if (! GoogleSheets::terhubung()) {
            $this->line('Google Sheets belum dihubungkan.');

            return self::SUCCESS;
        }
        $t = microtime(true);
        $n = Status::sinkron(GoogleSheets::wajib());
        $this->info(sprintf('Sudah Reimburse: %d ID transaksi (%.1f dtk).', $n, microtime(true) - $t));

        return self::SUCCESS;
    }
}
