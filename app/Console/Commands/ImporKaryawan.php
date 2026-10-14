<?php

namespace App\Console\Commands;

use App\Models\Karyawan;
use App\Models\KasRiwayat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Impor awal Data Karyawan dari sheet "Master" file Format Gaji MNP (.xlsx).
 * Kolom: B No, C Nama, D NPWP, E NIK, F Status, G Site, H Bank, I No Rekening, J Nama Rekening (K PIC Gaji tidak dipakai).
 * Keputusan user 14 Okt 2026: status apa adanya ("Tidak Aktif"/kosong = Non Aktif); NIK dobel tetap disimpan (ditandai di Data Karyawan);
 * kolom L–N (rekening lama) diabaikan; gaji belum diimpor. Aman diulang: baris dicocokkan lewat kolom No (no_master).
 */
class ImporKaryawan extends Command
{
    protected $signature = 'mnp:impor-karyawan {file : path file .xlsx} {--simpan : tulis ke database (tanpa ini hanya pratinjau)}';

    protected $description = 'Impor Data Karyawan dari sheet Master file Format Gaji MNP';

    public function handle(): int
    {
        $r = new Reader;
        $r->open($this->argument('file'));
        $baris = [];
        foreach ($r->getSheetIterator() as $sh) {
            if ($sh->getName() !== 'Master') {
                continue;
            }
            foreach ($sh->getRowIterator() as $row) {
                $baris[] = array_map(fn ($c) => $c->getValue(), $row->getCells());
            }
        }
        $r->close();
        if (! $baris) {
            $this->error('Sheet "Master" tidak ditemukan.');

            return self::FAILURE;
        }
        $teks = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $data = [];
        foreach (array_slice($baris, 2) as $x) {
            $nama = $teks($x[2] ?? null);
            if (! $nama || ! is_numeric($x[1] ?? null)) {
                continue;
            }
            $status = $teks($x[5] ?? null);
            $data[] = [
                'no_master' => (int) $x[1], 'nama' => $nama, 'npwp' => $teks($x[3] ?? null),
                'nik' => ($n = preg_replace('/\D/', '', (string) ($x[4] ?? ''))) === '' ? null : $n,
                'status' => $status === 'Aktif' ? 'aktif' : 'nonaktif', 'site' => Karyawan::rapikanSite($x[6] ?? null),
                'bank' => $teks($x[7] ?? null), 'no_rekening' => ($v = preg_replace('/\s+/', '', (string) ($x[8] ?? ''))) === '' ? null : $v,
                'nama_rekening' => $teks($x[9] ?? null),
            ];
        }
        // NIK dobel antar orang berbeda tetap disimpan (di Data Karyawan ditandai "dobel" supaya dicek dari KTP).
        $perNik = collect($data)->filter(fn ($d) => $d['nik'])->groupBy('nik')->filter(fn ($g) => $g->count() > 1);
        $this->info(count($data).' karyawan: '.collect($data)->countBy('status')->toJson().' | site '.collect($data)->countBy(fn ($d) => $d['site'] ?? '(kosong)')->toJson()
            .' | NIK dobel '.$perNik->count().' pasang');
        if (! $this->option('simpan')) {
            $this->line('Pratinjau saja — jalankan dengan --simpan untuk menulis.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($data) {
            foreach ($data as $d) {
                Karyawan::updateOrCreate(['no_master' => $d['no_master']], $d);
            }
        });
        KasRiwayat::create(['aksi' => 'karyawan-impor', 'lembar' => 'Karyawan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => 'Impor '.count($data).' karyawan dari sheet Master ('.basename($this->argument('file')).')', 'isi' => null, 'user_id' => null]);
        $this->info('Tersimpan. Total di database: '.Karyawan::count());

        return self::SUCCESS;
    }
}
