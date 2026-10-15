<?php

namespace App\Support;

/**
 * Watermark pada selfie absen, dipasang DI SERVER sesudah absen divalidasi: jenis absen & waktu server, koordinat GPS,
 * proyek (tahap) & lokasi, nama karyawan. Kotak kecil transparan di POJOK KIRI BAWAH supaya tidak menutupi wajah.
 */
class WatermarkAbsen
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    private const FONT_TEBAL = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    /** @param  array<int, string>  $baris  baris pertama ditulis tebal kuning (jenis absen & waktu) */
    public static function pasang(string $path, array $baris): void
    {
        $img = @imagecreatefromjpeg($path);
        if (! $img) {
            return;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $ttf = is_file(self::FONT) && is_file(self::FONT_TEBAL) && function_exists('imagettftext');
        $ukuran = max(8, (int) round($w / 64));          // ±11 px pada foto 720 px
        $spasi = (int) round($ukuran * 1.45);
        $pad = (int) round($ukuran * 0.6);
        $tepi = (int) round($w / 60);

        // ukur lebar teks terpanjang
        $lebar = 0;
        foreach ($baris as $i => $t) {
            if ($ttf) {
                $b = imagettfbbox($ukuran, 0, $i === 0 ? self::FONT_TEBAL : self::FONT, $t);
                $lebar = max($lebar, $b[2] - $b[0]);
            } else {
                $lebar = max($lebar, imagefontwidth(2) * strlen($t));
            }
        }
        $kotakW = min($lebar + $pad * 2, $w - $tepi * 2);
        $kotakH = ($ttf ? $spasi : 13) * count($baris) + $pad * 2 - ($ttf ? (int) round($ukuran * 0.35) : 0);
        $x0 = $tepi;
        $y0 = $h - $tepi - $kotakH;

        imagealphablending($img, true);
        imagefilledrectangle($img, $x0, $y0, $x0 + $kotakW, $y0 + $kotakH, imagecolorallocatealpha($img, 0, 0, 0, 70));
        $putih = imagecolorallocate($img, 255, 255, 255);
        $kuning = imagecolorallocate($img, 255, 214, 90);
        foreach ($baris as $i => $t) {
            if ($ttf) {
                imagettftext($img, $ukuran, 0, $x0 + $pad, $y0 + $pad + $ukuran + $i * $spasi, $i === 0 ? $kuning : $putih, $i === 0 ? self::FONT_TEBAL : self::FONT, $t);
            } else {
                imagestring($img, 2, $x0 + $pad, $y0 + $pad + $i * 13, $t, $i === 0 ? $kuning : $putih);
            }
        }
        imagejpeg($img, $path, 85);
        imagedestroy($img);
    }
}
