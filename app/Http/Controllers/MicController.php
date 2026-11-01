<?php

namespace App\Http\Controllers;

use App\Models\MicPerangkat;
use App\Support\TokenMic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Mic ke Laptop (hanya akun owner config mnp.mic_email, Gate 'mic' — bukan semua Super Admin): HP/tablet membuka halaman ini dan berbicara; suara diteruskan relay
 * (mnp:relay-mic) ke aplikasi Windows "Mic HP" yang memutarnya ke mikrofon virtual (VB-Cable) — dipakai Claude, Zoom, dsb.
 */
class MicController extends Controller
{
    private const MENIT_KODE = 10;

    public function index(Request $request): View
    {
        return view('mic.index', [
            'perangkat' => MicPerangkat::where('user_id', $request->user()->id)->latest('id')->get(),
            'menitKode' => self::MENIT_KODE,
        ]);
    }

    /** Token untuk HP pengirim (dipanggil tiap kali WebSocket dibuka). */
    public function token(Request $request): JsonResponse
    {
        return response()->json(['token' => TokenMic::buat($request->user()->id, TokenMic::KIRIM), 'relay' => TokenMic::urlRelay()]);
    }

    /** Kode 6 angka untuk memasangkan aplikasi Windows (sekali pakai, 10 menit). */
    public function kodePasang(Request $request): JsonResponse
    {
        do {
            $kode = (string) random_int(100000, 999999);
        } while (Cache::has('mic-pasang:'.$kode));
        Cache::put('mic-pasang:'.$kode, $request->user()->id, now()->addMinutes(self::MENIT_KODE));

        return response()->json(['kode' => $kode, 'menit' => self::MENIT_KODE]);
    }

    public function hapus(Request $request, MicPerangkat $perangkat): RedirectResponse
    {
        abort_unless($perangkat->user_id === $request->user()->id, 404);
        $perangkat->delete();

        return redirect()->route('mic.index')->with('status', 'Laptop "'.$perangkat->nama.'" dilepas.');
    }

    // ---- API untuk aplikasi Windows (tanpa sesi login) ----

    /** Tukar kode pasang → kunci perangkat (hanya ditampilkan sekali; disimpan aplikasi di laptop). */
    public function apiPasang(Request $request): JsonResponse
    {
        $kode = preg_replace('/\D/', '', (string) $request->input('kode'));
        $userId = $kode !== '' ? Cache::pull('mic-pasang:'.$kode) : null;
        if (! $userId) {
            return response()->json(['pesan' => 'Kode salah atau sudah kedaluwarsa. Buat kode baru di halaman Mic ke Laptop.'], 422);
        }
        $kunci = Str::random(48);
        MicPerangkat::create([
            'user_id' => $userId,
            'nama' => Str::limit(trim((string) $request->input('nama')) ?: 'Laptop', 60, ''),
            'kunci_hash' => MicPerangkat::hash($kunci),
            'terakhir_aktif' => now(),
        ]);

        return response()->json(['kunci' => $kunci]);
    }

    /** Kunci perangkat → token penerima untuk membuka WebSocket relay. */
    public function apiToken(Request $request): JsonResponse
    {
        $kunci = (string) $request->bearerToken();
        $perangkat = $kunci !== '' ? MicPerangkat::with('user')->where('kunci_hash', MicPerangkat::hash($kunci))->first() : null;
        if (! $perangkat || ! $perangkat->user?->bolehMic()) {
            return response()->json(['pesan' => 'Laptop ini belum / tidak lagi dipasangkan.'], 401);
        }
        $perangkat->forceFill(['terakhir_aktif' => now()])->save();

        return response()->json([
            'token' => TokenMic::buat($perangkat->user_id, TokenMic::TERIMA, 120, $perangkat->id),
            'relay' => TokenMic::urlRelay(),
        ]);
    }
}
