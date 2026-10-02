<?php

namespace App\Console\Commands;

use App\Support\ImporKas as Impor;
use Illuminate\Console\Command;

/**
 * Impor lembar bulanan Kas Bank Jago (0126, 0226, …) dari spreadsheet Kas Harian MNP.
 * Tanpa --simpan hanya menampilkan hasil rekonsiliasi.
 */
class ImporKas extends Command
{
    protected $signature = 'mnp:impor-kas
        {--lembar=* : Hanya lembar tertentu, mis. --lembar=0926 (boleh berulang); kosong = semua lembar BBTT}
        {--bulan-berjalan : Hanya lembar bulan ini dan bulan lalu (dipakai sinkron otomatis tiap jam)}
        {--simpan : Tulis ke database}
        {--dari-folder= : Baca dari folder JSON hasil mnp:baca-sheet, bukan dari Google}';

    protected $description = 'Impor & rekonsiliasi lembar kas bulanan dari Kas Harian MNP';

    public function handle(): int
    {
        $pilihan = $this->option('lembar');
        if ($this->option('bulan-berjalan')) {
            $pilihan = [now()->format('my'), now()->subMonthNoOverflow()->format('my')];
        }

        $sumber = ($folder = $this->option('dari-folder')) ? $this->dariFolder($folder, $pilihan) : Impor::ambilDariSheet($pilihan);
        if (! $sumber) {
            $this->error('Tidak ada lembar bulanan (format BBTT) yang cocok.');

            return self::FAILURE;
        }

        $hasil = Impor::baca($sumber);
        $baris = [];
        foreach ($hasil as $h) {
            foreach ($h['catatan'] as $c) {
                $this->warn("  {$h['lembar']}: {$c}");
            }
            $baris[] = [$h['lembar'], rp($h['saldo_awal']), count($h['transfer']), rp($h['total_debet']), rp($h['total_kredit']),
                $h['jumlah_bon'], rp($h['total_bon']), rp($h['saldo_akhir']), $h['catatan'] ? count($h['catatan']).' catatan' : 'cocok'];
        }
        $this->table(['Lembar', 'Saldo awal', 'Transfer', 'Masuk', 'Keluar', 'Bon', 'Total bon', 'Saldo akhir', 'Rekonsiliasi'], $baris);

        if (! $this->option('simpan')) {
            $this->line('Belum disimpan. Jalankan lagi dengan --simpan untuk menulis ke database.');

            return self::SUCCESS;
        }

        (new Impor)->simpan($hasil);
        $this->info('Tersimpan: '.implode(', ', array_keys($hasil)).'.');

        return self::SUCCESS;
    }

    private function dariFolder(string $folder, array $pilihan): array
    {
        $hasil = [];
        foreach (glob(rtrim($folder, '/\\').'/*.json') as $file) {
            $j = json_decode(file_get_contents($file), true);
            $lembar = $j['lembar'] ?? '';
            if (preg_match('/^\d{4}$/', $lembar) && (! $pilihan || in_array($lembar, $pilihan, true))) {
                $hasil[$lembar] = $j['nilai'];
            }
        }

        return $hasil;
    }
}
