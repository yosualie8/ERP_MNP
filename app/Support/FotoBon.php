<?php

namespace App\Support;

use App\Models\KasFoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Simpan foto bon (referensi admin) di storage/app/bon/<lembar>/, diperkecil supaya hemat ruang. */
class FotoBon
{
    public const MAKS_SISI = 2000;

    public static function simpan(UploadedFile $file, int $noId, string $lembar, ?int $userId): KasFoto
    {
        $nama = "bon/{$lembar}/{$noId}-".Str::lower(Str::random(8));
        [$isi, $ext] = self::kecilkan($file);
        Storage::put("{$nama}.{$ext}", $isi);

        return KasFoto::create([
            'no_id' => $noId,
            'lembar' => $lembar,
            'path' => "{$nama}.{$ext}",
            'nama_asli' => mb_strimwidth($file->getClientOriginalName(), 0, 200),
            'ukuran' => strlen($isi),
            'user_id' => $userId,
        ]);
    }

    public static function hapus(KasFoto $foto): void
    {
        Storage::delete($foto->path);
        $foto->delete();
    }

    /** Hapus semua foto milik NO ID (dipakai saat transfernya dihapus dari sheet). */
    public static function hapusMilik(int $noId): int
    {
        $foto = KasFoto::where('no_id', $noId)->get();
        $foto->each(fn ($f) => self::hapus($f));

        return $foto->count();
    }

    /** @return array{0: string, 1: string} isi file, ekstensi. Tanpa GD, foto disimpan apa adanya. */
    private static function kecilkan(UploadedFile $file): array
    {
        $asli = (string) file_get_contents($file->getRealPath());
        $ext = strtolower($file->guessExtension() ?: 'jpg');
        if (! function_exists('imagecreatefromstring') || ! ($img = @imagecreatefromstring($asli))) {
            return [$asli, $ext];
        }
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $putar = function_exists('exif_read_data') ? ([3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1)] ?? 0) : 0;
            if ($putar) {
                $img = imagerotate($img, $putar, 0);
            }
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $skala = min(1, self::MAKS_SISI / max($w, $h));
        if ($skala < 1) {
            $img = imagescale($img, (int) round($w * $skala), (int) round($h * $skala));
        }
        ob_start();
        imagejpeg($img, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        // Bila hasil kompresi justru lebih besar (foto sudah kecil), simpan aslinya.
        return strlen($jpeg) < strlen($asli) || $ext !== 'jpg' ? [$jpeg, 'jpg'] : [$asli, $ext];
    }
}
