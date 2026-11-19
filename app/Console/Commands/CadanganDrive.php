<?php

namespace App\Console\Commands;

use App\Support\CadanganDrive as Cadangan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/** Cadangan harian database (+ mingguan storage/app & .env dengan --file) ke folder pribadi "Cadangan ERP MNP" di Google Drive perusahaan. */
class CadanganDrive extends Command
{
    protected $signature = 'mnp:cadangan-drive {--file : Sertakan arsip storage/app + .env} {--tanpa-db : Lewati database (hanya dengan --file)}';

    protected $description = 'Cadangkan database (dan file aplikasi) ke Google Drive perusahaan, lalu buang cadangan lama';

    public function handle(): int
    {
        $drive = Cadangan::wajib();
        $cap = now()->format('Ymd-Hi');
        $tmp = sys_get_temp_dir()."/mnp-cadangan-{$cap}";
        @mkdir($tmp, 0700, true);
        $gagal = false;

        try {
            if (! $this->option('tanpa-db')) {
                $gagal = ! $this->langkah('db', function () use ($drive, $tmp, $cap) {
                    $f = "{$tmp}/mnp-db-{$cap}.sql.gz";
                    Cadangan::dumpDatabase($f);
                    if (filesize($f) < 100_000) {
                        throw new \RuntimeException('Hasil dump terlalu kecil ('.filesize($f).' byte) — dump tidak lengkap.');
                    }
                    [$id] = $drive->unggah($f, basename($f), $drive->folder('Database'));
                    $buang = $drive->rapikan('Database');

                    return ['file' => basename($f), 'id' => $id, 'mb' => round(filesize($f) / 1048576, 1), 'dibuang' => $buang];
                });
            }

            if ($this->option('file')) {
                $gagal = ! $this->langkah('file', function () use ($drive, $tmp, $cap) {
                    $f = "{$tmp}/mnp-file-{$cap}.tar.gz";
                    $hasil = Process::timeout(3600)->path(base_path())->run(['tar', '-czf', $f, '--warning=no-file-changed', '.env', 'storage/app']);
                    // kode 1 = ada file berubah saat dibaca (mis. foto baru masuk) — arsip tetap sah
                    if (! in_array($hasil->exitCode(), [0, 1], true)) {
                        throw new \RuntimeException('tar gagal: '.mb_strimwidth(trim($hasil->errorOutput()), 0, 300, '…'));
                    }
                    [$id] = $drive->unggah($f, basename($f), $drive->folder('File'));
                    $buang = $drive->rapikan('File');

                    return ['file' => basename($f), 'id' => $id, 'mb' => round(filesize($f) / 1048576, 1), 'dibuang' => $buang];
                }) || $gagal;
            }
        } finally {
            Process::run(['rm', '-rf', $tmp]);
        }

        return $gagal ? self::FAILURE : self::SUCCESS;
    }

    private function langkah(string $jenis, callable $kerja): bool
    {
        $label = $jenis === 'db' ? 'database' : 'file aplikasi + .env';
        $mulai = microtime(true);
        try {
            $hasil = $kerja() + ['detik' => round(microtime(true) - $mulai)];
            Cadangan::simpanStatus("{$jenis}_berhasil", $hasil);
            $this->info("Cadangan {$label}: {$hasil['file']} ({$hasil['mb']} MB, {$hasil['detik']} dtk) → Drive; {$hasil['dibuang']} cadangan lama dibuang.");

            return true;
        } catch (\Throwable $e) {
            Cadangan::simpanStatus("{$jenis}_gagal", ['pesan' => mb_strimwidth($e->getMessage(), 0, 300, '…')]);
            Cadangan::riwayat("⚠ Cadangan {$label} ke Google Drive gagal: {$e->getMessage()}");
            $this->error("Cadangan {$label} gagal: {$e->getMessage()}");

            return false;
        }
    }
}
