<?php

namespace App\Console\Commands;

use App\Models\KubikasiKendaraan;
use App\Support\GoogleSheets;
use App\Support\LembarRitasi;
use App\Support\NomorMobil;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Isi kolom X (Kubikasi) lembar Ritasi yang masih kosong dengan kubikasi standar jenis kendaraannya (tanpa jenis = 0),
 * sekaligus merapikan salah ketik jenis kendaraan di kolom L (mis. "Cold diesel"). Tanpa --simpan hanya menampilkan hasil.
 */
class IsiKubikasiRitasi extends Command
{
    protected $signature = 'mnp:isi-kubikasi-ritasi {--simpan : Tulis ke sheet lalu impor ulang Ritasi}';

    protected $description = 'Isi kubikasi standar di lembar Ritasi untuk rit yang kubikasinya masih kosong';

    public function handle(): int
    {
        $sheets = GoogleSheets::wajib();
        $id = LembarRitasi::id();
        $l = LembarRitasi::LEMBAR;
        $peta = KubikasiKendaraan::peta();

        $hasil = Cache::lock('tulis-ritasi-sheet', 300)->block(120, function () use ($sheets, $id, $l, $peta) {
            $mentah = $sheets->nilaiMentah($id, ["{$l}!A1:X"])["{$l}!A1:X"];
            $data = [];
            $hitung = ['isi' => 0, 'sudah' => 0, 'jenis_diperbaiki' => 0];
            $tanpaMaster = [];
            $kolomX = [];
            foreach ($mentah as $i => $r) {
                $n = $i + 1;
                if ($n === 1) {
                    $kolomX[] = [trim((string) ($r[23] ?? '')) ?: 'Kubikasi'];

                    continue;
                }
                $x = $r[LembarRitasi::KOLOM_KUBIKASI] ?? '';
                if (! LembarRitasi::adaData($r)) {
                    $kolomX[] = [$x];

                    continue;
                }
                $asli = trim((string) ($r[11] ?? ''));
                $jenis = NomorMobil::rapikanJenis($asli);
                if ($jenis && $asli !== '' && strcasecmp($asli, 'Cold diesel') === 0) {
                    $data["{$l}!L{$n}"] = [[$jenis]];
                    $hitung['jenis_diperbaiki']++;
                }
                if ($x !== '' && $x !== null) {
                    $kolomX[] = [$x];
                    $hitung['sudah']++;

                    continue;
                }
                $k = KubikasiKendaraan::untuk($jenis, $peta);
                if ($k === null) {
                    $tanpaMaster[$jenis] = ($tanpaMaster[$jenis] ?? 0) + 1;
                    $kolomX[] = [''];

                    continue;
                }
                $kolomX[] = [$k];
                $hitung['isi']++;
            }
            $data["{$l}!X1:X".count($kolomX)] = $kolomX;
            if ($this->option('simpan')) {
                $sheets->tulis($id, $data);
            }

            return [$hitung, $tanpaMaster];
        });

        [$hitung, $tanpaMaster] = $hasil;
        $this->info("Kubikasi diisi: {$hitung['isi']} rit · sudah ada isinya: {$hitung['sudah']} · \"Cold diesel\" diperbaiki: {$hitung['jenis_diperbaiki']}");
        foreach ($tanpaMaster as $j => $n) {
            $this->warn("  Jenis \"{$j}\" belum ada di master kubikasi ({$n} rit) — dibiarkan kosong.");
        }
        if ($this->option('simpan')) {
            $this->info('Ditulis ke sheet. Impor ulang Ritasi: '.LembarRitasi::impor($sheets).' rit.');
        } else {
            $this->line('Belum ditulis. Jalankan lagi dengan --simpan.');
        }

        return self::SUCCESS;
    }
}
