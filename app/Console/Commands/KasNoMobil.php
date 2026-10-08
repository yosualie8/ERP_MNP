<?php

namespace App\Console\Commands;

use App\Models\AsetTruk;
use App\Models\KasBon;
use App\Models\KasRiwayat;
use App\Support\BacaLembarKas;
use App\Support\GoogleSheets;
use App\Support\ImporKas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Isi kolom S "NO MOBIL" Kas Harian untuk detail lama yang keterangannya menyebut tepat SATU truk terdaftar
 * ("Oli Mesin DT034", "Solar DT 40"). Keterangan yang menyebut beberapa truk dilewati (dipilih admin lewat Edit).
 * Ditulis ke sheet (setelah dicek baris & nominalnya masih sama), lalu lembarnya diimpor ulang.
 */
class KasNoMobil extends Command
{
    protected $signature = 'mnp:kas-no-mobil {--tulis : Benar-benar menulis ke sheet (tanpa ini hanya menampilkan rencana)}';

    protected $description = 'Isi No Mobil Kas Harian dari keterangan yang menyebut satu nomor DT';

    public function handle(): int
    {
        $aset = AsetTruk::pluck('no_lambung')->flip();
        $bon = KasBon::whereNull('no_mobil')->with('transfer.kasBulan')->get();
        $rencana = [];
        $ganda = 0;
        foreach ($bon as $b) {
            preg_match_all('/\bDT\s*[-.]?\s*0?(\d{2,3})\b/i', (string) $b->keterangan, $m);
            $dt = collect($m[1])->map(fn ($n) => 'DT '.str_pad($n, 3, '0', STR_PAD_LEFT))->unique()->filter(fn ($d) => isset($aset[$d]))->values();
            if ($dt->count() > 1) {
                $ganda++;
            }
            if ($dt->count() === 1 && $b->transfer?->kasBulan) {
                $rencana[$b->transfer->kasBulan->lembar][] = ['bon' => $b, 'dt' => $dt[0]];
            }
        }
        $total = collect($rencana)->flatten(1)->count();
        $this->line("Rencana: {$total} detail · menyebut beberapa truk (dilewati): {$ganda}");
        foreach ($rencana as $l => $r) {
            $this->line("  {$l}: ".count($r).' detail, contoh: '.collect($r)->take(2)->map(fn ($x) => $x['dt'].' ← '.mb_strimwidth($x['bon']->keterangan, 0, 40, '…'))->implode(' · '));
        }
        if (! $this->option('tulis')) {
            $this->warn('Belum ditulis. Jalankan dengan --tulis.');

            return self::SUCCESS;
        }

        $sheets = GoogleSheets::wajib();
        $id = config('mnp.sheet_kas_harian');
        $ditulis = 0;
        $dilewati = [];
        foreach ($rencana as $lembar => $daftar) {
            Cache::lock('tulis-kas-sheet', 120)->block(60, function () use ($sheets, $id, $lembar, $daftar, &$ditulis, &$dilewati) {
                $nilai = $sheets->nilai($id, [$lembar])[$lembar];
                $judul = collect($nilai)->search(fn ($r) => in_array('Tanggal', array_map('trim', $r), true));
                $data = trim((string) ($nilai[$judul][18] ?? '')) === '' ? ["{$lembar}!S".($judul + 1) => [['NO MOBIL']]] : [];
                foreach ($daftar as $x) {
                    $b = $x['bon'];
                    $r = $nilai[$b->baris - 1] ?? [];
                    if (BacaLembarKas::rupiah(trim((string) ($r[10] ?? ''))) !== (int) $b->nominal || trim((string) ($r[12] ?? '')) !== trim((string) $b->keterangan)) {
                        $dilewati[] = "{$lembar} baris {$b->baris}";

                        continue;
                    }
                    $data["{$lembar}!S{$b->baris}"] = [[$x['dt']]];
                    $ditulis++;
                }
                foreach (array_chunk($data, 400, true) as $potong) {
                    $sheets->tulis($id, $potong);
                }
            });
            (new ImporKas)->simpan(ImporKas::baca(ImporKas::ambilDariSheet([$lembar])));
            $this->line("  {$lembar}: ditulis & diimpor ulang");
        }
        KasRiwayat::create(['aksi' => 'kas-no-mobil', 'lembar' => 'Kas', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "No Mobil Kas Harian dari keterangan: {$ditulis} detail", 'isi' => ['dilewati' => $dilewati], 'user_id' => null]);
        $this->info("Ditulis {$ditulis} detail; dilewati ".count($dilewati).' (sheet berubah).');

        return self::SUCCESS;
    }
}
