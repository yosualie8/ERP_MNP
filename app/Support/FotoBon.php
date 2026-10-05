<?php

namespace App\Support;

use App\Models\KasFoto;
use App\Models\KasTransfer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto bon: file penuh disimpan sementara di server lalu dipindah ke Google Drive perusahaan
 * (mnp:unggah-foto-drive, tiap menit). Server hanya menyimpan pratinjau kecil untuk tampilan cepat.
 * Foto biasanya sudah diperkecil di browser sebelum diunggah (sisi terpanjang 2000 px).
 */
class FotoBon
{
    public const MAKS_SISI = 2000;

    public const SISI_KECIL = 480;

    public static function simpan(UploadedFile $file, int $noId, string $lembar, ?int $userId): KasFoto
    {
        $dasar = "{$lembar}/{$noId}-".Str::lower(Str::random(8));
        [$penuh, $ext] = self::ubah((string) file_get_contents($file->getRealPath()), self::MAKS_SISI, 82, $file);
        Storage::put("bon-sementara/{$dasar}.{$ext}", $penuh);
        [$kecil] = self::ubah($penuh, self::SISI_KECIL, 72);
        Storage::put("bon-kecil/{$dasar}.jpg", $kecil);

        return KasFoto::create([
            'no_id' => $noId,
            'lembar' => $lembar,
            'path' => "bon-sementara/{$dasar}.{$ext}",
            'path_kecil' => "bon-kecil/{$dasar}.jpg",
            'nama_asli' => mb_strimwidth($file->getClientOriginalName(), 0, 200),
            'ukuran' => strlen($penuh),
            'status_drive' => 'menunggu',
            'user_id' => $userId,
        ]);
    }

    /** Pindahkan file penuh ke Drive lalu hapus dari server. */
    public static function unggahKeDrive(KasFoto $foto, DriveFoto $drive): void
    {
        if (! $foto->path_kecil && $foto->path && Storage::exists($foto->path)) {
            [$kecil] = self::ubah((string) Storage::get($foto->path), self::SISI_KECIL, 72);
            $foto->path_kecil = preg_replace('#^bon(-sementara)?/#', 'bon-kecil/', preg_replace('/\.\w+$/', '.jpg', $foto->path));
            Storage::put($foto->path_kecil, $kecil);
        }
        $t = KasTransfer::where('no_id', $foto->no_id)->first();
        $judul = trim("{$foto->no_id} - ".($t?->tanggal?->format('Y-m-d') ?? $foto->lembar).' - '
            .Str::limit(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', (string) ($t?->keterangan ?: $t?->nama_tujuan)), 60, '')).' - '.$foto->id.'.jpg';

        [$id, $link] = $drive->unggah($foto, $judul);
        $lama = $foto->path;
        $foto->fill(['drive_file_id' => $id, 'drive_link' => $link, 'status_drive' => 'terunggah', 'pesan_drive' => null, 'path' => null])->save();
        Storage::delete($lama);
    }

    public static function hapus(KasFoto $foto): void
    {
        if ($foto->drive_file_id && ($drive = DriveFoto::terhubung())) {
            rescue(fn () => $drive->hapus($foto->drive_file_id));
        }
        Storage::delete(array_filter([$foto->path, $foto->path_kecil]));
        $foto->delete();
    }

    /** Hapus semua foto milik NO ID (dipakai saat transfernya dihapus dari sheet). */
    public static function hapusMilik(int $noId): int
    {
        $foto = KasFoto::where('no_id', $noId)->get();
        $foto->each(fn ($f) => self::hapus($f));

        return $foto->count();
    }

    /**
     * Perkecil ke sisi terpanjang $maks px, JPEG. Tanpa GD: file dikembalikan apa adanya.
     *
     * @return array{0: string, 1: string} isi, ekstensi
     */
    private static function ubah(string $asli, int $maks, int $mutu, ?UploadedFile $file = null): array
    {
        $ext = $file ? strtolower($file->guessExtension() ?: 'jpg') : 'jpg';
        if (! function_exists('imagecreatefromstring') || ! ($img = @imagecreatefromstring($asli))) {
            return [$asli, $ext === 'jpeg' ? 'jpg' : $ext];
        }
        if ($file && in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $putar = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1)] ?? 0;
            if ($putar) {
                $img = imagerotate($img, $putar, 0);
            }
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $skala = min(1, $maks / max($w, $h));
        if ($skala < 1) {
            $img = imagescale($img, (int) round($w * $skala), (int) round($h * $skala));
        }
        ob_start();
        imagejpeg($img, null, $mutu);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        // Foto penuh yang sudah kecil (diperkecil di browser) tidak dikompres ulang supaya tulisannya tetap tajam.
        if ($file && $skala >= 1 && in_array($ext, ['jpg', 'jpeg'], true) && strlen($asli) <= strlen($jpeg) * 1.3) {
            return [$asli, 'jpg'];
        }

        return [$jpeg, 'jpg'];
    }
}
