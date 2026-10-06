<?php

namespace App\Console\Commands;

use App\Models\KasFoto;
use App\Support\DriveFoto;
use App\Support\FotoBon;
use App\Support\TautanBon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Pindahkan foto bon yang masih di server ke Google Drive perusahaan (langsung setelah disimpan, dan tiap menit sebagai cadangan),
 * lalu pastikan kolom Kode Bon transaksinya berisi link folder foto di Drive.
 */
class UnggahFotoDrive extends Command
{
    protected $signature = 'mnp:unggah-foto-drive {--batas=20 : Jumlah foto per jalan} {--no-id=* : Hanya tulis link Kode Bon untuk NO ID ini (mis. foto lama)}';

    protected $description = 'Unggah foto bon yang menunggu ke Google Drive perusahaan, hapus file penuhnya dari server, dan tulis link folder di Kode Bon';

    public function handle(): int
    {
        $drive = DriveFoto::terhubung();
        if (! $drive) {
            $this->line('Google Drive foto belum dihubungkan; foto tetap di server.');

            return self::SUCCESS;
        }
        // Unggah segera (setelah simpan) dan jadwal tiap menit bisa berjalan bersamaan; jangan unggah foto yang sama dua kali.
        $kunci = Cache::lock('unggah-foto-drive', 600);
        if (! $kunci->get()) {
            $this->line('Unggahan lain sedang berjalan.');

            return self::SUCCESS;
        }

        try {
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

            // Foto yang ditambah lewat halaman 📎, atau folder yang gagal dibuat saat simpan: link menyusul di sini.
            $noIds = $foto->where('status_drive', 'terunggah')->pluck('no_id')->merge($this->option('no-id'))->map(fn ($n) => (int) $n)->unique();
            foreach ($noIds as $noId) {
                try {
                    if ($n = TautanBon::pastikan($noId)) {
                        $this->info("NO ID {$noId}: link folder ditulis di {$n} sel Kode Bon");
                    }
                    $this->tautkanFotoLama($drive, $noId);
                } catch (\Throwable $e) {
                    $this->warn("NO ID {$noId}: link Kode Bon gagal ditulis: {$e->getMessage()}");
                }
            }
        } finally {
            $kunci->release();
        }

        return self::SUCCESS;
    }

    /** Foto yang diunggah sebelum ada folder per transaksi (langsung di folder lembar) dipindah ke folder transaksinya. */
    private function tautkanFotoLama(DriveFoto $drive, int $noId): void
    {
        foreach (KasFoto::where('no_id', $noId)->whereNotNull('drive_file_id')->get() as $f) {
            $drive->pindahkan($f->drive_file_id, $drive->folderTransaksi($noId, $f->lembar)['id']);
        }
    }
}
