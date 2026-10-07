<?php

namespace App\Support;

/**
 * Nomor dump truck baku: "DT" + spasi + 3 digit (DT 004, DT 062). Spasi dobel/di tepi, huruf kecil, tanpa spasi,
 * dan huruf O yang dimaksud angka 0 dirapikan ("DT  026", "Dt 038", "DT027", "DT O42" → DT 026, DT 038, DT 027, DT 042).
 * Nomor lain (mis. "LV 02") hanya dirapikan spasi & huruf besarnya.
 */
class NomorMobil
{
    public static function rapikan(?string $nomor): ?string
    {
        $v = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $nomor)));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^DT\s*([O0-9]{1,3})$/', $v, $m)) {
            return 'DT '.str_pad(str_replace('O', '0', $m[1]), 3, '0', STR_PAD_LEFT);
        }

        return $v;
    }

    /** Jenis kendaraan baku: "FAW" / "faw" → "Faw"; "LV" tetap. */
    public static function rapikanJenis(?string $jenis): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $jenis));
        if ($v === '') {
            return null;
        }

        return strlen($v) <= 2 ? strtoupper($v) : ucfirst(strtolower($v));
    }
}
