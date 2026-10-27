<?php

namespace App\Support;

use App\Models\PengaturanApp;

/**
 * Potongan reimburse Kas UJ (owner 27 Okt 2026): kelebihan yang sudah terlanjur ditransfer Kas Harian ke Kas UJ (mis. UJ-14687
 * dobel input, ikut RUJ-0025 lalu dihapus) dikurangkan dari reimburse Kas UJ berikutnya — di Input Kas muncul satu detail
 * bernominal minus, jadi nominal transfer = jumlah UJ − potongan. Disimpan di pengaturan_app sebagai daftar JSON.
 */
class PotonganReimburseUj
{
    public const KUNCI = 'potongan_reimburse_uj';

    /** @return array<int, array{id: int, nominal: int, keterangan: string, dibuat: string, no_id: ?int, reimburse: ?string, dipakai: ?string}> */
    public static function semua(): array
    {
        return json_decode(PengaturanApp::ambil(self::KUNCI, '[]'), true) ?: [];
    }

    /** Potongan yang belum dipakai di Kas Harian. */
    public static function belum(): array
    {
        return array_values(array_filter(self::semua(), fn ($p) => empty($p['no_id'])));
    }

    public static function tambah(int $nominal, string $keterangan, ?int $userId = null): array
    {
        $semua = self::semua();
        $p = ['id' => (int) collect($semua)->max('id') + 1, 'nominal' => $nominal, 'keterangan' => $keterangan,
            'dibuat' => now()->toDateTimeString(), 'no_id' => null, 'reimburse' => null, 'dipakai' => null];
        PengaturanApp::simpan(self::KUNCI, json_encode([...$semua, $p], JSON_UNESCAPED_UNICODE), $userId);

        return $p;
    }

    /** Kunci baris form ("potongan:1") → potongan yang belum dipakai, atau null. */
    public static function dariKunci(?string $kunci): ?array
    {
        if (! preg_match('/^potongan:(\d+)$/', (string) $kunci, $m)) {
            return null;
        }

        return collect(self::belum())->firstWhere('id', (int) $m[1]);
    }

    /** Detail form Input Kas untuk tiap potongan yang belum dipakai (nominal minus). */
    public static function untukForm(?string $pic, ?string $kodeGl): array
    {
        return array_map(fn ($p) => [
            'uj' => 'potongan:'.$p['id'], 'potongan' => true, 'id_uj' => null, 'tanggal_uj' => null, 'nama' => null, 'kategori' => 'Potongan',
            'no_mobil' => null, 'no_do' => null, 'galian' => null, 'nominal' => -$p['nominal'], 'pic' => $pic, 'keterangan' => $p['keterangan'], 'kode_gl' => $kodeGl,
        ], self::belum());
    }

    /** Sesudah ditulis ke Kas Harian: potongan ditandai terpakai di NO ID itu. */
    public static function tandaiDipakai(int $id, int $noId, string $reimburse, ?int $userId): void
    {
        $semua = array_map(fn ($p) => $p['id'] === $id ? [...$p, 'no_id' => $noId, 'reimburse' => $reimburse, 'dipakai' => now()->toDateTimeString()] : $p, self::semua());
        PengaturanApp::simpan(self::KUNCI, json_encode($semua, JSON_UNESCAPED_UNICODE), $userId);
    }
}
