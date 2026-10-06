<?php

namespace App\Console\Commands;

use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Support\CerminReimburse;
use App\Support\GoogleSheets;
use Illuminate\Console\Command;

/**
 * Cadangan: transaksi yang diinput/diedit lewat aplikasi (60 hari terakhir) tetapi belum ada di Mutasi Reimburse
 * (mis. penulisan sesaat setelah simpan gagal) ditambahkan di bawah data terakhir. Transaksi yang diisi admin langsung
 * di sheet Kas Harian tidak disentuh — tetap disalin admin seperti biasa.
 */
class SinkronReimburse extends Command
{
    protected $signature = 'mnp:sinkron-reimburse {--no-id=* : Tambahkan juga transfer ber-NO ID ini (isi susulan)}';

    protected $description = 'Tambahkan baris transaksi dari aplikasi yang belum ada di lembar Mutasi Reimburse';

    public function handle(): int
    {
        if (! GoogleSheets::terhubung()) {
            $this->line('Google Sheets belum dihubungkan.');

            return self::SUCCESS;
        }
        $noIds = KasRiwayat::whereIn('aksi', ['tambah', 'ubah'])->where('created_at', '>=', now()->subDays(60))->get()
            ->flatMap(fn ($r) => (array) ($r->isi['no_id'] ?? []))
            ->merge(array_map('intval', $this->option('no-id')))->map(fn ($n) => (int) $n)->filter()->unique()->values();
        $transfer = KasTransfer::whereIn('no_id', $noIds)->get();
        $n = CerminReimburse::wajib()->tambahYangBelum($transfer);
        $this->info("Mutasi Reimburse: {$n} baris ditambahkan (dari {$transfer->count()} transfer yang dicek).");

        return self::SUCCESS;
    }
}
