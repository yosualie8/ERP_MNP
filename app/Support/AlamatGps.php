<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Nama tempat dari titik GPS (reverse geocoding OpenStreetMap / Nominatim, gratis) untuk kiriman Lapor Lapangan yang tidak
 * berada di lokasi proyek mana pun (owner 19 Okt 2026). Hasil disimpan 30 hari per titik ±11 m supaya tidak dipanggil berulang
 * (kebijakan Nominatim: maks. 1 permintaan/detik, wajib User-Agent). Gagal / lambat → null (kiriman tetap jalan).
 */
class AlamatGps
{
    public static function dari(?float $lat, ?float $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        $kunci = sprintf('alamat-gps:%.4f,%.4f', $lat, $lng);
        if (($ada = Cache::get($kunci)) !== null) {
            return $ada ?: null;
        }
        $alamat = rescue(function () use ($lat, $lng) {
            $r = Http::withHeaders(['User-Agent' => 'MNP-ERP/1.0 (ptmultiniagaputra@gmail.com)'])->timeout(5)->connectTimeout(3)
                ->get('https://nominatim.openstreetmap.org/reverse', ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 17, 'addressdetails' => 1, 'accept-language' => 'id']);
            if ($r->failed()) {
                return null;
            }
            $a = (array) $r->json('address', []);
            $bagian = array_filter([
                $a['road'] ?? $a['pedestrian'] ?? $a['industrial'] ?? $a['commercial'] ?? $a['amenity'] ?? null,
                $a['village'] ?? $a['suburb'] ?? $a['hamlet'] ?? $a['neighbourhood'] ?? $a['quarter'] ?? null,
                $a['city_district'] ?? $a['district'] ?? $a['subdistrict'] ?? null,
                $a['city'] ?? $a['town'] ?? $a['municipality'] ?? $a['county'] ?? $a['regency'] ?? null,
            ]);

            return $bagian ? implode(', ', array_unique($bagian)) : ($r->json('display_name') ? mb_strimwidth((string) $r->json('display_name'), 0, 200, '…') : null);
        }, null, false);
        // gagal pun disimpan sebentar supaya tidak membanjiri layanan
        Cache::put($kunci, $alamat ?? '', $alamat ? now()->addDays(30) : now()->addMinutes(10));

        return $alamat;
    }
}
