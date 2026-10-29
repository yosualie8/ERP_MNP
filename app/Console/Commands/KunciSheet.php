<?php

namespace App\Console\Commands;

use App\Support\GoogleSheets;
use App\Support\KasSeabank;
use Illuminate\Console\Command;

/**
 * Kunci lembar input di Google Sheets (owner 29 Okt 2026): master Kas UJ / Kas Harian hanya lewat aplikasi (dari mutasi Jago), jadi
 * lembar "Kas Seabank" & lembar bulanan Kas Harian (0126, 0226, …) diproteksi — hanya akun aplikasi (dan pemilik spreadsheet) yang bisa menulis,
 * admin tetap bisa melihat. Lembar bulan baru yang dibuat admin ikut dikunci pada jadwal berikutnya.
 */
class KunciSheet extends Command
{
    protected $signature = 'mnp:kunci-sheet';

    protected $description = 'Proteksi lembar Kas Seabank & lembar bulanan Kas Harian (hanya akun aplikasi yang bisa menulis)';

    public const KET = 'Dikunci ERP MNP: input hanya lewat aplikasi (master dari mutasi Jago) — owner 29 Okt 2026';

    public function handle(): int
    {
        $sheets = GoogleSheets::terhubung();
        if (! $sheets) {
            $this->line('Google Sheets belum dihubungkan.');

            return self::SUCCESS;
        }
        $target = [
            config('mnp.sheet_uj') => fn (string $t) => $t === KasSeabank::LEMBAR,
            config('mnp.sheet_kas_harian') => fn (string $t) => (bool) preg_match('/^\d{4}$/', $t),
        ];
        foreach ($target as $id => $pilih) {
            $res = $sheets->http()->get("https://sheets.googleapis.com/v4/spreadsheets/{$id}", ['fields' => 'sheets(properties(sheetId,title),protectedRanges(description,range))']);
            if (! $res->successful()) {
                $this->error("Gagal membaca {$id}: ".$res->status());

                continue;
            }
            $minta = [];
            foreach ($res->json('sheets') ?? [] as $s) {
                $p = $s['properties'];
                $sudah = collect($s['protectedRanges'] ?? [])->contains(fn ($r) => ($r['description'] ?? '') === self::KET);
                if ($pilih($p['title']) && ! $sudah) {
                    $minta[] = ['addProtectedRange' => ['protectedRange' => ['range' => ['sheetId' => $p['sheetId']], 'description' => self::KET,
                        'warningOnly' => false, 'editors' => ['users' => [$sheets->email()]]]]];
                    $this->info("Dikunci: {$p['title']}");
                }
            }
            $sheets->permintaan($id, $minta, 'mengunci lembar input');
        }

        return self::SUCCESS;
    }
}
