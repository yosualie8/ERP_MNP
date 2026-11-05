<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Kendali Rumah: daftar perangkat Tuya diringkas jadi bentuk sederhana untuk halaman /rumah
 * (lampu, stop kontak, finger bot, gateway) + penerjemah aksi halaman → perintah Tuya.
 * Hemat kuota API: daftar perangkat di-cache 60 dtk, status 1 dtk.
 */
class Rumah
{
    /** PengaturanApp: tanggal habis langganan Tuya IoT Core (Y-m-d). */
    public const KUNCI_HABIS = 'tuya_habis';

    /** Pengingat muncul mulai sekian hari sebelum habis. */
    public const HARI_INGATKAN = 30;

    /** @return array{tanggal: ?\Carbon\Carbon, sisa_hari: ?int, ingatkan: bool} */
    public static function masaBerlaku(): array
    {
        $t = \App\Models\PengaturanApp::ambil(self::KUNCI_HABIS);
        $tanggal = $t ? \Carbon\Carbon::parse($t)->startOfDay() : null;
        $sisa = $tanggal ? (int) now()->startOfDay()->diffInDays($tanggal, false) : null;

        return ['tanggal' => $tanggal, 'sisa_hari' => $sisa, 'ingatkan' => $sisa === null || $sisa <= self::HARI_INGATKAN];
    }

    /** @return array<int, array<string, mixed>> */
    public static function perangkat(bool $segarkan = false): array
    {
        if ($segarkan) {
            Cache::forget('rumah:daftar');
            Cache::forget('rumah:status');
        }
        $daftar = Cache::remember('rumah:daftar', 60, function () {
            // Tuya membatasi 20 per halaman; lanjutkan dengan last_id sampai habis.
            $semua = [];
            $lastId = '';
            for ($i = 0; $i < 10; $i++) {
                $hal = (array) Tuya::get('/v2.0/cloud/thing/device?page_size=20'.($lastId !== '' ? '&last_id='.$lastId : ''));
                $semua = array_merge($semua, $hal);
                if (count($hal) < 20) {
                    break;
                }
                $lastId = (string) end($hal)['id'];
            }

            return $semua;
        });
        $ids = array_column($daftar, 'id');
        $status = Cache::remember('rumah:status', 1, fn () => $ids ? Tuya::statusBanyak($ids) : []);

        $hasil = [];
        foreach ($daftar as $p) {
            $hasil[] = self::ringkas($p, $status[$p['id']] ?? []);
        }
        // Urutan tampil: lampu, stop kontak, finger bot, lain-lain, gateway paling akhir; lalu menurut nama.
        $bobot = ['lampu' => 1, 'stopkontak' => 2, 'fingerbot' => 3, 'lain' => 4, 'gateway' => 5];
        usort($hasil, fn ($a, $b) => [$bobot[$a['jenis']], $a['nama']] <=> [$bobot[$b['jenis']], $b['nama']]);

        return $hasil;
    }

    /** Jalankan aksi dari halaman pada satu perangkat. */
    public static function aksi(string $id, string $aksi, mixed $nilai): void
    {
        $p = collect(self::perangkat())->firstWhere('id', $id);
        if (! $p) {
            throw new InvalidArgumentException('Perangkat tidak ditemukan.');
        }
        $perintah = match ($p['jenis']) {
            'lampu' => self::perintahLampu($p, $aksi, $nilai),
            'stopkontak', 'fingerbot', 'lain' => $aksi === 'nyala' && $p['kode_saklar']
                ? [[$p['kode_saklar'], (bool) $nilai]]
                : throw new InvalidArgumentException('Aksi tidak didukung perangkat ini.'),
            default => throw new InvalidArgumentException('Perangkat ini tidak bisa dikendalikan.'),
        };
        self::kirim($id, $perintah);
    }

    /** Nyalakan / matikan semua lampu. @return int jumlah lampu yang diperintah */
    public static function semuaLampu(bool $nyala): int
    {
        $n = 0;
        foreach (self::perangkat() as $p) {
            if ($p['jenis'] === 'lampu' && $p['online']) {
                self::kirim($p['id'], [['switch_led', $nyala]]);
                $n++;
            }
        }

        return $n;
    }

