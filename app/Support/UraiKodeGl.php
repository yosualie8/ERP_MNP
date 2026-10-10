<?php

namespace App\Support;

/**
 * Urai Kode GL yang ditulis bebas di sheet ("BIaya Komisi ASG T113") menjadi
 * akun baku ("Biaya Komisi"), cost center ("ASG"), dan nomor T ("T113").
 */
class UraiKodeGl
{
    /** Salah ketik yang ditemukan di sheet Jan–Okt 2026 → tulisan baku. */
    private const PERBAIKAN = [
        '/\bBIaya\b/' => 'Biaya',
        '/\bBiay\b/' => 'Biaya',
        '/\bDInas\b/' => 'Dinas',
        '/\bLIstrik\b/' => 'Listrik',
        '/\bSpareart\b/' => 'Sparepart',
        '/\bParikir\b|\bParkri\b/' => 'Parkir',
        '/\bEntetain\b|\bEntertin\b/' => 'Entertain',
        '/\bPengonatan\b/' => 'Pengobatan',
        '/\bRItasi\b|\bRiatsi\b|\bRtasi\b|\bRitaasi\b/' => 'Ritasi',
        '/\bGaji karyawan\b/' => 'Gaji Karyawan',
        '/\bCetak &/' => 'Cetakan &',
        '/\bTol & Parkir\b/' => 'Parkir & Tol',
        '/\bRetribusi dan Keamanan\b/' => 'Retribusi & Keamanan',
        '/\bLain Lain\b/' => 'Lain-lain',
        '/ AG (T\d+)$/' => ' ASG $1',
    ];

    /** Cost center di akhir kode, dicocokkan dari yang terpanjang. */
    public const COST_CENTER = ['PM Infra Marina', 'Infra PM', 'DT Buhut', 'Infra', 'PM', 'ASG', 'JKT', 'Buhut', 'DPN', 'Kendal', 'AB'];

    /**
     * "DT Buhut" = dump truck di Buhut; akunnya tetap Sparepart, cost center Buhut.
     * "PM Infra Marina" sama dengan "Infra PM" (konfirmasi user 2 Okt 2026).
     */
    private const ALIAS_COST_CENTER = ['DT Buhut' => 'Buhut', 'PM Infra Marina' => 'Infra PM'];

    /** Keterangan cost center dari user (2 Okt 2026). Nomor T = tahap proyek. */
    public const NAMA_COST_CENTER = [
        'Buhut' => 'Lokasi Buhut, Kalimantan',
        'DPN' => 'Lokasi DPN, Kalimantan',
        'Kendal' => 'Lokasi Kendal, Jawa Tengah',
        'AB' => 'Alat Berat',
    ];

    /** @return array{akun: ?string, cost_center: ?string, ref: ?string} */
    public static function urai(?string $kode): array
    {
        $kode = trim(preg_replace('/\s+/', ' ', (string) $kode));
        if ($kode === '') {
            return ['akun' => null, 'cost_center' => null, 'ref' => null];
        }
        $kode = preg_replace(array_keys(self::PERBAIKAN), array_values(self::PERBAIKAN), $kode);

        $ref = null;
        if (preg_match('/^(.*\S)\s+(T\d+)$/', $kode, $m)) {
            [$kode, $ref] = [$m[1], $m[2]];
        }

        $cc = null;
        foreach (self::COST_CENTER as $calon) {
            if (preg_match('/^(.+?)\s+'.preg_quote($calon, '/').'$/', $kode, $m)) {
                [$kode, $cc] = [$m[1], self::ALIAS_COST_CENTER[$calon] ?? $calon];
                break;
            }
        }

        return ['akun' => $kode, 'cost_center' => $cc, 'ref' => $ref];
    }

    public static function kelompok(string $akun): string
    {
        return match (true) {
            in_array($akun, [TebakKodeGl::TALANGAN, TebakKodeGl::TERIMA_TALANGAN, TebakKodeGl::SALAH_TRANSFER, 'Pindah Uang Antar Kantong'], true) => 'Non-biaya',
            str_starts_with($akun, 'HPP') => 'HPP',
            str_starts_with($akun, 'Gaji'), in_array($akun, ['Biaya THR', 'Biaya Lembur', 'Biaya BPJS', 'Biaya Komisi'], true) => 'Gaji & Tunjangan',
            str_starts_with($akun, 'Piutang') => 'Piutang',
            str_starts_with($akun, 'Biaya'), str_starts_with($akun, 'Dinas') => 'Biaya',
            default => 'Lainnya',
        };
    }
}
