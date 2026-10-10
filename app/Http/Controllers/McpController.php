<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\User;
use App\Support\Mcp\AlatMnp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * MCP server (Model Context Protocol, transport Streamable HTTP tanpa sesi) untuk ChatGPT: HANYA BACA, di /api/mcp.
 * Akses wajib token OAuth (OAuthMcpController): hanya email yang terdaftar di menu Pengguna yang bisa login & memberi izin.
 * Tool yang tampil mengikuti menu yang boleh dibuka pengguna itu; setiap pemanggilan dicatat atas namanya (aksi "mcp-chatgpt").
 */
class McpController extends Controller
{
    private const VERSI = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private ?User $pengguna = null;

    public function __invoke(Request $request): JsonResponse|Response
    {
        $userId = OAuthMcpController::penggunaDariToken($request->bearerToken());
        $this->pengguna = $userId ? User::find($userId) : null;
        if (! $this->pengguna) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'Login dengan akun ERP MNP yang terdaftar.'], 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="'.OAuthMcpController::urlMetadataResource().'", scope="'.OAuthMcpController::SCOPE.'"'
                    .($request->bearerToken() ? ', error="invalid_token"' : ''),
            ]);
        }
        AlatMnp::$pengguna = $this->pengguna;
        // Tanpa aliran SSE dari server: GET/DELETE tidak didukung (spesifikasi MCP membolehkan 405).
        if (! $request->isMethod('post')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        $pesan = json_decode($request->getContent(), true);
        if (! is_array($pesan)) {
            return response()->json(self::galat(null, -32700, 'Parse error'), 400);
        }
        $batch = array_is_list($pesan);
        $balasan = array_values(array_filter(array_map(fn ($m) => $this->tangani(is_array($m) ? $m : []), $batch ? $pesan : [$pesan])));
        if (! $balasan) {
            return response('', 202); // hanya notifikasi / balasan
        }

        return response()->json($batch ? $balasan : $balasan[0], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function tangani(array $m): ?array
    {
        $id = $m['id'] ?? null;
        $metode = $m['method'] ?? null;
        if ($metode === null || ! array_key_exists('id', $m)) {
            return null; // notifikasi (mis. notifications/initialized) atau balasan dari klien
        }
        $param = (array) ($m['params'] ?? []);

        return match ($metode) {
            'initialize' => self::hasil($id, [
                'protocolVersion' => in_array($param['protocolVersion'] ?? null, self::VERSI, true) ? $param['protocolVersion'] : self::VERSI[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'erp-mnp', 'title' => 'ERP PT Multi Niaga Putra', 'version' => '1.0.0'],
                'instructions' => 'Data ERP PT Multi Niaga Putra (MNP) — HANYA BACA. Kas Harian = rekening Bank Jago PT (transfer + transaksi detail/bon dengan Kode GL; '
                    .'"reimburse" = penggantian dana kas oleh owner). Kas UJ = uang jalan dump truck (lembar Kas Seabank). Pengajuan UJ (PUJ) = permintaan uang jalan '
                    .'sebelum ditransfer. Ritasi = perjalanan dump truck (galian → tujuan buangan). Nominal dalam rupiah; tanggal YYYY-MM-DD. '
                    .'Mulai dari ringkasan_kas_harian / ringkasan_uj untuk gambaran umum.',
            ]),
            'ping' => self::hasil($id, (object) []),
            'tools/list' => self::hasil($id, ['tools' => collect(AlatMnp::untuk($this->pengguna))->map(fn ($t, $nama) => [
                'name' => $nama, 'title' => $t['judul'], 'description' => $t['deskripsi'], 'inputSchema' => $t['skema'],
                'annotations' => ['title' => $t['judul'], 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ])->values()]),
            'tools/call' => $this->panggil($id, (string) ($param['name'] ?? ''), (array) ($param['arguments'] ?? [])),
            'resources/list' => self::hasil($id, ['resources' => []]),
            'prompts/list' => self::hasil($id, ['prompts' => []]),
            default => self::galat($id, -32601, "Method not found: {$metode}"),
        };
    }

    private function panggil(mixed $id, string $nama, array $arg): array
    {
        $alat = AlatMnp::untuk($this->pengguna)[$nama] ?? null;
        if (! $alat) {
            return self::galat($id, -32602, "Tool tidak dikenal atau tidak boleh dipakai akun ini: {$nama}");
        }
        KasRiwayat::create(['aksi' => 'mcp-chatgpt', 'lembar' => 'MCP', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("ChatGPT memakai {$nama}".($arg ? ' '.json_encode($arg, JSON_UNESCAPED_UNICODE) : ''), 0, 490, '…'), 'isi' => ['tool' => $nama, 'argumen' => $arg],
            'user_id' => $this->pengguna->id]);
        try {
            $data = ($alat['jalankan'])($arg);
        } catch (InvalidArgumentException $e) {
            return self::hasil($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        } catch (\Throwable $e) {
            report($e);

            return self::hasil($id, ['content' => [['type' => 'text', 'text' => 'Terjadi kesalahan di server ERP: '.$e->getMessage()]], 'isError' => true]);
        }
        $data = json_decode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        $teks = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($teks) > 400000) {
            $teks = mb_strcut($teks, 0, 400000).' …(dipotong; persempit pencarian atau kecilkan "batas")';

            return self::hasil($id, ['content' => [['type' => 'text', 'text' => $teks]], 'isError' => false]);
        }

        return self::hasil($id, ['content' => [['type' => 'text', 'text' => $teks]], 'structuredContent' => (object) $data, 'isError' => false]);
    }

    private static function hasil(mixed $id, mixed $isi): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $isi];
    }

    private static function galat(mixed $id, int $kode, string $pesan): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $kode, 'message' => $pesan]];
    }
}
