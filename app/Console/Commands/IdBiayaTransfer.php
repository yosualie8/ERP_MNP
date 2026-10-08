<?php

namespace App\Console\Commands;

use App\Models\KasReimburseUj;
use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Support\GoogleSheets;
use App\Support\KasSeabank;
use App\Support\TulisUjSheet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Baris "Biaya Transfer" di Kas UJ yang belum punya ID UJ (ditulis aplikasi sebelum v0.35.0) diberi ID UJ baru (lanjutan
 * ID terbesar), ditulis ke kolom A sheet Kas Seabank dan ke aplikasi, supaya ikut terlacak saat direimburse.
 */
class IdBiayaTransfer extends Command
{
    protected $signature = 'mnp:id-biaya-transfer {--tulis : Benar-benar menulis (tanpa ini hanya menampilkan rencana)}';

    protected $description = 'Beri ID UJ untuk baris Biaya Transfer Kas UJ yang belum ber-ID';

    public function handle(): int
    {
        $tanpa = fn () => UjDetail::where('biaya_transfer', true)->where(fn ($q) => $q->whereNull('id_uj')->orWhere('id_uj', ''))->orderBy('baris')->get();
        $this->line('Biaya Transfer tanpa ID UJ: '.$tanpa()->count().' baris ('.$tanpa()->pluck('baris')->implode(', ').')');
        if (! $this->option('tulis') || $tanpa()->isEmpty()) {
            $this->option('tulis') || $this->warn('Belum ditulis. Jalankan dengan --tulis.');

            return self::SUCCESS;
        }

        $hasil = Cache::lock('tulis-uj-sheet', 300)->block(120, function () use ($tanpa) {
            $sheets = GoogleSheets::wajib();
            $l = KasSeabank::LEMBAR;
            $baris = $tanpa();
            [$dari, $sampai] = [$baris->min('baris'), $baris->max('baris')];
            $isi = $sheets->nilaiMentah(KasSeabank::id(), ["{$l}!A{$dari}:I{$sampai}"])["{$l}!A{$dari}:I{$sampai}"];
            $max = TulisUjSheet::idTerbesar();
            $data = $beri = $dilewati = [];
            foreach ($baris as $d) {
                $r = $isi[$d->baris - $dari] ?? [];
                // Sheet harus masih sama: kolom A kosong, baris biaya transfer, nominal sama.
                if (trim((string) ($r[0] ?? '')) !== '' || ! KasSeabank::biayaTransfer((string) ($r[8] ?? ''), (string) ($r[6] ?? ''))
                    || KasSeabank::angka($r[7] ?? null) !== (int) $d->nominal) {
                    $dilewati[] = $d->baris;

                    continue;
                }
                $id = 'UJ-'.(++$max);
                $data["{$l}!A{$d->baris}"] = [[$id]];
                $beri[$d->baris] = $id;
            }
            if ($data) {
                $sheets->tulis(KasSeabank::id(), $data);
                Cache::forever('uj-max', $max);
                DB::transaction(function () use ($beri) {
                    foreach ($beri as $n => $id) {
                        UjDetail::where('baris', $n)->update(['id_uj' => $id]);
                        // Tautan reimburse UJ → Kas Harian yang sudah tercatat untuk baris ini ikut membawa ID-nya.
                        KasReimburseUj::where('baris_uj', $n)->whereNull('id_uj')->update(['id_uj' => $id]);
                    }
                });
                KasRiwayat::create(['aksi' => 'uj-id-biaya', 'lembar' => 'Seabank', 'baris_awal' => (int) $dari, 'baris_akhir' => (int) $sampai,
                    'ringkasan' => 'ID UJ untuk '.count($beri).' baris Biaya Transfer', 'isi' => ['id' => $beri], 'user_id' => null]);
            }

            return [$beri, $dilewati];
        });

        foreach ($hasil[0] as $n => $id) {
            $this->line("  baris {$n} → {$id}");
        }
        $this->info('Ditulis: '.count($hasil[0]).' · dilewati (sheet berubah): '.(count($hasil[1]) ? implode(', ', $hasil[1]) : '-'));

        return self::SUCCESS;
    }
}
