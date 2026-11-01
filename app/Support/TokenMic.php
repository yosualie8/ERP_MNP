<?php

namespace App\Support;

/**
 * Token sekali-sambung untuk relay Mic ke Laptop: "isi.tanda" (isi = JSON base64url {u, p, e, d}, tanda = HMAC dari APP_KEY).
 * Hanya dipakai saat membuka WebSocket (berlaku singkat); sambungan yang sudah terbuka tetap jalan.
 */
class TokenMic
{
    public const KIRIM = 'kirim';   // HP / tablet yang berbicara

    public const TERIMA = 'terima'; // aplikasi Windows yang memutar ke mikrofon virtual

    public static function buat(int $userId, string $peran, int $detik = 120, ?int $perangkatId = null): string
    {
        $isi = self::b64(json_encode(['u' => $userId, 'p' => $peran, 'e' => time() + $detik, 'd' => $perangkatId]));

        return $isi.'.'.self::tanda($isi);
    }

    /** @return array{u:int,p:string,e:int,d:?int}|null */
    public static function periksa(string $token): ?array
    {
        [$isi, $tanda] = array_pad(explode('.', $token, 2), 2, '');
        if ($isi === '' || $tanda === '' || ! hash_equals(self::tanda($isi), $tanda)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($isi, '-_', '+/')), true);
        if (! is_array($data) || (int) ($data['e'] ?? 0) < time() || ! in_array($data['p'] ?? '', [self::KIRIM, self::TERIMA], true)) {
            return null;
        }

        return $data;
    }

    /** Alamat WebSocket relay untuk klien (wss://domain/mic-relay di server, atau MIC_RELAY_URL lengkap). */
    public static function urlRelay(): string
    {
        $url = (string) config('mnp.mic_relay_url');
        if (str_starts_with($url, '/')) {
            $url = preg_replace('#^http#', 'ws', rtrim((string) config('app.url'), '/')).$url;
        }

        return $url;
    }

    private static function tanda(string $isi): string
    {
        return hash_hmac('sha256', $isi, 'mic|'.config('app.key'));
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
