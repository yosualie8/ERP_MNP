<?php

namespace App\Support\Ai;

use App\Models\AsetTruk;
use App\Models\KasBon;
use App\Models\PengaturanApp;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\User;
use App\Support\Mcp\AlatMnp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tanya AI lewat API OpenAI: menjawab pertanyaan dengan tool data ERP yang sama dengan MCP server (AlatMnp, HANYA BACA,
 * mengikuti menu pengguna) dan speech to text (transkripsi) dengan kosakata khusus MNP. API key disimpan terenkripsi.
 */
class OpenAi
{
    public const KUNCI_API = 'openai_api_key';

    public const KUNCI_MODEL = 'openai_model';

    public const KUNCI_MODEL_SUARA = 'openai_model_transkripsi';

    public const MODEL_SUARA = 'gpt-4o-transcribe';

    private const MAKS_PUTARAN = 8;

    public static function apiKey(): ?string
    {
        $v = PengaturanApp::ambil(self::KUNCI_API);

        return $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;
    }

    public static function simpanApiKey(string $kunci, int $userId): void
    {
        PengaturanApp::simpan(self::KUNCI_API, Crypt::encryptString(trim($kunci)), $userId);
    }

    public static function model(): ?string
    {
        return PengaturanApp::ambil(self::KUNCI_MODEL);
    }

    public static function modelSuara(): string
    {
        return PengaturanApp::ambil(self::KUNCI_MODEL_SUARA) ?: self::MODEL_SUARA;
    }

    private static function http()
    {
        $kunci = self::apiKey() ?? throw new RuntimeException('API key OpenAI belum diisi (Super Admin: menu Tanya AI → Pengaturan).');

        return Http::withToken($kunci)->baseUrl('https://api.openai.com/v1')->timeout(150)->connectTimeout(10);
    }

    private const BUKAN_PENJAWAB = '/transcribe|whisper|tts|embedding|dall-e|image|moderation|realtime|audio|search|sora|babbage|davinci|instruct/';

    /** Model untuk menjawab (bukan model suara/gambar/embedding). */
    public static function modelPenjawab(string $m): bool
    {
        return ! preg_match(self::BUKAN_PENJAWAB, $m);
    }

    public static function modelTranskripsi(string $m): bool
    {
        return (bool) preg_match('/transcribe|whisper/', $m);
    }

    /** Coba model dengan satu permintaan kecil; lempar galat bila OpenAI menolak. */
    public static function ujiModel(string $model): void
    {
        $r = self::http()->post('/responses', ['model' => $model, 'input' => 'Balas: OK', 'max_output_tokens' => 64, 'store' => false]);
        if ($r->failed()) {
            throw new RuntimeException("Model {$model} tidak bisa dipakai: ".($r->json('error.message') ?? 'HTTP '.$r->status()));
        }
    }

    /** Model yang tersedia untuk API key ini (untuk pilihan di pengaturan). @return string[] */
    public static function daftarModel(): array
    {
        $r = self::http()->get('/models');
        if ($r->failed()) {
            throw new RuntimeException('OpenAI menolak API key: '.($r->json('error.message') ?? $r->status()));
        }

        return collect($r->json('data'))->pluck('id')->sort()->values()->all();
    }