    /** @param array<int, array{0:string,1:mixed}> $perintah */
    private static function kirim(string $id, array $perintah): void
    {
        Tuya::post("/v1.0/iot-03/devices/{$id}/commands", [
            'commands' => array_map(fn ($c) => ['code' => $c[0], 'value' => $c[1]], $perintah),
        ]);
        Cache::forget('rumah:status');
    }

    private static function perintahLampu(array $p, string $aksi, mixed $nilai): array
    {
        $persen = fn ($v) => max(1, min(100, (int) $v));

        return match ($aksi) {
            'nyala' => [['switch_led', (bool) $nilai]],
            // Mode warna: terang ikut nilai V warna; mode putih: bright_value_v2 (10–1000).
            'terang' => $p['mode'] === 'warna' && $p['warna']
                ? [['switch_led', true], ['colour_data_v2', ['h' => $p['warna']['h'], 's' => $p['warna']['s'] * 10, 'v' => $persen($nilai) * 10]]]
                : [['switch_led', true], ['work_mode', 'white'], ['bright_value_v2', max(10, $persen($nilai) * 10)]],
            'suhu' => [['switch_led', true], ['work_mode', 'white'], ['temp_value_v2', max(0, min(100, (int) $nilai)) * 10]],
            'putih' => [['switch_led', true], ['work_mode', 'white']],
            'warna' => [['switch_led', true], ['work_mode', 'colour'], ['colour_data_v2', [
                'h' => max(0, min(360, (int) ($nilai['h'] ?? 0))),
                's' => max(0, min(100, (int) ($nilai['s'] ?? 100))) * 10,
                'v' => max(10, $p['terang'] * 10),
            ]]],
            default => throw new InvalidArgumentException('Aksi lampu tidak dikenal.'),
        };
    }

    private static function ringkas(array $p, array $s): array
    {
        $kat = $p['category'] ?? '';
        $jenis = match (true) {
            in_array($kat, ['dj', 'dd', 'fwd', 'xdd', 'dc', 'tgq', 'tgkg'], true) && array_key_exists('switch_led', $s) => 'lampu',
            in_array($kat, ['cz', 'pc'], true) => 'stopkontak',
            $kat === 'szjqr' || str_contains(strtolower(($p['customName'] ?? '').' '.($p['name'] ?? '')), 'finger') => 'fingerbot',
            in_array($kat, ['wg2', 'wg', 'wfcon'], true) => 'gateway',
            default => 'lain',
        };
        $kodeSaklar = collect(['switch_1', 'switch', 'switch_led'])->first(fn ($k) => array_key_exists($k, $s));

        $r = [
            'id' => $p['id'],
            'nama' => trim((string) ($p['customName'] ?? '') ?: (string) ($p['name'] ?? 'Perangkat')),
            'jenis' => $jenis,
            'online' => (bool) ($p['isOnline'] ?? false),
            'nyala' => $kodeSaklar ? (bool) $s[$kodeSaklar] : null,
            'kode_saklar' => $kodeSaklar,
        ];

        if ($jenis === 'lampu') {
            $warna = json_decode((string) ($s['colour_data_v2'] ?? ''), true);
            $r['mode'] = ($s['work_mode'] ?? 'white') === 'colour' ? 'warna' : 'putih';
            $r['warna'] = is_array($warna) ? ['h' => (int) $warna['h'], 's' => (int) round($warna['s'] / 10)] : null;
            $r['terang'] = $r['mode'] === 'warna' && is_array($warna)
                ? max(1, (int) round($warna['v'] / 10))
                : max(1, (int) round(((int) ($s['bright_value_v2'] ?? 1000)) / 10));
            $r['suhu'] = (int) round(((int) ($s['temp_value_v2'] ?? 0)) / 10);
        }
        if ($jenis === 'stopkontak') {
            // Skala standar Tuya stop kontak: daya ÷10 W, tegangan ÷10 V, arus mA, energi ÷1000 kWh.
            $r['daya'] = isset($s['cur_power']) ? round($s['cur_power'] / 10, 1) : null;
            $r['tegangan'] = isset($s['cur_voltage']) ? round($s['cur_voltage'] / 10, 1) : null;
            $r['arus'] = $s['cur_current'] ?? null;
        }

        return $r;
    }
}
