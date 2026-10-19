<?php

namespace App\Support;

use App\Models\Lapor;
use App\Models\LaporFoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto Lapor Lapangan: diperkecil (sisi terpanjang 2000 px) lalu disimpan sementara di server; foto kamera diberi watermark
 * waktu server, GPS & proyek (bukti absen). mnp:unggah-foto-lapor memindahkan file penuhnya ke Google Drive
 * ("Lapor Lapangan/2026-10/2026-10-19"); server hanya menyimpan pratinjau kecil.
 */
class FotoLapor
{
    public const MAKS_FOTO = 20;

    public static function simpan(Lapor $lapor, UploadedFile $file, int $urutan, string $sumber, ?string $kamera, array $watermark): LaporFoto
    {
        $dasar = $lapor->tanggal->format('Y/m').'/'.$lapor->karyawan_id.'-'.$lapor->waktu->format('Ymd-His').'-'.$urutan.'-'.Str::lower(Str::random(6));
        [$penuh] = FotoBon::perkecil((string) file_get_contents($file->getRealPath()), FotoBon::MAKS_SISI, 82, $file);
        Storage::put("lapor-sementara/{$dasar}.jpg", $penuh);
        // Foto kamera langsung = bukti kejadian di lapangan → watermark dari data server (foto galeri tidak, karena bisa foto lama)
        if ($sumber === 'kamera' && $watermark) {
            WatermarkAbsen::pasang(Storage::path("lapor-sementara/{$dasar}.jpg"), $watermark);
        }
        [$kecil] = FotoBon::perkecil((string) Storage::get("lapor-sementara/{$dasar}.jpg"), FotoBon::SISI_KECIL, 72);
        Storage::put("lapor-kecil/{$dasar}.jpg", $kecil);

        return LaporFoto::create(['lapor_id' => $lapor->id, 'urutan' => $urutan, 'sumber' => $sumber, 'kamera' => $kamera,
            'path' => "lapor-sementara/{$dasar}.jpg", 'path_kecil' => "lapor-kecil/{$dasar}.jpg", 'ukuran' => Storage::size("lapor-sementara/{$dasar}.jpg"), 'status_drive' => 'menunggu']);
    }

    public static function unggahKeDrive(LaporFoto $f, DriveFoto $drive): void
    {
        $l = $f->lapor()->with(['karyawan', 'tahap'])->first();
        $judul = $l->waktu->format('Y-m-d H.i').' - '.Str::limit(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string) $l->karyawan?->nama), 40, '')
            .($l->tahap ? ' - '.Str::limit(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $l->tahap->nama), 50, '') : '').' - '.$l->id.'-'.$f->urutan.($f->sumber === 'galeri' ? ' (galeri)' : '').'.jpg';
        [$id, $link] = $drive->unggahFile($f->path, $judul, $drive->folderLapor($l->tanggal));
        $lama = $f->path;
        $f->fill(['drive_file_id' => $id, 'drive_link' => $link, 'status_drive' => 'terunggah', 'pesan_drive' => null, 'path' => null])->save();
        Storage::delete($lama);
    }

    /** Unggah ke Drive sesaat setelah halaman terkirim — karyawan tidak menunggu. */
    public static function unggahSegera(): void
    {
        dispatch(fn () => Artisan::call('mnp:unggah-foto-lapor'))->afterResponse();
    }
}
