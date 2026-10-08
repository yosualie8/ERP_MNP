<?php

namespace App\Console\Commands;

use App\Support\RapikanUj as Rapikan;
use Illuminate\Console\Command;

/** Perbaikan otomatis penulisan Kas UJ (kategori baku, No Mobil dari DO yang sama, jenis dari Data Aset) ke sheet & aplikasi. */
class RapikanUj extends Command
{
    protected $signature = 'mnp:rapikan-uj {--tulis : Benar-benar menulis ke sheet (tanpa ini hanya menampilkan rencana)}';

    protected $description = 'Rapikan penulisan Kas UJ: kategori baku, No Mobil & jenis kendaraan yang pasti';

    public function handle(): int
    {
        $rencana = Rapikan::rencanaOtomatis();
        $c = collect($rencana);
        $this->line('Rencana: '.count($rencana).' baris · kategori '.$c->whereNotNull('kategori')->count()
            .' · No Mobil '.$c->whereNotNull('no_mobil')->count().' · jenis '.$c->whereNotNull('jenis')->count());
        foreach ($c->whereNotNull('kategori')->countBy('kategori') as $k => $n) {
            $this->line("  kategori → {$k}: {$n}");
        }
        if (! $this->option('tulis')) {
            $this->warn('Belum ditulis. Jalankan dengan --tulis.');

            return self::SUCCESS;
        }
        $hasil = Rapikan::tulis($rencana, null, 'Rapikan Kas UJ otomatis (kategori baku, No Mobil dari DO sama, jenis dari Data Aset)');
        $this->info("Ditulis ke sheet & aplikasi: {$hasil['ditulis']} baris; dilewati ".count($hasil['dilewati']).'.');
        foreach (array_slice($hasil['dilewati'], 0, 10) as $p) {
            $this->line("  - {$p}");
        }

        return self::SUCCESS;
    }
}
