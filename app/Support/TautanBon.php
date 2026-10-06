<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasTransfer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Kolom Kode Bon (P) di sheet berisi link folder Google Drive tempat foto bon transaksi itu disimpan.
 * Membuat folder di Drive butuh ±3 detik, jadi tidak dilakukan selagi admin menunggu: baris ditulis dulu dengan Kode Bon biasa,
 * lalu sesaat setelah halaman terkirim pastikanSegera() membuat folder & menimpa Kode Bon dengan link-nya (±5 detik kemudian).
 */
class TautanBon
{
    /**
     * Untuk TulisKasSheet::tulis/ubah: link folder yang sudah dikenal (tanpa memanggil Drive), supaya Edit
     * tidak menimpa link yang sudah ada dengan kode biasa. Transaksi baru: null → link menyusul lewat pastikanSegera().
     *
     * @return callable(int): ?string
     */
    public static function pembuat(): callable
    {
        return fn (int $noId): ?string => Cache::get("drive-foto-transaksi-{$noId}")['link'] ?? null;
    }

    /** Buat folder & tulis link-nya di Kode Bon sesaat setelah halaman terkirim ke admin. */
    public static function pastikanSegera(int $noId): void
    {
        dispatch(fn () => rescue(fn () => self::pastikan($noId)))->afterResponse();
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
