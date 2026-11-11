<?php

namespace App\Console\Commands;

use App\Support\GmailMnp;
use Illuminate\Console\Command;

/** Lihat (baca saja) email Gmail MNP sesuai pencarian — dipakai untuk menyusun aturan pengenal penawaran & SPK. */
class GmailLihat extends Command
{
    protected $signature = 'mnp:gmail-lihat {q=newer_than:90d : Pencarian Gmail} {--maks=200} {--json}';

    protected $description = 'Daftar email Gmail MNP (baca saja): tanggal, dari, ke, subjek, lampiran';

    public function handle(): int
    {
        $gmail = GmailMnp::terhubung();
        if (! $gmail) {
            $this->error('Gmail MNP belum dihubungkan (Beranda → Hubungkan Gmail).');

            return self::FAILURE;
        }
        $baris = array_map(fn ($id) => $gmail->ringkas($id), $gmail->cari($this->argument('q'), (int) $this->option('maks')));
        if ($this->option('json')) {
            $this->line(json_encode($baris, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        foreach ($baris as $b) {
            $arah = in_array('SENT', $b['label'], true) ? 'KIRIM' : 'MASUK';
            $this->line("{$b['waktu']} | {$arah} | {$b['dari']} → {$b['ke']} | {$b['subjek']} | ".implode(', ', $b['lampiran']));
        }
        $this->info(count($baris).' email.');

        return self::SUCCESS;
    }
}