    /**
     * Jawab satu pertanyaan. Percakapan dilanjutkan lewat previous_response_id (riwayat disimpan OpenAI); tool data ERP
     * dijalankan di sini atas nama pengguna lalu hasilnya dikirim balik sampai AI memberi jawaban akhir.
     *
     * @return array{jawaban: string, response_id: string, tool: array, token_masuk: int, token_keluar: int, model: string}
     */
    public static function jawab(User $pengguna, string $pertanyaan, ?string $responseSebelum): array
    {
        $model = self::model() ?? throw new RuntimeException('Model AI belum dipilih (Super Admin: menu Tanya AI → Pengaturan).');
        AlatMnp::$pengguna = $pengguna;
        $alat = AlatMnp::untuk($pengguna);
        unset($alat['search'], $alat['fetch']); // khusus konektor ChatGPT
        $tools = collect($alat)->map(fn ($t, $nama) => ['type' => 'function', 'name' => $nama, 'description' => $t['deskripsi'], 'parameters' => $t['skema']])->values()->all();

        $instruksi = self::instruksi($pengguna);
        $input = [['role' => 'user', 'content' => $pertanyaan]];
        $sebelum = $responseSebelum;
        $catatanTool = [];
        $masuk = $keluar = 0;
        for ($i = 0; $i < self::MAKS_PUTARAN; $i++) {
            $r = self::http()->post('/responses', array_filter(['model' => $model, 'instructions' => $instruksi, 'input' => $input, 'tools' => $tools,
                'previous_response_id' => $sebelum, 'store' => true], fn ($v) => $v !== null));
            if ($r->failed()) {
                // Riwayat lama sudah tidak ada di OpenAI → mulai tanpa riwayat.
                if ($sebelum && $r->status() === 404 && $i === 0) {
                    $sebelum = null;
                    $i--;

                    continue;
                }
                throw new RuntimeException('OpenAI: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
            }
            $masuk += (int) $r->json('usage.input_tokens');
            $keluar += (int) $r->json('usage.output_tokens');
            $sebelum = $r->json('id');
            $panggilan = collect($r->json('output'))->where('type', 'function_call');
            if ($panggilan->isEmpty()) {
                $teks = collect($r->json('output'))->where('type', 'message')->flatMap(fn ($m) => $m['content'] ?? [])
                    ->where('type', 'output_text')->pluck('text')->implode("\n\n");

                return ['jawaban' => trim($teks) ?: '(AI tidak memberi jawaban teks.)', 'response_id' => $sebelum, 'tool' => $catatanTool,
                    'token_masuk' => $masuk, 'token_keluar' => $keluar, 'model' => $model];
            }
            $input = [];
            foreach ($panggilan as $p) {
                $arg = json_decode($p['arguments'] ?? '{}', true) ?: [];
                try {
                    $hasil = isset($alat[$p['name']]) ? json_encode(($alat[$p['name']]['jalankan'])($arg), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
                        : json_encode(['galat' => 'Tool tidak boleh dipakai akun ini.']);
                } catch (\InvalidArgumentException $e) {
                    $hasil = json_encode(['galat' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
                if (strlen($hasil) > 120000) {
                    $hasil = mb_strcut($hasil, 0, 120000).' …(dipotong; persempit pencarian)';
                }
                $catatanTool[] = ['nama' => $p['name'], 'argumen' => $arg, 'ukuran' => strlen($hasil)];
                $input[] = ['type' => 'function_call_output', 'call_id' => $p['call_id'], 'output' => $hasil];
            }
        }

        throw new RuntimeException('AI terlalu banyak memanggil data tanpa menjawab; coba pertanyaan yang lebih spesifik.');
    }

    /** Suara → teks. Kosakata khusus MNP (nomor truk, nama driver/PIC, galian, istilah) dikirim sebagai prompt. */
    public static function transkripsi(UploadedFile $audio): string
    {
        $r = self::http()->attach('file', fopen($audio->getRealPath(), 'r'), 'suara.'.($audio->getClientOriginalExtension() ?: 'webm'))
            ->post('/audio/transcriptions', ['model' => self::modelSuara(), 'language' => 'id', 'prompt' => self::kosakata(), 'response_format' => 'json']);
        if ($r->failed()) {
            throw new RuntimeException('Transkripsi gagal: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }

        return trim((string) $r->json('text'));
    }

    private static function kosakata(): string
    {
        return Cache::remember('ai-kosakata', now()->addHours(6), function () {
            $truk = AsetTruk::orderBy('no_lambung')->pluck('no_lambung')->take(40)->implode(', ');
            $driver = UjDetail::where('tanggal', '>=', now()->subDays(60))->whereNotNull('nama')->selectRaw('nama, COUNT(*) n')->groupBy('nama')
                ->orderByDesc('n')->limit(30)->pluck('nama')->implode(', ');
            $pic = KasBon::where('tanggal', '>=', now()->subDays(90))->whereNotNull('pic')->selectRaw('pic, COUNT(*) n')->groupBy('pic')
                ->orderByDesc('n')->limit(20)->pluck('pic')->implode(', ');
            $galian = Ritasi::whereNotNull('galian')->selectRaw('galian, COUNT(*) n')->groupBy('galian')->orderByDesc('n')->limit(12)->pluck('galian')->implode(', ');

            return mb_substr('ERP PT Multi Niaga Putra (MNP). Istilah: Kas Harian, Kas UJ, uang jalan, reimburse, pengajuan UJ (PUJ), Kode GL, cost center, '
                ."ritasi, galian, tujuan buangan, No DO, dump truck. Nomor truk: {$truk}. Driver: {$driver}. PIC: {$pic}. Galian: {$galian}.", 0, 900);
        });
    }

    private static function instruksi(User $u): string
    {
        return 'Anda asisten data ERP PT Multi Niaga Putra (MNP). Pengguna: '.($u->name ?? $u->email).'. Hari ini '.now()->translatedFormat('l, j F Y').".\n"
            .'Jawab dalam Bahasa Indonesia yang ringkas dan jelas. Ambil angka HANYA dari tool data ERP (hanya baca) — jangan menebak; bila data tidak ada, katakan. '
            .'Tulis rupiah seperti Rp 1.234.567 dan tanggal seperti 9 Okt 2026. Pakai tabel Markdown untuk daftar/rekap. Sebutkan periode/saringan yang dipakai. '
            .'Anda tidak bisa mengubah data; arahkan pengguna ke menu aplikasi bila perlu mengubah.'."\n"
            .'Konteks: Kas Harian = rekening Bank Jago PT (transfer + transaksi detail/bon ber-Kode GL); "reimburse" = penggantian dana kas oleh owner. '
            .'Kas UJ = uang jalan dump truck (lembar Kas Seabank). Pengajuan UJ (PUJ) = permintaan uang jalan sebelum ditransfer. '
            .'Ritasi = perjalanan dump truck dari galian ke tujuan buangan. No Mobil truk ditulis "DT 068".';
    }
}
