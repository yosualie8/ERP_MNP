<?php

namespace App\Console\Commands;

use App\Support\DaftarRekening;
use Illuminate\Console\Command;

/**
 * Isi Daftar Rekening (database rekening bersama Kas Harian & Kas UJ) dari Kas Harian, Kas UJ & Data Karyawan. Tanpa --perbarui
 * hanya menambah nomor rekening yang belum ada; dengan --perbarui yang terisi otomatis dihitung ulang (yang diisi admin tetap).
 */
class IsiRekening extends Command
{
    protected $signature = 'mnp:isi-rekening {--perbarui : Hitung ulang rekening yang terisi otomatis}';

    protected $description = 'Isi / perbarui Daftar Rekening dari Kas Harian, Kas UJ & Data Karyawan';

    public function handle(): int
    {
        $h = DaftarRekening::isi((bool) $this->option('perbarui'));
        if ($h['baru'] || $h['diperbarui']) {
            DaftarRekening::log("Daftar Rekening diisi otomatis: {$h['baru']} rekening baru, {$h['diperbarui']} diperbarui", $h, null);
        }
        $this->info("{$h['baru']} baru, {$h['diperbarui']} diperbarui, total {$h['total']} rekening");

        return self::SUCCESS;
    }
}
