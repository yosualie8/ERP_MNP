<?php

namespace App\Console\Commands;

use App\Models\KasFoto;
use App\Support\DriveFoto;
use App\Support\FotoBon;
use Illuminate\Console\Command;

/** Pindahkan foto bon yang masih di server ke Google Drive perusahaan (dijadwalkan tiap menit). */
class UnggahFotoDrive extends Command
{
    protected $signature = 'mnp:unggah-foto-drive {--batas=20 : Jumlah foto per jalan}';

    protected $description = 'Unggah foto bon yang menunggu ke Google Drive perusahaan, lalu hapus file penuhnya dari server';

    public function handle(): int
    {
        $drive = DriveFoto::terhubung();
        if (! $drive) {
            $this->line('Google Drive foto belum dihubungkan; foto tetap di server.');

            return self::SUCCESS;
        }

        $foto = KasFoto::whereIn('status_drive', ['menunggu', 'gagal'])->where('percobaan', '<', 10)
            ->whereNotNull('path')->orderBy('id')->limit((int) $this->option('batas'))->get();
        foreach ($foto as $f) {
            try {
                FotoBon::unggahKeDrive($f, $drive);
                $this->info("#{$f->id} NO ID {$f->no_id} → Drive");
            } catch (\Throwable $e) {
                $f->update(['status_drive' => 'gagal', 'percobaan' => $f->percobaan + 1, 'pesan_drive' => mb_strimwidth($e->getMessage(), 0, 480, '…')]);
                $this->warn("#{$f->id} gagal: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
