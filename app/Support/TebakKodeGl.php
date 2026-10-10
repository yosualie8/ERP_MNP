<?php

namespace App\Support;

/**
 * Bon yang Kode GL-nya kosong di sheet: tebak dari keterangan supaya tidak tercampur dengan biaya.
 * Hasil tebakan ditandai di aplikasi; sheet tidak diubah.
 */
class TebakKodeGl
{
    public const TALANGAN = 'Pengembalian Talangan';

    public const SALAH_TRANSFER = 'Salah Transfer & Refund';

    /** Pasangan TALANGAN untuk uang masuk: kas ditalangi (mis. oleh Yosua). */
    public const TERIMA_TALANGAN = 'Penerimaan Talangan';

    public static function dari(?string $keterangan, ?string $gl): ?string
    {
        $k = strtolower((string) $keterangan);

        return match (true) {
            strcasecmp(trim((string) $gl), 'Adm') === 0, str_contains($k, 'biaya transfer') => 'Biaya Transfer Antar Bank',
            (bool) preg_match('/talang|tampungan dana|tabungan gaji/', $k) => self::TALANGAN,
            (bool) preg_match('/salah tr|refund|tombok/', $k) => self::SALAH_TRANSFER,
            default => null,
        };
    }

    /** Uang masuk yang Kode GL-nya kosong di sheet. */
    public static function masuk(?string $keterangan): ?string
    {
        return preg_match('/talang/', strtolower((string) $keterangan)) ? self::TERIMA_TALANGAN : null;
    }
}
