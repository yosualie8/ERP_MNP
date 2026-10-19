<?php

namespace App\Console\Commands;

use App\Models\LaporFoto;
use App\Support\DriveFoto;
use App\Support\FotoLapor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Pindahkan foto Lapor Lapangan yang masih di server ke Google Drive (langsung setelah dikirim, dan tiap menit sebagai cadangan). */
class UnggahFotoLapor extends Command
{
    protected $signature = 'mnp:unggah-foto-lapor {--batas=30 : Jumlah foto per jalan}';

    protected $description = 'Unggah foto Lapor Lapangan yang menunggu ke Google Drive perusahaan dan hapus file penuhnya dari server';

    public function handle(): int
    {
        $drive = DriveFoto::terhubung();
        if (! $drive) {
            $this->line('Google Drive foto belum dihubungkan; foto tetap di server.');

            return self::SUCCESS;
        }
        $kunci = Cache::lock('unggah-foto-lapor', 600);
        if (! $kunci->get()) {
            return self::SUCCESS;
        }
        try {
            foreach (LaporFoto::whereIn('status_drive', ['menunggu', 'gagal'])->where('percobaan', '<', 10)->whereNotNull('path')->orderBy('id')->limit((int) $this->option('batas'))->get() as $f) {
                try {
                    FotoLapor::unggahKeDrive($f, $drive);
                    $this->info("foto #{$f->id} (lapor #{$f->lapor_id}) → Drive");
                } catch (\Throwable $e) {
                    $f->update(['status_drive' => 'gagal', 'percobaan' => $f->percobaan + 1, 'pesan_drive' => mb_strimwidth($e->getMessage(), 0, 480, '…')]);
                    $this->warn("foto #{$f->id} gagal: {$e->getMessage()}");
                }
            }
        } finally {
            $kunci->release();
        }

        return self::SUCCESS;
    }
}
