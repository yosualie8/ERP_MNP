<?php

namespace App\Support;

use App\Models\PengaturanApp;

/**
 * Sakelar fitur (tabel pengaturan). Fitur yang dimatikan tidak dihapus: kode & datanya tetap, hanya disembunyikan & ditutup.
 */
class Fitur
{
    /**
     * Pengajuan UJ (menu Pengajuan UJ & Buat Pengajuan UJ, isi otomatis / Tautkan di Input UJ, sinkron di Monitor Ritasi).
     * Owner 25 Okt 2026: dinonaktifkan untuk semua pengguna termasuk Super Admin. Aktifkan lagi: PengaturanApp 'fitur_pengajuan_uj' = '1'.
     */
    public const PENGAJUAN_UJ = 'fitur_pengajuan_uj';

    /** Menu yang ikut sakelar Pengajuan UJ. */
    public const MENU_PENGAJUAN = ['pengajuan-uj', 'ajukan-uj'];

    private static array $nilai = [];

    public static function pengajuanUj(): bool
    {
        return self::$nilai[self::PENGAJUAN_UJ] ??= PengaturanApp::ambil(self::PENGAJUAN_UJ, '1') !== '0';
    }
}
