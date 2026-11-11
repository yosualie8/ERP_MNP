<?php

namespace App\Console\Commands;

use App\Models\PenawaranSpk;
use App\Support\GmailMnp;
use App\Support\SinkronGmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Ambil penawaran terkirim & SPK masuk baru dari Gmail MNP (terjadwal tiap 15 menit). */
class SinkronGmailCommand extends Command
{
    protected $signature = 'mnp:sinkron-gmail {--sejak= : Tanggal awal YYYY/MM/DD (bawaan: 7 hari sebelum email terakhir)} {--urai-ulang : Baca ulang angka dari teks tersimpan untuk baris yang belum dicek}';

    protected $description = 'Penawaran & SPK baru dari Gmail MNP → menu Penawaran & SPK';

    public function handle(): int
    {
        if ($this->option('urai-ulang')) {
            $n = 0;
            foreach (PenawaranSpk::whereNull('dicek_at')->get() as $d) {
                $d->update($d->jenis === 'spk' ? SinkronGmail::uraiSpk((string) $d->teks, $d->nama_file) : SinkronGmail::uraiPenawaran((string) $d->teks, $d->nama_file));
                if ($d->jenis === 'spk' && $d->status === 'baru') {
                    SinkronGmail::sarankan($d);
                }
                $n++;
            }
            SinkronGmail::tautPenawaran();
            $this->info("{$n} baris dibaca ulang.");

            return self::SUCCESS;
        }
        $gmail = GmailMnp::terhubung();
        if (! $gmail) {
            $this->warn('Gmail MNP belum dihubungkan.');

            return self::SUCCESS;
        }
        // Jalan manual (--sejak) dan terjadwal tidak boleh bersamaan: dua OCR berebut CPU sampai tesseract timeout.
        $kunci = Cache::lock('mnp-sinkron-gmail', 3600);
        if (! $kunci->get()) {
            $this->warn('Sinkron Gmail sedang berjalan — coba lagi nanti.');

            return self::SUCCESS;
        }
        try {
            $hasil = SinkronGmail::jalankan($gmail, $this->option('sejak') ?: SinkronGmail::terakhir(), fn ($s) => $this->line($s));
        } finally {
            $kunci->release();
        }
        $this->info("Baru: {$hasil['penawaran']} penawaran, {$hasil['spk']} SPK.");

        return self::SUCCESS;
    }
}
