<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\PengaturanApp;
use App\Models\User;
use App\Support\Ai\OpenAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Tanya AI: chat tentang data ERP (hanya baca) + speech to text. Setiap pertanyaan & jawaban dicatat lengkap per pengguna;
 * Super Admin bisa melihat log semua pengguna dan mengatur API key & model OpenAI.
 */
class TanyaAiController extends Controller
{
    public function index(Request $request): View
    {
        $u = $request->user();
        $semua = $u->isSuperAdmin() && $request->boolean('semua');
        $saringUser = $semua ? ((int) $request->query('pengguna') ?: null) : $u->id;
        $daftar = DB::table('ai_percakapan as p')->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->when($saringUser, fn ($q) => $q->where('p.user_id', $saringUser))
            ->select('p.*', 'u.name as nama_user', 'u.email as email_user',
                DB::raw('(SELECT COUNT(*) FROM ai_pesan m WHERE m.ai_percakapan_id = p.id AND m.peran = "tanya") as jumlah_tanya'))
            ->orderByDesc('p.updated_at')->limit(200)->get();
        $aktif = null;
        if ($id = (int) $request->query('c')) {
            $aktif = DB::table('ai_percakapan')->where('id', $id)->when(! $u->isSuperAdmin(), fn ($q) => $q->where('user_id', $u->id))->first();
        }

        return view('tanya-ai.index', [
            'daftar' => $daftar, 'aktif' => $aktif, 'semua' => $semua, 'saringUser' => $saringUser,
            'pesan' => $aktif ? DB::table('ai_pesan')->where('ai_percakapan_id', $aktif->id)->orderBy('id')->get() : collect(),
            'pemilik' => $aktif ? User::find($aktif->user_id) : null,
            'siap' => (bool) (OpenAi::apiKey() && OpenAi::model()),
            'pengaturan' => $u->isSuperAdmin() ? ['ada_kunci' => (bool) OpenAi::apiKey(), 'model' => OpenAi::model(), 'model_suara' => OpenAi::modelSuara()] : null,
            'pengguna' => $semua ? User::orderBy('name')->get(['id', 'name', 'email']) : collect(),
        ]);
    }

    public function kirim(Request $request): JsonResponse
    {
        @set_time_limit(170);
        $data = $request->validate(['pertanyaan' => ['required', 'string', 'max:4000'], 'percakapan' => ['nullable', 'integer'], 'sumber' => ['nullable', 'in:ketik,suara']]);
        $u = $request->user();
        $p = ($data['percakapan'] ?? null) ? DB::table('ai_percakapan')->where('id', $data['percakapan'])->where('user_id', $u->id)->first() : null;
        $pid = $p?->id ?? DB::table('ai_percakapan')->insertGetId(['user_id' => $u->id, 'judul' => Str::limit($data['pertanyaan'], 80),
            'created_at' => now(), 'updated_at' => now()]);
        $catat = fn (array $isi) => DB::table('ai_pesan')->insertGetId([...$isi, 'ai_percakapan_id' => $pid, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        $catat(['peran' => 'tanya', 'isi' => $data['pertanyaan'], 'sumber' => $data['sumber'] ?? 'ketik']);
        KasRiwayat::create(['aksi' => 'ai-tanya', 'lembar' => 'AI', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth('Tanya AI: '.$data['pertanyaan'], 0, 490, '…'), 'isi' => ['percakapan' => $pid], 'user_id' => $u->id]);

        $mulai = microtime(true);
        try {
            $h = OpenAi::jawab($u, $data['pertanyaan'], $p?->response_id);
        } catch (\Throwable $e) {
            report($e);
            $catat(['peran' => 'galat', 'isi' => $e->getMessage(), 'durasi_ms' => (int) ((microtime(true) - $mulai) * 1000)]);
            DB::table('ai_percakapan')->where('id', $pid)->update(['updated_at' => now()]);

            return response()->json(['percakapan' => $pid, 'galat' => $e->getMessage()], 422);
        }
        $catat(['peran' => 'jawab', 'isi' => $h['jawaban'], 'tool' => json_encode($h['tool'], JSON_UNESCAPED_UNICODE), 'model' => $h['model'],
            'token_masuk' => $h['token_masuk'], 'token_keluar' => $h['token_keluar'], 'durasi_ms' => (int) ((microtime(true) - $mulai) * 1000)]);
        DB::table('ai_percakapan')->where('id', $pid)->update(['response_id' => $h['response_id'], 'updated_at' => now()]);

        return response()->json(['percakapan' => $pid, 'jawaban_html' => self::html($h['jawaban']), 'tool' => $h['tool']]);
    }

    public function transkripsi(Request $request): JsonResponse
    {
        @set_time_limit(90);
        $request->validate(['audio' => ['required', 'file', 'max:20480']], ['audio.max' => 'Rekaman terlalu panjang (maks. 20 MB).']);
        try {
            return response()->json(['teks' => OpenAi::transkripsi($request->file('audio'))]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['galat' => $e->getMessage()], 422);
        }
    }

    /** Pengaturan (Super Admin): API key OpenAI (disimpan terenkripsi) & model. */
    public function pengaturan(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['api_key' => ['nullable', 'string', 'max:300'], 'model' => ['nullable', 'string', 'max:80'], 'model_suara' => ['nullable', 'string', 'max:80']]);
        if (! empty($data['api_key'])) {
            OpenAi::simpanApiKey($data['api_key'], $request->user()->id);
            try {
                $model = OpenAi::daftarModel();
            } catch (\Throwable $e) {
                return back()->with('error', $e->getMessage());
            }
            session()->flash('model_tersedia', $model);
        }
        if (! empty($data['model'])) {
            PengaturanApp::simpan(OpenAi::KUNCI_MODEL, trim($data['model']), $request->user()->id);
        }
        if (! empty($data['model_suara'])) {
            PengaturanApp::simpan(OpenAi::KUNCI_MODEL_SUARA, trim($data['model_suara']), $request->user()->id);
        }

        return back()->with('success', 'Pengaturan Tanya AI disimpan.');
    }

    public function modelTersedia(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        try {
            return response()->json(['model' => OpenAi::daftarModel()]);
        } catch (\Throwable $e) {
            return response()->json(['galat' => $e->getMessage()], 422);
        }
    }

    public static function html(string $md): string
    {
        return Str::markdown($md, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
