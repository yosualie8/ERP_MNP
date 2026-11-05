<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Klien Tuya Cloud OpenAPI (Kendali Rumah). Tanda tangan HMAC-SHA256 versi baru:
 * client_id + [access_token] + t + nonce + (METHOD \n sha256(body) \n \n path?query).
 * Token disimpan di cache sampai hampir kedaluwarsa; dibuang & diminta ulang bila Tuya menolak token.
 */
class Tuya
{
    private const KUNCI_TOKEN = 'tuya:token';

    public static function siap(): bool
    {
        return filled(config('mnp.tuya_client_id')) && filled(config('mnp.tuya_secret'));
    }

    public static function get(string $jalur): mixed
    {
        return self::panggil('GET', $jalur);
    }

    public static function post(string $jalur, array $isi): mixed
    {
        return self::panggil('POST', $jalur, $isi);
    }

    /** Status banyak perangkat sekaligus (1 panggilan API). @return array<string, array<string, mixed>> id → [code => value] */
    public static function statusBanyak(array $ids): array
    {
        $hasil = [];
        foreach (array_chunk($ids, 20) as $potong) {
            foreach ((array) self::get('/v1.0/iot-03/devices/status?device_ids='.implode(',', $potong)) as $p) {
                $hasil[$p['id']] = collect($p['status'] ?? [])->pluck('value', 'code')->all();
            }
        }

        return $hasil;
    }

    private static function panggil(string $metode, string $jalur, ?array $isi = null, bool $ulang = true): mixed
    {
        $token = self::token();
        $badan = $isi === null ? '' : json_encode($isi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $r = Http::withHeaders(self::kepala($metode, $jalur, $badan, $token))
            ->timeout(12)->connectTimeout(5)
            ->withBody($badan, 'application/json')
            ->send($metode, rtrim(config('mnp.tuya_host'), '/').$jalur)
            ->json();

        if (! ($r['success'] ?? false)) {
            // 1010 token tidak sah, 1011 token kedaluwarsa → minta token baru sekali.
            if ($ulang && in_array((int) ($r['code'] ?? 0), [1010, 1011, 1012], true)) {
                Cache::forget(self::KUNCI_TOKEN);

                return self::panggil($metode, $jalur, $isi, false);
            }
            throw new RuntimeException('Tuya: '.($r['msg'] ?? 'tidak ada jawaban').' ('.($r['code'] ?? '-').')');
        }

        return $r['result'] ?? null;
    }

    private static function token(): string
    {
        $simpan = Cache::get(self::KUNCI_TOKEN);
        if ($simpan) {
            return $simpan;
        }
        $jalur = '/v1.0/token?grant_type=1';
        $r = Http::withHeaders(self::kepala('GET', $jalur, '', ''))->timeout(12)->connectTimeout(5)
            ->get(rtrim(config('mnp.tuya_host'), '/').$jalur)->json();
        if (! ($r['success'] ?? false)) {
            throw new RuntimeException('Tuya: gagal login ('.($r['msg'] ?? '-').')');
        }
        $token = $r['result']['access_token'];
        Cache::put(self::KUNCI_TOKEN, $token, max(60, (int) ($r['result']['expire_time'] ?? 7200) - 120));

        return $token;
    }

    private static function kepala(string $metode, string $jalur, string $badan, string $token): array
    {
        $t = (string) (int) round(microtime(true) * 1000);
        $nonce = Str::random(32);
        $teks = config('mnp.tuya_client_id').$token.$t.$nonce.implode("\n", [$metode, hash('sha256', $badan), '', self::jalurUrut($jalur)]);
        $h = [
            'client_id' => config('mnp.tuya_client_id'),
            'sign' => strtoupper(hash_hmac('sha256', $teks, (string) config('mnp.tuya_secret'))),
            't' => $t,
            'nonce' => $nonce,
            'sign_method' => 'HMAC-SHA256',
        ];
        if ($token !== '') {
            $h['access_token'] = $token;
        }

        return $h;
    }

    /** Tuya menandatangani query yang diurutkan menurut nama parameter. */
    private static function jalurUrut(string $jalur): string
    {
        if (! str_contains($jalur, '?')) {
            return $jalur;
        }
        [$p, $q] = explode('?', $jalur, 2);
        $bagian = explode('&', $q);
        sort($bagian);

        return $p.'?'.implode('&', $bagian);
    }
}
