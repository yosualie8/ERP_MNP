<?php

namespace App\Console\Commands;

use App\Models\Karyawan;
use App\Models\KasRiwayat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Samakan status karyawan dengan kolom Status sheet "Master" (file Format Gaji). Masukan JSON [{no, nama, status, …}] — hanya
 * data Master (tanpa data gaji). Dicocokkan per No Master + nama yang sama; karyawan yang dibuat di aplikasi
 * (tanpa No Master) tidak diubah. Karyawan Aktif di Master yang belum ada di aplikasi ditambahkan.
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
        // Karyawan baru di Master (No belum ada di aplikasi) yang berstatus Aktif → ditambahkan.
        $adaNo = Karyawan::whereNotNull('no_master')->pluck('no_master')->flip();
        $baruMaster = $master->filter(fn ($m) => $m['status'] === 'Aktif' && ! isset($adaNo[(int) $m['no']]))->values();
        foreach ($baruMaster as $m) {
            $this->line("  + baru: No {$m['no']} {$m['nama']} (site ".($m['site'] ?? '-').')');
        }
        $this->info(count($ubah).' berubah. Tidak dicocokkan (dibiarkan): '.count($tanpaPasangan).($tanpaPasangan ? ' — '.implode('; ', $tanpaPasangan) : ''));
        $sesudah = Karyawan::pluck('status', 'id')->all();
        foreach ($ubah as [$k, $baru]) {
            $sesudah[$k->id] = $baru;
        }
        foreach ($baruMaster as $i => $m) {
            $sesudah['baru'.$i] = 'aktif';
        }
        $this->info('Sesudahnya: '.json_encode(array_count_values($sesudah)));
        if (! $this->option('simpan')) {
            $this->warn('Pratinjau saja. Jalankan dengan --simpan untuk menulis.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($ubah, $baruMaster) {
            foreach ($ubah as [$k, $baru]) {
                $k->update(['status' => $baru]);
            }
            $teks = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
            foreach ($baruMaster as $m) {
                Karyawan::create(['no_master' => (int) $m['no'], 'nama' => $m['nama'], 'status' => 'aktif', 'npwp' => $teks($m['npwp'] ?? null),
                    'nik' => ($n = preg_replace('/\D/', '', (string) ($m['nik'] ?? ''))) === '' ? null : $n, 'jabatan' => $teks($m['jabatan'] ?? null),
                    'site' => Karyawan::rapikanSite($m['site'] ?? null), 'bank' => $teks($m['bank'] ?? null),
                    'no_rekening' => $teks($m['no_rekening'] ?? null), 'nama_rekening' => $teks($m['nama_rekening'] ?? null)]);
            }
            KasRiwayat::create(['aksi' => 'karyawan-status', 'lembar' => 'Karyawan', 'baris_awal' => 0, 'baris_akhir' => 0,
                'ringkasan' => mb_strimwidth('Status disamakan dengan sheet Master (gaji Sept 2026, site 21 dihentikan): '.count($ubah).' berubah, '.count($baruMaster).' karyawan baru', 0, 490, '…'),
                'isi' => collect($ubah)->map(fn ($x) => ['id' => $x[0]->id, 'nama' => $x[0]->nama, 'status' => $x[1]])->all(), 'user_id' => null]);
        });
        $this->info('Tersimpan.');

        return self::SUCCESS;
    }
}
