<?php

namespace App\Console\Commands;

use App\Support\ImporMutasiPt as Impor;
use Illuminate\Console\Command;

/** Impor Mutasi Rekening PT (BCA) dari file Drive bila file berubah (--paksa: impor ulang walau tidak berubah). */
class ImporMutasiPt extends Command
{
    protected $signature = 'mnp:impor-mutasi-pt {--paksa}';

    protected $description = 'Impor Mutasi Rekening PT (BCA) dari file xlsx di Drive';

    public function handle(): int
    {
        $h = Impor::jalankan((bool) $this->option('paksa'));
        $this->info($h['status'] === 'tetap' ? "File tidak berubah sejak impor terakhir ({$h['diubah']})." : "Diimpor {$h['baris']} baris (file diubah {$h['diubah']}).");

        return self::SUCCESS;
    }
}
