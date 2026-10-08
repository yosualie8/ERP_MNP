<?php

namespace App\Support;

/**
 * Nama baku kategori Kas UJ (disetujui owner 8 Okt 2026), supaya pemetaan biaya per truk nanti tertarik dengan benar.
 * "Uang makan" / "Uang  Makan" → Uang Makan · "Perangsang" → Uang Perangsang · "MOB" / "Uang Mobilisasi" → Uang MOB · dst.
 */
class KategoriUj
{
    /** kunci (huruf kecil, spasi tunggal) → nama baku */
    private const PETA = [
        'uang jalan' => 'Uang Jalan', 'uang tanah' => 'Uang Tanah', 'uang makan' => 'Uang Makan',
        'uang perangsang' => 'Uang Perangsang', 'perangsang' => 'Uang Perangsang',
        'sparepart' => 'Sparepart', 'spare part' => 'Sparepart',
        'uang mob' => 'Uang MOB', 'mob' => 'Uang MOB', 'uang mobilisasi' => 'Uang MOB',
        'uang laka' => 'Uang Laka', 'laka' => 'Uang Laka',
        'uang pinjam' => 'Uang Pinjaman', 'uang pinjaman' => 'Uang Pinjaman',
        'uang lembur' => 'Uang Lembur', 'uang storing' => 'Uang Storing', 'uang material' => 'Uang Material', 'uang geser' => 'Uang Geser',
        'uang solar' => 'Uang Solar', 'uang derek' => 'Uang Derek', 'uang pasir' => 'Uang Pasir',
    ];

    /** Kategori yang biayanya milik satu truk tertentu → No Mobil semestinya diisi. */
    public const MILIK_TRUK = ['Uang Jalan', 'Uang Tanah', 'Uang Makan', 'Uang Perangsang', 'Sparepart', 'Uang MOB', 'Uang Laka', 'Uang Storing', 'Uang Geser', 'Uang Solar', 'Uang Derek'];

    public static function baku(?string $kategori): ?string
    {
        $k = strtolower(trim(preg_replace('/\s+/', ' ', (string) $kategori)));
        if ($k === '') {
            return null;
        }

        return self::PETA[$k] ?? trim(preg_replace('/\s+/', ' ', (string) $kategori));
    }

    /** Daftar nama baku untuk saran di form. */
    public static function daftar(): array
    {
        return array_values(array_unique(self::PETA));
    }

    public static function milikTruk(?string $kategori): bool
    {
        return in_array(self::baku($kategori), self::MILIK_TRUK, true);
    }
}
