<?php

namespace App\Console\Commands;

use App\Models\PenawaranSpk;
use App\Support\GmailMnp;
use App\Support\SinkronGmail;
use Illuminate\Console\Command;

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
        $hasil = SinkronGmail::jalankan($gmail, $this->option('sejak') ?: SinkronGmail::terakhir(), fn ($s) => $this->line($s));
        $this->info("Baru: {$hasil['penawaran']} penawaran, {$hasil['spk']} SPK.");

        return self::SUCCESS;
    }
}
