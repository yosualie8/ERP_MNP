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

    /** Indeks kolom X (Kubikasi) di lembar Ritasi. */
    public const KOLOM_KUBIKASI = 23;

    /** Tahap yang Harga Jual-nya per m³ (owner 30 Okt 2026: Kendal & Jogja = kubikasi × Rp41.000), huruf kecil. */
    public const HARGA_PER_M3 = ['proyek industri kendal' => 41000, 'kik' => 41000, 'jogja' => 41000];

    /** Nama tahap yang disatukan saat input / upload (owner 31 Okt 2026: Tujuan Bongkar "KIK" = "Proyek Industri Kendal"), kunci huruf kecil. */
    public const TAHAP_SAMA = ['kik' => 'Proyek Industri Kendal'];

    /**
     * Tahap yang No Seri-nya boleh sama dengan rit PROYEK LAIN → otomatis diberi akhiran nama proyek (owner 31 Okt 2026: nomor tiket
     * loadscanner Kendal bisa sama dengan Jogja). Kunci = tahap huruf kecil, nilai = akhiran.
     */
    public const SERI_BERAKHIRAN = ['proyek industri kendal' => 'Kendal'];

    public static function tahapBaku(?string $tahap): ?string
    {
        return $tahap === null ? null : (self::TAHAP_SAMA[mb_strtolower(trim($tahap))] ?? $tahap);
    }

    /**
     * No Seri rit tahap SERI_BERAKHIRAN yang sudah dipakai rit PROYEK LAIN (di data Ritasi, atau rit lain di input yang sama) diberi
     * akhiran nama proyek: LS-2610-1489 → LS-2610-1489-Kendal (bentrok lagi → -Kendal-2, dst.). Dobel dengan rit proyek yang SAMA
     * tidak diubah — tetap ditolak validasi, karena biasanya tiket cetak ulang (rit yang sama tercatat dua kali).
     *
     * @param  array<int, array>  $rit
     * @return array{0: array<int, array>, 1: array<int, array{0: string, 1: string}>} rit & [indeks => [No Seri asal, No Seri baru]]
     */
    public static function akhiranSeri(array $rit): array
    {
        $tahapKunci = fn ($t) => mb_strtolower(trim((string) self::tahapBaku($t)));
        $akhiran = fn ($r) => self::kunciAngka($r['no_seri'] ?? null) ? (self::SERI_BERAKHIRAN[$tahapKunci($r['tahap'] ?? null)] ?? null) : null;
        $dasar = collect($rit)->filter($akhiran)->pluck('no_seri')->map(fn ($s) => trim((string) $s))->unique()->values();
        if ($dasar->isEmpty()) {
            return [$rit, []];
        }
        // No Seri terpakai (kunci → tahap-tahapnya), termasuk yang sudah berakhiran.
        $dipakai = [];
        foreach (\App\Models\Ritasi::where(function ($q) use ($dasar) {
            foreach ($dasar as $d) {
                $q->orWhere('no_seri', $d)->orWhere('no_seri', 'like', str_replace(['%', '_'], ['\%', '\_'], $d).'-%');
            }
        })->get(['no_seri', 'tahap']) as $x) {
            $dipakai[self::kunciAngka($x->no_seri)][] = $tahapKunci($x->tahap);
        }
        $ubah = [];
        foreach ($rit as $i => $r) {
            $k = self::kunciAngka($r['no_seri'] ?? null);
            $label = $akhiran($r);
            $lain = array_diff($dipakai[$k] ?? [], [$tahapKunci($r['tahap'] ?? null)]);
            // Hanya bila bentroknya dengan proyek lain dan tidak dengan proyek yang sama.
            if ($k && $label && $lain && count($lain) === count($dipakai[$k])) {
                $asal = trim((string) $r['no_seri']);
                $baru = "{$asal}-{$label}";
                for ($n = 2; isset($dipakai[self::kunciAngka($baru)]); $n++) {
                    $baru = "{$asal}-{$label}-{$n}";
                }
                $rit[$i]['no_seri'] = $baru;
                $ubah[$i] = [$asal, $baru];
                $k = self::kunciAngka($baru);
            }
            if ($k) {
                $dipakai[$k][] = $tahapKunci($r['tahap'] ?? null);
            }
        }

        return [$rit, $ubah];
    }

    /** Harga Jual rit untuk tahap ber-harga per m³; null bila tahapnya per rit atau kubikasi belum ada. */
    public static function hargaPerM3(?string $tahap, ?float $kubikasi): ?int
    {
        $tarif = self::HARGA_PER_M3[mb_strtolower(trim((string) $tahap))] ?? null;

        return $tarif && $kubikasi > 0 ? (int) round($kubikasi * $tarif) : null;
    }

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
            // Teks dipotong ke lebar kolom database (sel berisi catatan panjang tetap terbaca, tidak menggagalkan impor).
            $teks = fn (int $k) => ($v = $sel($k)) === null || $v === '' ? null : self::potong((string) $v);
            $hasil[] = [
                'baris' => $mulai + $i, 'tahap' => $teks(1) ? preg_replace('/\s+/', ' ', $teks(1)) : null, 'no_seri' => $teks(2),
                'tanggal' => KasSeabank::tanggal($sel(3))?->toDateString(), 'jam' => self::jam($sel(4)),
                'plat' => $teks(5) ?? self::pecahPolisi($teks(7))[0], 'no_lambung' => NomorMobil::rapikan($teks(6)), 'no_polisi' => $teks(7) ? mb_substr($teks(7), 0, 80) : null,
                'galian' => $teks(8), 'jenis_buangan' => $teks(9), 'jenis_tanah' => $teks(10), 'jenis_kendaraan' => NomorMobil::rapikanJenis($teks(11)),
                'pemilik' => $teks(12), 'harga_jual' => KasSeabank::angka($sel(13)), 'keterangan' => $teks(14) ? mb_substr($teks(14), 0, 300) : null,
                'status_bayar' => $teks(15), 'no_do' => $teks(16),
                // Kolom X = Kubikasi (m³); kolom R–W dipakai rumus/pivot di sheet.
                'kubikasi' => is_numeric($k = str_replace(',', '.', (string) $sel(self::KOLOM_KUBIKASI))) ? round((float) $k, 2) : null,
            ];
        }

        return $hasil;
    }

    /** Impor ulang seluruh lembar (rit yang dihapus di sheet ikut hilang); berbagi kunci dengan penulisan dari aplikasi. */
    public static function impor(GoogleSheets $sheets): int
    {
        return Cache::lock('tulis-ritasi-sheet', 120)->block(60, function () use ($sheets) {
            $l = self::LEMBAR;
            $baris = self::urai($sheets->nilaiMentah(self::id(), ["{$l}!A2:X"])["{$l}!A2:X"], 2);
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

    public static function potong(?string $v, int $maks = 100): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $maks);
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
