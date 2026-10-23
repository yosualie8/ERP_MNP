<?php

namespace App\Console\Commands;

use App\Support\CekServer as Cek;
use Illuminate\Console\Command;

/** Cek sisa disk & foto yang tertahan (belum terunggah ke Drive); masalah baru dicatat ke riwayat dan tampil di Beranda Super Admin. */
class CekServer extends Command
{
    protected $signature = 'mnp:cek-server';

    protected $description = 'Cek sisa disk server & unggahan foto ke Google Drive';

    public function handle(): int
    {
        $h = Cek::catat();
        $this->info("Disk: {$h['disk']['terpakai']} / {$h['disk']['total']} GB terpakai, sisa {$h['disk']['persen_sisa']}%");
        foreach ($h['masalah'] as $m) {
            $this->warn($m);
        }

        return self::SUCCESS;
    }
}
