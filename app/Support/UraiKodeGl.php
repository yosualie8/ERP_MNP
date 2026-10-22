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
        // Kas Harian 2024–2025 (konfirmasi user 12 Okt 2026): nama lokasi/tahap lama → kode baku.
        '/ ASG (?:D29|D32|Ecopark) (T\d+)$/' => ' ASG $1',
        '/ ASG D32$/' => ' ASG T1',
        '/ ASG Ecopark$/' => ' ASG T2',
        '/ (ASG T\d+) (?:V2|Mako Brimob)$/' => ' $1',
        '/ (?:Jakarta|Jkt)$/' => ' JKT',
        '/ buhut$/' => ' Buhut',
        '/ bajoog$/' => ' Bajoog',
    ];

    /** Cost center di akhir kode, dicocokkan dari yang terpanjang. */
    public const COST_CENTER = ['PM Infra Marina', 'Infra PM', 'DT Buhut', 'Infra', 'PM', 'ASG', 'JKT', 'Buhut', 'DPN', 'Kendal', 'AB',
        // Lokasi lama di Kas Harian 2024–2025, masing-masing cost center sendiri (keputusan user 12 Okt 2026).
        'Site 21', 'UPH', 'DT', 'PPA', 'BIS', 'Bajoog', 'Workshop', 'Petrosea', 'Semarang', 'Sampit', 'KBU'];

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
        'UPH' => 'Lokasi lama (2024)', 'DT' => 'Dump truck (2024–2025)', 'PPA' => 'Lokasi lama PPA (2024–2025)', 'BIS' => 'Lokasi lama BIS (2025)',
        'Bajoog' => 'Lokasi lama Bajoog (2024)', 'Workshop' => 'Workshop (2025)', 'Petrosea' => 'Lokasi lama Petrosea (2024)',
        'Semarang' => 'Lokasi lama Semarang (2024)', 'Sampit' => 'Lokasi lama Sampit (2024)', 'Site 21' => 'Site 21 (2024)', 'KBU' => 'KBU (2024)',
    ];

    private static ?array $calon = null;

    /**
     * Cost center yang dikenali di akhir Kode GL: daftar tetap di atas, ditambah cost center baru di master (dibuat lewat
     * Daftar Proyek "+ Cost center baru…", mis. "Subang"), yang terpanjang dulu.
     *
     * @return string[]
     */
    public static function calonCostCenter(): array
    {
        if (self::$calon === null) {
            $tambahan = rescue(fn () => \App\Models\CostCenter::pluck('kode')->all(), [], false);
            $tambahan = array_values(array_diff($tambahan, self::COST_CENTER, array_values(self::ALIAS_COST_CENTER)));
            usort($tambahan, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            self::$calon = [...self::COST_CENTER, ...$tambahan];
        }

        return self::$calon;
    }

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
        foreach (self::calonCostCenter() as $calon) {
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
