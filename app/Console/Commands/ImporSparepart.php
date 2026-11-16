<?php

namespace App\Console\Commands;

use App\Models\SparepartBeli;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Isi Database Sparepart dari JSON hasil skrip katalog (buat.py --json=…): seluruh isi tabel sparepart_beli diganti utuh.
 * Tabel ini hanya diisi lewat impor (tidak ada isian pengguna), jadi aman diganti.
 */
class ImporSparepart extends Command
{
    protected $signature = 'mnp:impor-sparepart {berkas : JSON {"ditarik": "Y-m-d", "baris": [...]}} {--simulasi : Hanya hitung, tidak menulis}';

    protected $description = 'Ganti isi Database Sparepart (sparepart_beli) dari JSON hasil skrip katalog sparepart';

    public function handle(): int
    {
        $d = json_decode((string) @file_get_contents($this->argument('berkas')), true);
        if (! is_array($d['baris'] ?? null) || ! $d['baris']) {
            $this->error('Berkas tidak terbaca / tanpa baris.');

            return self::FAILURE;
        }
        $teks = fn ($v, $n) => ($v = trim((string) $v)) === '' ? null : Str::limit($v, $n - 1, '…');
        $sekarang = now();
        $baris = array_map(fn ($b) => [
            'tanggal' => $b['tanggal'], 'kategori' => $teks($b['kat'], 60), 'nama_item' => $teks($b['nama'], 200), 'spesifikasi' => $teks($b['spek'], 150),
            'golongan' => $teks($b['gol'], 40), 'tipe_unit' => $teks($b['tu'], 60), 'no_unit' => $teks($b['unit'], 20), 'qty' => round((float) $b['qty'], 2),
            'satuan' => $teks($b['sat'], 20), 'harga_satuan' => round((float) $b['harga'], 2), 'total' => round((float) $b['total'], 2),
            'supplier' => $teks($b['supplier'], 150), 'pic' => $teks($b['pic'], 100), 'keterangan' => $teks($b['ket'], 300),
            'keterangan_transfer' => $teks($b['tket'], 300), 'akun' => $teks($b['akun'], 100), 'sumber' => $b['sumber'], 'ref' => $teks($b['ref'], 40),
            'ditarik' => $d['ditarik'] ?? null, 'created_at' => $sekarang, 'updated_at' => $sekarang,
        ], $d['baris']);

        $lama = SparepartBeli::count();
        $this->info(sprintf('%d baris (%s s.d. %s), %d item×satuan, total Rp %s — sebelumnya %d baris.', count($baris), min(array_column($baris, 'tanggal')),
            max(array_column($baris, 'tanggal')), count(array_unique(array_map(fn ($b) => $b['nama_item'].'|'.$b['satuan'], $baris))),
            rp(array_sum(array_column($baris, 'total'))), $lama));
        if ($this->option('simulasi')) {
            $this->warn('Simulasi — tidak ada yang ditulis.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($baris) {
            SparepartBeli::query()->delete();
            foreach (array_chunk($baris, 500) as $c) {
                SparepartBeli::insert($c);
            }
        });
        $this->info('Database Sparepart diganti.');

        return self::SUCCESS;
    }
}
