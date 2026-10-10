<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Server otorisasi OAuth 2.1 kecil untuk MCP server ERP MNP (dipakai ChatGPT): metadata (RFC 9728 & RFC 8414), Dynamic Client
 * Registration (RFC 7591) / client_id berupa URL metadata, kode otorisasi dengan PKCE S256, refresh token berputar.
 * Yang boleh memberi izin hanya pengguna yang login ke aplikasi — artinya email yang terdaftar di menu Pengguna.
 */
class OAuthMcpController extends Controller
{
    public const SCOPE = 'erp.baca';

    private const UMUR_AKSES = 3600;           // 1 jam

    private const UMUR_REFRESH = 30 * 86400;   // 30 hari

    public static function resource(): string
    {
        return url('/api/mcp');
    }

    public static function urlMetadataResource(): string
    {
        return url('/.well-known/oauth-protected-resource/api/mcp');
    }

    public function metadataResource(): JsonResponse
    {
        return response()->json([
            'resource' => self::resource(), 'authorization_servers' => [url('/')], 'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'], 'resource_name' => 'ERP PT Multi Niaga Putra',
        ]);
    }

    public function metadataServer(): JsonResponse
    {
        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => route('oauth.izin'),
            'token_endpoint' => route('oauth.token'),
            'registration_endpoint' => route('oauth.daftar'),
            'scopes_supported' => [self::SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'client_id_metadata_document_supported' => true,
        ]);
    }

    /** Dynamic Client Registration: ChatGPT mendaftarkan dirinya (nama + redirect URI). */
    public function daftar(Request $request): JsonResponse
    {
        $uris = array_values(array_filter((array) $request->input('redirect_uris', []), fn ($u) => is_string($u) && self::uriAman($u)));
        if (! $uris) {
            return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris wajib (https).'], 400);
        }
        $metode = in_array($request->input('token_endpoint_auth_method'), ['client_secret_post', 'client_secret_basic'], true) ? $request->input('token_endpoint_auth_method') : 'none';
        $clientId = 'mnp-'.Str::random(32);
        $secret = $metode === 'none' ? null : Str::random(48);
        DB::table('oauth_klien')->insert(['client_id' => $clientId, 'nama' => mb_substr((string) $request->input('client_name', 'Klien MCP'), 0, 200),
            'redirect_uris' => json_encode($uris), 'secret_hash' => $secret ? hash('sha256', $secret) : null, 'metode_auth' => $metode,
            'created_at' => now(), 'updated_at' => now()]);

        return response()->json(array_filter([
            'client_id' => $clientId, 'client_secret' => $secret, 'client_id_issued_at' => now()->timestamp, 'client_secret_expires_at' => $secret ? 0 : null,
            'client_name' => $request->input('client_name'), 'redirect_uris' => $uris, 'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'], 'token_endpoint_auth_method' => $metode, 'scope' => self::SCOPE,
        ], fn ($v) => $v !== null), 201);
    }

    /** Halaman izin: pengguna (sudah login dengan email terdaftar) mengizinkan ChatGPT membaca data atas namanya. */
    public function izin(Request $request): View|RedirectResponse
    {
        $p = $request->query();
        $klien = self::klien((string) ($p['client_id'] ?? ''));
        $redirect = (string) ($p['redirect_uri'] ?? '');
        if (! $klien || ! in_array($redirect, $klien['redirect_uris'], true)) {
            return view('oauth.izin', ['galat' => 'Permintaan tidak sah: aplikasi atau alamat kembali (redirect_uri) tidak dikenal.']);
        }
        if (($p['response_type'] ?? '') !== 'code' || empty($p['code_challenge']) || ($p['code_challenge_method'] ?? 'S256') !== 'S256') {
            return redirect()->away($redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query(['error' => 'invalid_request',
                'error_description' => 'Wajib response_type=code dengan PKCE S256.', 'state' => $p['state'] ?? null]));
        }

        return view('oauth.izin', ['galat' => null, 'klien' => $klien, 'p' => $p]);
    }

    public function putuskan(Request $request): RedirectResponse|View
    {
        $p = $request->validate(['client_id' => 'required|string', 'redirect_uri' => 'required|string', 'code_challenge' => 'required|string|max:128',
            'state' => 'nullable|string|max:500', 'resource' => 'nullable|string|max:300', 'scope' => 'nullable|string|max:100', 'keputusan' => 'required|in:izinkan,tolak']);
        $klien = self::klien($p['client_id']);
        if (! $klien || ! in_array($p['redirect_uri'], $klien['redirect_uris'], true)) {
            return view('oauth.izin', ['galat' => 'Permintaan tidak sah.']);
        }
        $kembali = fn (array $q) => redirect()->away($p['redirect_uri'].(str_contains($p['redirect_uri'], '?') ? '&' : '?').http_build_query(array_filter($q, fn ($v) => $v !== null)));
        if ($p['keputusan'] === 'tolak') {
            return $kembali(['error' => 'access_denied', 'state' => $p['state'] ?? null]);
        }
        $kode = Str::random(64);
        DB::table('oauth_kode')->insert(['kode_hash' => hash('sha256', $kode), 'client_id' => $p['client_id'], 'user_id' => $request->user()->id,
            'redirect_uri' => $p['redirect_uri'], 'code_challenge' => $p['code_challenge'], 'resource' => $p['resource'] ?? null, 'scope' => self::SCOPE,
            'kedaluwarsa' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        KasRiwayat::create(['aksi' => 'mcp-izin', 'lembar' => 'MCP', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("Mengizinkan {$klien['nama']} membaca data ERP lewat MCP", 0, 490, '…'),
            'isi' => ['client_id' => $p['client_id']], 'user_id' => $request->user()->id]);

        return $kembali(['code' => $kode, 'state' => $p['state'] ?? null, 'iss' => url('/')]);
    }

    public function token(Request $request): JsonResponse
    {
        [$clientId, $secret] = self::kredensialKlien($request);
        $klien = self::klien((string) $clientId);
        if (! $klien || ($klien['secret_hash'] && ! hash_equals($klien['secret_hash'], hash('sha256', (string) $secret)))) {
            return self::gagal('invalid_client', 'Klien tidak dikenal atau secret salah.', 401);
        }

        if ($request->input('grant_type') === 'authorization_code') {
            $kode = DB::table('oauth_kode')->where('kode_hash', hash('sha256', (string) $request->input('code')))->first();
            if ($kode) {
                DB::table('oauth_kode')->where('id', $kode->id)->delete(); // sekali pakai
            }
            $verifier = (string) $request->input('code_verifier');
            $tantangan = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (! $kode || now()->gt($kode->kedaluwarsa) || $kode->client_id !== $clientId || $kode->redirect_uri !== $request->input('redirect_uri')
                || ! hash_equals($kode->code_challenge, $tantangan)) {
                return self::gagal('invalid_grant', 'Kode otorisasi tidak sah, kedaluwarsa, atau PKCE tidak cocok.');
            }

            return self::terbitkan($clientId, $kode->user_id);
        }

        if ($request->input('grant_type') === 'refresh_token') {
            $lama = DB::table('oauth_token')->where('token_hash', hash('sha256', (string) $request->input('refresh_token')))->where('jenis', 'refresh')->first();
            if (! $lama || $lama->dicabut || now()->gt($lama->kedaluwarsa) || $lama->client_id !== $clientId) {
                return self::gagal('invalid_grant', 'Refresh token tidak sah atau sudah dicabut.');
            }
            DB::table('oauth_token')->where('id', $lama->id)->update(['dicabut' => now(), 'updated_at' => now()]); // diputar

            return self::terbitkan($clientId, $lama->user_id);
        }

        return self::gagal('unsupported_grant_type', 'grant_type harus authorization_code atau refresh_token.');
    }

    /** Token akses yang sah → id pengguna (email masih terdaftar), atau null. */
    public static function penggunaDariToken(?string $bearer): ?int
    {
        if (! $bearer) {
            return null;
        }
        $t = DB::table('oauth_token')->where('token_hash', hash('sha256', $bearer))->where('jenis', 'akses')->first();
        if (! $t || $t->dicabut || now()->gt($t->kedaluwarsa)) {
            return null;
        }
        if (! $t->terakhir_dipakai || now()->diffInMinutes($t->terakhir_dipakai) >= 5) {
            DB::table('oauth_token')->where('id', $t->id)->update(['terakhir_dipakai' => now()]);
        }

        return (int) $t->user_id;
    }

    private static function terbitkan(string $clientId, int $userId): JsonResponse
    {
        $akses = Str::random(64);
        $refresh = Str::random(64);
        $baris = fn ($token, $jenis, $umur) => ['token_hash' => hash('sha256', $token), 'jenis' => $jenis, 'client_id' => $clientId, 'user_id' => $userId,
            'scope' => self::SCOPE, 'kedaluwarsa' => now()->addSeconds($umur), 'created_at' => now(), 'updated_at' => now()];
        DB::table('oauth_token')->insert([$baris($akses, 'akses', self::UMUR_AKSES), $baris($refresh, 'refresh', self::UMUR_REFRESH)]);

        return response()->json(['access_token' => $akses, 'token_type' => 'Bearer', 'expires_in' => self::UMUR_AKSES,
            'refresh_token' => $refresh, 'scope' => self::SCOPE], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * Klien terdaftar (DCR), atau client_id berupa URL dokumen metadata (Client ID Metadata Document) yang diambil & disimpan.
     *
     * @return array{nama: string, redirect_uris: string[], secret_hash: ?string}|null
     */
    private static function klien(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }
        $k = DB::table('oauth_klien')->where('client_id', $clientId)->first();
        if (! $k && str_starts_with($clientId, 'https://') && strlen($clientId) <= 100) {
            $doc = Cache::remember('oauth-cimd:'.md5($clientId), 3600, fn () => rescue(fn () => Http::timeout(5)->acceptJson()->get($clientId)->json(), null, false));
            $uris = array_values(array_filter((array) ($doc['redirect_uris'] ?? []), fn ($u) => is_string($u) && self::uriAman($u)));
            if (! is_array($doc) || ($doc['client_id'] ?? null) !== $clientId || ! $uris) {
                return null;
            }
            DB::table('oauth_klien')->insert(['client_id' => $clientId, 'nama' => mb_substr((string) ($doc['client_name'] ?? parse_url($clientId, PHP_URL_HOST)), 0, 200),
                'redirect_uris' => json_encode($uris), 'metode_auth' => 'none', 'created_at' => now(), 'updated_at' => now()]);
            $k = DB::table('oauth_klien')->where('client_id', $clientId)->first();
        }

        return $k ? ['nama' => $k->nama ?: 'Klien MCP', 'redirect_uris' => json_decode($k->redirect_uris, true), 'secret_hash' => $k->secret_hash] : null;
    }

    private static function kredensialKlien(Request $request): array
    {
        if (($u = $request->getUser()) !== null) {
            return [urldecode($u), urldecode((string) $request->getPassword())]; // client_secret_basic
        }

        return [$request->input('client_id'), $request->input('client_secret')];
    }

    private static function uriAman(string $u): bool
    {
        return str_starts_with($u, 'https://') || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $u);
    }

    private static function gagal(string $kode, string $pesan, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $kode, 'error_description' => $pesan], $status, ['Cache-Control' => 'no-store']);
    }
}
