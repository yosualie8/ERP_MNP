<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasTransfer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Kolom Kode Bon (P) di sheet berisi link folder Google Drive tempat foto bon transaksi itu disimpan.
 * Saat Input/Edit: folder dibuat lebih dulu dan link-nya ikut ditulis bersama baris transaksi (langsung, tanpa menunggu).
 * Foto yang ditambah belakangan (halaman 📎) atau gagal dibuatkan folder saat simpan: link ditulis oleh pastikan().
 */
class TautanBon
{
    /**
     * Pembuat link untuk TulisKasSheet::tulis/ubah: NO ID transfer → link folder, atau null bila Drive belum
     * terhubung / gagal (Kode Bon lalu diisi kode biasa dan link menyusul lewat pastikan()).
     *
     * @return callable(int): ?string
     */
    public static function pembuat(CarbonInterface $tanggal, ?string $nama, ?string $keterangan): callable
    {
        return function (int $noId) use ($tanggal, $nama, $keterangan): ?string {
            $drive = DriveFoto::terhubung();

            return $drive ? rescue(fn () => $drive->folderTransaksi($noId, $tanggal->format('my'), self::namaFolder($noId, $tanggal, $nama, $keterangan))['link']) : null;
        };
    }

    public static function namaFolder(int $noId, CarbonInterface $tanggal, ?string $nama, ?string $keterangan): string
    {
        $isi = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', trim(implode(' - ', array_filter([trim((string) $nama), trim((string) $keterangan)]))));

        return trim("{$noId} - ".$tanggal->format('Y-m-d').' - '.Str::limit($isi, 80, ''), ' -');
    }

    public static function adalahLink(?string $v): bool
    {
        return str_starts_with(trim((string) $v), 'https://drive.google.com/');
    }

    /**
     * Pastikan semua baris detail transaksi NO ID ini berisi link folder fotonya di kolom Kode Bon.
     * Baris di sheet dicocokkan lewat NO ID (Q) supaya tidak menimpa baris lain bila sheet bergeser sejak impor terakhir.
     *
     * @return int jumlah sel yang ditulis
     */
    public static function pastikan(int $noId): int
    {
        $t = KasTransfer::where('no_id', $noId)->with('bon', 'kasBulan')->first();
        if (! $t || $t->bon->isEmpty() || ! ($drive = DriveFoto::terhubung())) {
            return 0;
        }
        $folder = $drive->folderTransaksi($noId, $t->kasBulan->lembar, self::namaFolder($noId, $t->tanggal, $t->nama_tujuan, $t->keterangan));
        $perlu = $t->bon->filter(fn (KasBon $b) => trim((string) $b->kode_bon) !== $folder['link']);
        if ($perlu->isEmpty()) {
            return 0;
        }

        $sheets = GoogleSheets::wajib();
        $id = config('mnp.sheet_kas_harian');
        $lembar = $t->kasBulan->lembar;

        return Cache::lock('tulis-kas-sheet', 60)->block(30, function () use ($sheets, $id, $lembar, $perlu, $folder) {
            [$dari, $sampai] = [$perlu->min('baris'), $perlu->max('baris')];
            $isi = $sheets->nilai($id, ["{$lembar}!P{$dari}:Q{$sampai}"])["{$lembar}!P{$dari}:Q{$sampai}"] ?? [];
            $data = [];
            foreach ($perlu as $b) {
                $q = $isi[$b->baris - $dari][1] ?? '';
                if (TulisKasSheet::angkaNoId($q) !== TulisKasSheet::angkaNoId($b->no_id)) {
                    continue; // baris sudah bergeser; dicoba lagi setelah sinkron berikutnya
                }
                $data["{$lembar}!P{$b->baris}"] = [[$folder['link']]];
                $b->update(['kode_bon' => $folder['link']]);
            }
            if ($data) {
                $sheets->tulis($id, $data);
            }

            return count($data);
        });
    }
}
