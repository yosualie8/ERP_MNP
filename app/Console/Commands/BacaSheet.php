<?php

namespace App\Console\Commands;

use App\Support\GoogleSheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Salin isi spreadsheet (nilai tampil + rumus) ke storage/app/sheet/<nama>/ sebagai JSON,
 * supaya susunannya bisa dipelajari tanpa membuka sheet berulang kali.
 */
class BacaSheet extends Command
{
    protected $signature = 'mnp:baca-sheet
        {spreadsheet : Link atau id spreadsheet}
        {--lembar=* : Hanya lembar tertentu (boleh berulang); kosong = semua lembar}';

    protected $description = 'Baca seluruh isi spreadsheet Google ke storage/app/sheet sebagai JSON';

    public function handle(): int
    {
        $sheets = GoogleSheets::wajib();
        $id = GoogleSheets::idDari($this->argument('spreadsheet'));
        $info = $sheets->info($id);

        $judul = $info['properties']['title'];
        $folder = 'sheet/'.Str::slug($judul);
        $this->info("{$judul} (akun {$sheets->email()})");

        $pilihan = $this->option('lembar');
        $lembar = collect($info['sheets'])
            ->filter(fn ($s) => ! $pilihan || in_array($s['properties']['title'], $pilihan, true))
            ->values();

        Storage::put("{$folder}/_info.json", json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $baris = [];
        // Dicicil per 4 lembar supaya satu respons tidak terlalu besar.
        foreach ($lembar->chunk(4) as $kelompok) {
            $nama = $kelompok->pluck('properties.title')->all();
            $nilai = $sheets->nilai($id, $nama);
            $rumus = $sheets->nilai($id, $nama, 'FORMULA');

            foreach ($kelompok as $s) {
                $t = $s['properties']['title'];
                Storage::put("{$folder}/".Str::slug($t).'.json', json_encode([
                    'lembar' => $t,
                    'gid' => $s['properties']['sheetId'],
                    'tersembunyi' => $s['properties']['hidden'] ?? false,
                    'merges' => $s['merges'] ?? [],
                    'nilai' => $nilai[$t],
                    'rumus' => $rumus[$t],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $jumlahRumus = collect($rumus[$t])->flatten()->filter(fn ($v) => is_string($v) && str_starts_with($v, '='))->count();
                $baris[] = [$t, $s['properties']['sheetId'], count($nilai[$t]), collect($nilai[$t])->map(fn ($r) => count($r))->max() ?? 0, $jumlahRumus];
            }
        }

        $this->table(['Lembar', 'gid', 'Baris terisi', 'Kolom', 'Sel berumus'], $baris);
        $this->line('Tersimpan di '.Storage::path($folder));

        return self::SUCCESS;
    }
}
