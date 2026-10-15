<?php

namespace App\Console\Commands;

use App\Models\Karyawan;
use App\Models\KasRiwayat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sinkron Data Karyawan dengan sheet "Mapping Jabatan Karyawan" (file Format Gaji) — daftar karyawan AKTIF beserta jabatannya
 * (keputusan owner 15 Okt 2026). Yang ada di Mapping → aktif + jabatan + site (Cost Center); yang tidak ada → nonaktif.
 * Dicocokkan per nama; yang belum ada di aplikasi ditambahkan. KTP/bank/rekening hanya mengisi yang masih kosong.
 * Masukan JSON [{nama, jabatan, ktp, cost_center, bank, no_rekening, nama_rekening, no_master}].
 */
class SinkronMappingKaryawan extends Command
{
    protected $signature = 'mnp:sinkron-mapping-karyawan {file : JSON dari sheet Mapping Jabatan Karyawan} {--simpan : tulis ke database (tanpa ini hanya pratinjau)}';

    protected $description = 'Sinkron Data Karyawan dengan sheet Mapping Jabatan Karyawan';

    public function handle(): int
    {
        $mapping = collect(json_decode((string) file_get_contents($this->argument('file')), true) ?: []);
        if ($mapping->isEmpty()) {
            $this->error('File kosong / bukan JSON.');

            return self::FAILURE;
        }
        $kunci = fn ($s) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $s)));
        $teks = fn ($v) => ($v = trim((string) $v)) === '' || $v === '0' ? null : $v;
        $semua = Karyawan::orderBy('no_master')->get();
        $perNama = $semua->groupBy(fn ($k) => $kunci($k->nama));
        $rencana = [];   // [Karyawan|null, data baru, catatan]
        $dipakai = [];
        $catatan = [];
        foreach ($mapping as $m) {
            $calon = $perNama->get($kunci($m['nama']), collect());
            // nama ganda: utamakan No Master dari sheet, lalu yang sedang aktif
            $k = $calon->count() > 1 ? ($calon->firstWhere('no_master', $m['no_master'] ?? -1) ?? $calon->firstWhere('status', 'aktif') ?? $calon->sortByDesc('no_master')->first()) : $calon->first();
            if ($calon->count() > 1) {
                $catatan[] = "{$m['nama']}: ada {$calon->count()} data bernama sama, dipakai No {$k->no_master}";
            }
            $nik = ($n = preg_replace('/\D/', '', (string) ($m['ktp'] ?? ''))) === '' || (int) $n === 0 ? null : $n;
            $isi = ['status' => 'aktif', 'jabatan' => $teks($m['jabatan']), 'site' => Karyawan::rapikanSite($m['cost_center'] ?? null)];
            $lengkap = ['nik' => $nik, 'bank' => $teks($m['bank'] ?? null), 'no_rekening' => $teks($m['no_rekening'] ?? null), 'nama_rekening' => $teks($m['nama_rekening'] ?? null)];
            if ($k) {
                $dipakai[$k->id] = true;
                foreach ($lengkap as $f => $v) {
                    if ($v !== null && ! $k->{$f}) {
                        $isi[$f] = $v; // hanya mengisi yang kosong
                    }
                }
                $beda = collect($isi)->filter(fn ($v, $f) => (string) $k->{$f} !== (string) $v);
                if ($beda->isNotEmpty()) {
                    $rencana[] = [$k, $beda->all()];
                }
            } else {
                $rencana[] = [null, ['nama' => trim(preg_replace('/\s+/', ' ', $m['nama'])), 'no_master' => $m['no_master'] ?? null] + $isi + $lengkap];
            }
        }
        $nonaktif = $semua->filter(fn ($k) => ! isset($dipakai[$k->id]) && $k->status === 'aktif');

        foreach ($rencana as [$k, $isi]) {
            $this->line($k ? "  ~ {$k->nama}: ".collect($isi)->map(fn ($v, $f) => "$f ".json_encode($k->{$f}).' → '.json_encode($v))->implode(', ')
                : "  + BARU {$isi['nama']} ({$isi['jabatan']}, {$isi['site']}".($isi['no_master'] ? ", No {$isi['no_master']}" : ', tanpa No Master').')');
        }
        foreach ($nonaktif as $k) {
            $this->line("  - {$k->nama} ({$k->site}): aktif → nonaktif");
        }
        foreach ($catatan as $c) {
            $this->warn("  ! $c");
        }
        $baru = collect($rencana)->filter(fn ($x) => ! $x[0])->count();
        $this->info(count($rencana) - $baru." diubah, $baru baru, {$nonaktif->count()} dinonaktifkan. Aktif sesudahnya: ".$mapping->count());
        if (! $this->option('simpan')) {
            $this->warn('Pratinjau saja. Jalankan dengan --simpan untuk menulis.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($rencana, $nonaktif, $baru) {
            foreach ($rencana as [$k, $isi]) {
                $k ? $k->update($isi) : Karyawan::create($isi);
            }
            foreach ($nonaktif as $k) {
                $k->update(['status' => 'nonaktif']);
            }
            KasRiwayat::create(['aksi' => 'karyawan-mapping', 'lembar' => 'Karyawan', 'baris_awal' => 0, 'baris_akhir' => 0,
                'ringkasan' => 'Sinkron dengan sheet Mapping Jabatan Karyawan: '.(count($rencana) - $baru)." diubah, $baru baru, {$nonaktif->count()} dinonaktifkan",
                'isi' => ['ubah' => collect($rencana)->map(fn ($x) => ['id' => $x[0]?->id, 'isi' => $x[1]])->all(), 'nonaktif' => $nonaktif->pluck('id')->all()], 'user_id' => null]);
        });
        $this->info('Tersimpan. Aktif: '.Karyawan::where('status', 'aktif')->count().', nonaktif: '.Karyawan::where('status', 'nonaktif')->count());

        return self::SUCCESS;
    }
}
