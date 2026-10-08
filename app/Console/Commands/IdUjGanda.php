<?php

namespace App\Console\Commands;

use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Support\GoogleSheets;
use App\Support\KasSeabank;
use App\Support\StatusReimburse;
use App\Support\TulisUjSheet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ID UJ yang dipakai lebih dari satu baris Kas Seabank: baris pertama (nomor baris terkecil) tetap memakai ID-nya, baris
 * lainnya diberi ID UJ baru. Rujukan di spreadsheet reimburse (kolom C "Id Transaksi UJ" lembar Mutasi Reimburse & Sudah
 * Reimburse) ikut diganti untuk baris yang nominalnya cocok dengan baris UJ yang diberi ID baru.
 */
class IdUjGanda extends Command
{
    protected $signature = 'mnp:id-uj-ganda {--tulis : Benar-benar menulis (tanpa ini hanya menampilkan rencana)}';

    protected $description = 'Beri ID UJ baru untuk baris Kas UJ yang ID-nya dobel (beserta rujukannya di sheet reimburse)';

    /** Lembar reimburse => [range, indeks kolom nominal (Kredit)]. */
    private const RUJUKAN = ['Mutasi Reimburse' => ['A2:P', 15], StatusReimburse::LEMBAR => ['A2:O', 14]];

    public function handle(): int
    {
        $tulis = (bool) $this->option('tulis');
        $kunci = [Cache::lock('tulis-uj-sheet', 300), Cache::lock('tulis-reimburse', 300), Cache::lock('tulis-sudah-reimburse', 300)];
        foreach ($kunci as $k) {
            $k->block(120);
        }
        try {
            return $this->jalankan($tulis);
        } finally {
            foreach ($kunci as $k) {
                $k->release();
            }
        }
    }

    private function jalankan(bool $tulis): int
    {
        $ganda = UjDetail::whereNotNull('id_uj')->where('id_uj', '!=', '')->select('id_uj')->groupBy('id_uj')->havingRaw('COUNT(*) > 1')->pluck('id_uj');
        if ($ganda->isEmpty()) {
            $this->info('Tidak ada ID UJ dobel.');

            return self::SUCCESS;
        }
        $sheets = GoogleSheets::wajib();
        $l = KasSeabank::LEMBAR;
        $max = TulisUjSheet::idTerbesar();

        // Baris UJ yang diberi ID baru (semua kecuali baris pertama tiap ID), dicek dulu masih sama dengan sheet.
        $baru = []; // baris => [lama, baru, nominal]
        foreach ($ganda as $id) {
            foreach (UjDetail::where('id_uj', $id)->orderBy('baris')->get()->slice(1) as $d) {
                $baru[$d->baris] = ['lama' => $id, 'baru' => 'UJ-'.(++$max), 'nominal' => (int) $d->nominal, 'ket' => $d->keterangan];
            }
        }
        $ranges = array_map(fn ($n) => "{$l}!A{$n}:H{$n}", array_keys($baru));
        $isi = $sheets->nilaiMentah(KasSeabank::id(), $ranges);
        foreach ($baru as $n => $b) {
            $r = $isi["{$l}!A{$n}:H{$n}"][0] ?? [];
            if (trim((string) ($r[0] ?? '')) !== $b['lama'] || KasSeabank::angka($r[7] ?? null) !== $b['nominal']) {
                $this->error("Baris {$n} di sheet sudah berubah (bukan {$b['lama']} ".rp($b['nominal']).'). Jalankan mnp:impor-uj lalu ulangi.');

                return self::FAILURE;
            }
        }

        // Rujukan di sheet reimburse: tiap baris ber-ID lama dipasangkan ke baris UJ ber-ID itu dengan nominal sama
        // (urut nomor baris); hanya yang terpasang ke baris yang diberi ID baru yang diganti.
        $rujukan = [];
        $sheetReimburse = config('mnp.sheet_reimburse');
        foreach (self::RUJUKAN as $lembar => [$range, $kolNominal]) {
            $rg = "'{$lembar}'!{$range}";
            $baris = $sheets->nilaiMentah($sheetReimburse, [$rg])[$rg];
            $terpakai = [];
            foreach ($baris as $i => $r) {
                $id = trim((string) ($r[2] ?? ''));
                if (! $ganda->contains($id)) {
                    continue;
                }
                $nominal = KasSeabank::angka($r[$kolNominal] ?? null);
                $uj = UjDetail::where('id_uj', $id)->orderBy('baris')->get()
                    ->first(fn ($d) => (int) $d->nominal === $nominal && ! isset($terpakai[$d->baris]));
                if (! $uj) {
                    $this->warn("  {$lembar} baris ".($i + 2)." ({$id}, ".rp((int) $nominal).') tidak cocok dengan baris UJ mana pun — dibiarkan.');

                    continue;
                }
                $terpakai[$uj->baris] = true;
                if (isset($baru[$uj->baris])) {
                    $rujukan["'{$lembar}'!C".($i + 2)] = ['ke' => $baru[$uj->baris]['baru'], 'lama' => $id, 'id_kas' => (string) ($r[1] ?? ''), 'baris_uj' => $uj->baris];
                }
            }
        }

        // Sel yang berisi rumus tidak ditimpa.
        if ($rujukan) {
            $rumus = $sheets->nilai($sheetReimburse, array_keys($rujukan), 'FORMULA');
            foreach (array_keys($rujukan) as $sel) {
                if (str_starts_with((string) ($rumus[$sel][0][0] ?? ''), '=')) {
                    $this->warn("  {$sel} berisi rumus — dibiarkan.");
                    unset($rujukan[$sel]);
                }
            }
        }

        foreach ($baru as $n => $b) {
            $this->line("Kas Seabank baris {$n}: {$b['lama']} → {$b['baru']} (".rp($b['nominal']).' '.mb_strimwidth((string) $b['ket'], 0, 40, '…').')');
            foreach ($rujukan as $sel => $r) {
                if ($r['baris_uj'] === $n) {
                    $this->line("    rujukan {$sel} ({$r['id_kas']}) → {$r['ke']}");
                }
            }
        }
        $this->line(count($baru).' baris UJ, '.count($rujukan).' rujukan di sheet reimburse.');
        if (! $tulis) {
            $this->warn('Belum ditulis. Jalankan dengan --tulis.');

            return self::SUCCESS;
        }

        $sheets->tulis(KasSeabank::id(), collect($baru)->mapWithKeys(fn ($b, $n) => ["{$l}!A{$n}" => [[$b['baru']]]])->all());
        if ($rujukan) {
            $sheets->tulis($sheetReimburse, collect($rujukan)->map(fn ($r) => [[$r['ke']]])->all());
        }
        Cache::forever('uj-max', $max);
        DB::transaction(function () use ($baru) {
            foreach ($baru as $n => $b) {
                UjDetail::where('baris', $n)->where('id_uj', $b['lama'])->update(['id_uj' => $b['baru']]);
            }
        });
        KasRiwayat::create(['aksi' => 'uj-id-ganda', 'lembar' => 'Seabank', 'baris_awal' => (int) min(array_keys($baru)), 'baris_akhir' => (int) max(array_keys($baru)),
            'ringkasan' => 'ID UJ baru untuk '.count($baru).' baris ber-ID dobel ('.count($rujukan).' rujukan reimburse ikut diganti)',
            'isi' => ['uj' => $baru, 'rujukan' => $rujukan], 'user_id' => null]);
        $this->info('Ditulis.');

        return self::SUCCESS;
    }
}
