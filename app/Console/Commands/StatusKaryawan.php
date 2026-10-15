<?php

namespace App\Console\Commands;

use App\Models\Karyawan;
use App\Models\KasRiwayat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Samakan status karyawan dengan kolom Status sheet "Master" (file Format Gaji). Masukan JSON [{no, nama, status}] — hanya
 * No, Nama & Status (tanpa data gaji). Dicocokkan per No Master + nama yang sama; karyawan yang dibuat di aplikasi
 * (tanpa No Master) tidak diubah.
 */
class StatusKaryawan extends Command
{
    protected $signature = 'mnp:status-karyawan {file : JSON [{no, nama, status}]} {--simpan : tulis ke database (tanpa ini hanya pratinjau)}';

    protected $description = 'Samakan status karyawan dengan sheet Master (Format Gaji)';

    public function handle(): int
    {
        $master = collect(json_decode((string) file_get_contents($this->argument('file')), true) ?: [])->keyBy(fn ($m) => (int) $m['no']);
        if ($master->isEmpty()) {
            $this->error('File kosong / bukan JSON.');

            return self::FAILURE;
        }
        $kunci = fn ($s) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $s)));
        $ubah = [];
        $tanpaPasangan = [];
        foreach (Karyawan::orderBy('no_master')->get() as $k) {
            $m = $k->no_master ? $master->get($k->no_master) : null;
            if (! $m || $kunci($m['nama']) !== $kunci($k->nama)) {
                $tanpaPasangan[] = "{$k->nama} (No {$k->no_master}, {$k->status})";
                continue;
            }
            $baru = $m['status'] === 'Aktif' ? 'aktif' : 'nonaktif';
            if ($k->status !== $baru) {
                $ubah[] = [$k, $baru];
            }
        }
        foreach ($ubah as [$k, $baru]) {
            $this->line("  {$k->nama} ({$k->site}): {$k->status} → {$baru}");
        }
        $this->info(count($ubah).' berubah. Tidak dicocokkan (dibiarkan): '.count($tanpaPasangan).($tanpaPasangan ? ' — '.implode('; ', $tanpaPasangan) : ''));
        $sesudah = Karyawan::pluck('status', 'id')->all();
        foreach ($ubah as [$k, $baru]) {
            $sesudah[$k->id] = $baru;
        }
        $this->info('Sesudahnya: '.json_encode(array_count_values($sesudah)));
        if (! $this->option('simpan')) {
            $this->warn('Pratinjau saja. Jalankan dengan --simpan untuk menulis.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($ubah) {
            foreach ($ubah as [$k, $baru]) {
                $k->update(['status' => $baru]);
            }
            KasRiwayat::create(['aksi' => 'karyawan-status', 'lembar' => 'Karyawan', 'baris_awal' => 0, 'baris_akhir' => 0,
                'ringkasan' => mb_strimwidth('Status disamakan dengan sheet Master (gaji Sept 2026, site 21 dihentikan): '.count($ubah).' karyawan', 0, 490, '…'),
                'isi' => collect($ubah)->map(fn ($x) => ['id' => $x[0]->id, 'nama' => $x[0]->nama, 'status' => $x[1]])->all(), 'user_id' => null]);
        });
        $this->info('Tersimpan.');

        return self::SUCCESS;
    }
}
