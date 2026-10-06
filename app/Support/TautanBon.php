<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasTransfer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Kolom Kode Bon (P) di sheet berisi smart chip folder Google Drive tempat foto bon transaksi itu disimpan
 * (tampil sebagai nama folder, mis. "31216 - 2026-10-06 - Erwin Gultom - …", bisa diklik).
 * Membuat folder di Drive butuh ±3 detik, jadi tidak dilakukan selagi admin menunggu: baris ditulis dulu dengan Kode Bon biasa,
 * lalu sesaat setelah halaman terkirim pastikanSegera() membuat folder & menimpa Kode Bon dengan chip-nya (±5 detik kemudian).
 */
class TautanBon
{
    /**
     * Untuk TulisKasSheet::ubah: folder yang sudah dikenal (tanpa memanggil Drive), supaya Edit tidak mengganti
     * link dengan kode biasa. Edit menulis link polos; pastikanSegera() lalu menjadikannya chip lagi.
     *
     * @return callable(int): ?array{id: string, link: string, nama: string}
     */
    public static function pembuat(): callable
    {
        return fn (int $noId): ?array => Cache::get("drive-foto-transaksi-{$noId}");
    }

    /** Buat folder & tulis chip-nya di Kode Bon sesaat setelah halaman terkirim ke admin. */
    public static function pastikanSegera(int $noId): void
    {
        dispatch(fn () => rescue(fn () => self::pastikan($noId)))->afterResponse();
    }

    public static function namaFolder(int $noId, CarbonInterface $tanggal, ?string $nama, ?string $keterangan): string
    {
        $isi = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', trim(implode(' - ', array_filter([trim((string) $nama), trim((string) $keterangan)]))));

        return trim("{$noId} - ".$tanggal->format('Y-m-d').' - '.Str::limit($isi, 80, ''), ' -');
    }

    /** Isi sel Kode Bon (seperti terbaca dari sheet) sudah menunjuk folder ini: chip (tampil nama folder) atau link polos. */
    public static function menunjuk(?string $isi, ?array $folder): bool
    {
        $isi = trim((string) $isi);

        return $folder && $isi !== '' && ($isi === $folder['nama'] || $isi === $folder['link'])
            || str_starts_with($isi, 'https://drive.google.com/');
    }

    /**
     * Pastikan baris detail transaksi NO ID ini berisi chip folder fotonya di kolom Kode Bon.
     * Baris di sheet dicocokkan lewat NO ID (Q) supaya tidak menimpa baris lain bila sheet bergeser sejak impor terakhir.
     * $hanyaPertama: chip hanya di detail pertama, Kode Bon detail lainnya dibiarkan (mis. NO ID 30956 dengan 91 detail).
     * Transaksi yang detail pertamanya sudah ber-link sementara detail lain masih kode biasa dianggap memilih cara itu.
     *
     * @return int jumlah sel yang ditulis
     */
    public static function pastikan(int $noId, bool $hanyaPertama = false): int
    {
        $t = KasTransfer::where('no_id', $noId)->with('bon', 'kasBulan')->first();
        if (! $t || $t->bon->isEmpty() || ! ($drive = DriveFoto::terhubung())) {
            return 0;
        }
        $folder = $drive->folderTransaksi($noId, $t->kasBulan->lembar, self::namaFolder($noId, $t->tanggal, $t->nama_tujuan, $t->keterangan));
        $bon = $t->bon->sortBy('baris')->values();
        if ($hanyaPertama || ($bon->count() > 1 && self::menunjuk($bon[0]->kode_bon, $folder) && ! self::menunjuk($bon[1]->kode_bon, $folder) && trim((string) $bon[1]->kode_bon) !== '')) {
            $bon = $bon->take(1);
        }
        // Sudah chip = isi sel tampil sebagai nama folder.
        $perlu = $bon->filter(fn (KasBon $b) => trim((string) $b->kode_bon) !== $folder['nama']);
        if ($perlu->isEmpty()) {
            return 0;
        }

        $sheets = GoogleSheets::wajib();
        $id = config('mnp.sheet_kas_harian');
        $lembar = $t->kasBulan->lembar;

        return Cache::lock('tulis-kas-sheet', 60)->block(30, function () use ($sheets, $id, $lembar, $perlu, $folder) {
            [$dari, $sampai] = [$perlu->min('baris'), $perlu->max('baris')];
            $isi = $sheets->nilai($id, ["{$lembar}!P{$dari}:Q{$sampai}"])["{$lembar}!P{$dari}:Q{$sampai}"] ?? [];
            $sel = [];
            foreach ($perlu as $b) {
                [$p, $q] = [$isi[$b->baris - $dari][0] ?? '', $isi[$b->baris - $dari][1] ?? ''];
                if (TulisKasSheet::angkaNoId($q) !== TulisKasSheet::angkaNoId($b->no_id)) {
                    continue; // baris sudah bergeser; dicoba lagi setelah sinkron berikutnya
                }
                if (trim($p) !== $folder['nama']) {
                    $sel[$b->baris] = $folder['link'];
                }
                $b->update(['kode_bon' => $folder['nama']]);
            }
            if ($sel) {
                $sheetId = collect($sheets->info($id)['sheets'])->firstWhere('properties.title', $lembar)['properties']['sheetId'];
                $sheets->chipDrive($id, $sheetId, 15, $sel);
                // Kolom Bon di Mutasi Reimburse ikut menjadi chip.
                $ids = $perlu->filter(fn (KasBon $b) => isset($sel[$b->baris]) && $b->id_transaksi)
                    ->mapWithKeys(fn (KasBon $b) => [trim($b->id_transaksi) => $folder['link']])->all();
                rescue(fn () => (new CerminReimburse($sheets))->chip($ids));
            }

            return count($sel);
        });
    }
}
