<?php

namespace App\Console\Commands;

use App\Support\GoogleSheets;
use App\Support\StatusReimburse as Status;
use Illuminate\Console\Command;

/**
 * Status reimburse milik aplikasi → lembar "Sudah Reimburse".
 * Tanpa opsi: tulis status dari aplikasi yang belum tertulis di sheet (cadangan bila penulisan sesudah simpan gagal).
 * --impor-awal: ambil status dari sheet SEKALI (migrasi awal); --paksa untuk menimpa yang sudah ada.
 */
class StatusReimburse extends Command
{
    protected $signature = 'mnp:status-reimburse {--impor-awal : Ambil status dari lembar Sudah Reimburse (sekali)} {--paksa : Timpa status di aplikasi}';

    protected $description = 'Tulis status reimburse dari aplikasi ke lembar Sudah Reimburse (atau impor awal sekali)';

    public function handle(): int
    {
        if (! GoogleSheets::terhubung()) {
            $this->line('Google Sheets belum dihubungkan.');

            return self::SUCCESS;
        }
        $t = microtime(true);
        if ($this->option('impor-awal')) {
            $n = Status::imporAwal(GoogleSheets::wajib(), (bool) $this->option('paksa'));
            $this->info(sprintf('Impor awal status reimburse: %d ID transaksi (%.1f dtk).', $n, microtime(true) - $t));

            return self::SUCCESS;
        }
        if (! Status::antreanSheet()) {
            return self::SUCCESS;
        }
        $n = Status::dorongKeSheet(GoogleSheets::wajib());
        $this->info(sprintf('Sudah Reimburse: %d baris ditulis ke sheet (%.1f dtk); antrean tersisa %d.', $n, microtime(true) - $t, Status::antreanSheet()));

        return self::SUCCESS;
    }
}
