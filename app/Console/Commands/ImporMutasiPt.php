<?php

namespace App\Console\Commands;

use App\Support\ImporMutasiPt as Impor;
use Illuminate\Console\Command;

/**
 * Impor Mutasi Rekening PT (BCA) dari file Drive bila file berubah (--paksa: impor ulang walau tidak berubah).
 * Sejak ada upload KlikBCA: arah dibalik — transaksi baru ERP ditambahkan ke sheet Drive (TulisMutasiPtDrive).
 */
class ImporMutasiPt extends Command
{
    protected $signature = 'mnp:impor-mutasi-pt {--paksa}';

    protected $description = 'Mutasi Rekening PT (BCA): impor dari xlsx Drive, atau sejak upload KlikBCA tulis transaksi baru ke xlsx Drive';

    public function handle(): int
    {
        $h = Impor::jalankan((bool) $this->option('paksa'));
        if ($h['status'] === 'upload') {
            $t = \App\Support\TulisMutasiPtDrive::jalankanAman((bool) $this->option('paksa'));
            $this->info('Sheet Drive: '.$t['pesan']);

            return self::SUCCESS;
        }
        $this->info($h['status'] === 'tetap' ? "File tidak berubah sejak impor terakhir ({$h['diubah']})." : "Diimpor {$h['baris']} baris (file diubah {$h['diubah']}).");

        return self::SUCCESS;
    }
}
