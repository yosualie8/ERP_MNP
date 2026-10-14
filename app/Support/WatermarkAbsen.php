<?php

namespace App\Support;

/**
 * Watermark pada selfie absen, dipasang DI SERVER sesudah absen divalidasi: jenis absen, waktu server, koordinat GPS,
 * proyek (tahap) & lokasi, nama karyawan. Pita gelap transparan di bagian bawah foto supaya teks terbaca di latar apa pun.
 */
class WatermarkAbsen
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    private const FONT_TEBAL = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    /** @param  array<int, string>  $baris  baris pertama ditulis tebal & lebih besar (jenis absen) */
    public static function pasang(string $path, array $baris): void
    {
        $img = @imagecreatefromjpeg($path);
        if (! $img) {
            return;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $besar = max(14, (int) round($w / 26));
        $kecil = max(11, (int) round($w / 40));
        $jarak = (int) round($kecil * 0.55);
        $ttf = is_file(self::FONT) && is_file(self::FONT_TEBAL) && function_exists('imagettftext');
        $tinggi = $ttf ? (int) round($besar * 1.5 + (count($baris) - 1) * ($kecil * 1.45) + $jarak * 2) : 18 * count($baris) + 16;

        imagealphablending($img, true);
        imagefilledrectangle($img, 0, $h - $tinggi, $w, $h, imagecolorallocatealpha($img, 0, 0, 0, 45));
        $putih = imagecolorallocate($img, 255, 255, 255);
        $kuning = imagecolorallocate($img, 255, 214, 90);
        $y = $h - $tinggi + $jarak;
        foreach ($baris as $i => $teks) {
            if ($ttf) {
                $ukuran = $i === 0 ? $besar : $kecil;
                $y += (int) round($ukuran * ($i === 0 ? 1.25 : 1.45));
                imagettftext($img, $ukuran, 0, $jarak + 4, $y, $i === 0 ? $kuning : $putih, $i === 0 ? self::FONT_TEBAL : self::FONT, $teks);
            } else {
                imagestring($img, 5, 8, $y, $teks, $i === 0 ? $kuning : $putih);
                $y += 18;
            }
        }
        imagejpeg($img, $path, 85);
        imagedestroy($img);
    }
}
