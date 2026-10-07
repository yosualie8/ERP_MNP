<?php

namespace App\Support;

use App\Models\Ritasi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lembar "Ritasi" (spreadsheet "Proyek ASG - Gsheet"): satu baris = satu rit dump truck.
 * Kolom: A No (rumus lama), B Tahap, C No Seri, D Tanggal, E Jam, F Plat Nomor, G No Lambung (DT), H No Polisi ("plat/driver"),
 * I Galian, J Jenis Buangan, K Jenis Tanah, L Jenis Kendaraan, M Nama Pemilik, N Harga Jual, O Keterangan, P Status Bayar (rumus lama),
 * Q No Do; S = rumus pengecek DO dobel =COUNTIF(Q:Q;Qn) (sudah disiapkan jauh di bawah data).
 */
class LembarRitasi
{
    public const LEMBAR = 'Ritasi';

    public static function id(): string
    {
        return config('mnp.sheet_ritasi');
    }

    /** Baris berisi rit bila salah satu kolom B–O terisi (kolom S berisi rumus sampai jauh di bawah data). */
    public static function adaData(array $r): bool
    {
        foreach (array_slice($r, 1, 14) as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Nomor seri jam sheet (pecahan hari) → "14:37". */
    public static function jam(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            $menit = (int) round(fmod((float) $v, 1) * 1440) % 1440;

            return sprintf('%d:%02d', intdiv($menit, 60), $menit % 60);
        }
        $v = trim((string) $v);

        return preg_match('/^(\d{1,2})[:.](\d{2})/', $v, $m) ? ((int) $m[1]).':'.$m[2] : ($v === '' ? null : mb_substr($v, 0, 10));
    }

    /** "B 9231 UIR/Sule" → ["B 9231 UIR", "Sule"]. */
    public static function pecahPolisi(?string $v): array
    {
        $v = trim((string) $v);
        if (! str_contains($v, '/')) {
            return [$v ?: null, null];
        }
        [$plat, $driver] = array_map('trim', explode('/', $v, 2));

        return [$plat ?: null, $driver ?: null];
    }

    /** @return array<int, array> baris DB dari baris sheet mentah (indeks 0 = baris $mulai) */
    public static function urai(array $mentah, int $mulai): array
    {
        $hasil = [];
        foreach ($mentah as $i => $r) {
            if (! self::adaData($r)) {
                continue;
            }
            $sel = fn (int $k) => is_string($r[$k] ?? null) ? trim($r[$k]) : ($r[$k] ?? null);
            $teks = fn (int $k) => ($v = $sel($k)) === null || $v === '' ? null : (string) $v;
            $hasil[] = [
                'baris' => $mulai + $i, 'tahap' => $teks(1) ? preg_replace('/\s+/', ' ', $teks(1)) : null, 'no_seri' => $teks(2),
                'tanggal' => KasSeabank::tanggal($sel(3))?->toDateString(), 'jam' => self::jam($sel(4)),
                'plat' => $teks(5) ?? self::pecahPolisi($teks(7))[0], 'no_lambung' => NomorMobil::rapikan($teks(6)), 'no_polisi' => $teks(7) ? mb_substr($teks(7), 0, 80) : null,
                'galian' => $teks(8), 'jenis_buangan' => $teks(9), 'jenis_tanah' => $teks(10), 'jenis_kendaraan' => NomorMobil::rapikanJenis($teks(11)),
                'pemilik' => $teks(12), 'harga_jual' => KasSeabank::angka($sel(13)), 'keterangan' => $teks(14) ? mb_substr($teks(14), 0, 300) : null,
                'status_bayar' => $teks(15), 'no_do' => $teks(16),
            ];
        }

        return $hasil;
    }

    /** Impor ulang seluruh lembar (rit yang dihapus di sheet ikut hilang); berbagi kunci dengan penulisan dari aplikasi. */
    public static function impor(GoogleSheets $sheets): int
    {
        return Cache::lock('tulis-ritasi-sheet', 120)->block(60, function () use ($sheets) {
            $l = self::LEMBAR;
            $baris = self::urai($sheets->nilaiMentah(self::id(), ["{$l}!A2:Q"])["{$l}!A2:Q"], 2);
            DB::transaction(function () use ($baris) {
                Ritasi::query()->delete();
                $waktu = now();
                foreach (array_chunk($baris, 500) as $potong) {
                    Ritasi::insert(array_map(fn ($r) => [...$r, 'created_at' => $waktu, 'updated_at' => $waktu], $potong));
                }
            });
            Cache::forever('ritasi-akhir', $baris ? max(array_column($baris, 'baris')) : 1);
            Cache::forever('ritasi-diimpor-pada', now()->toIso8601String());

            return count($baris);
        });
    }

    /** Kunci No DO / No Seri untuk dibandingkan ("0039" = "39"). */
    public static function kunciAngka(?string $v): ?string
    {
        $v = preg_replace('/\s+/', '', (string) $v);

        return $v === '' ? null : (ltrim($v, '0') ?: '0');
    }

    public static function tanggalCarbon(?string $v): ?Carbon
    {
        return $v ? Carbon::parse($v) : null;
    }
}
