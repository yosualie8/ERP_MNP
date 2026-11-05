<?php

namespace App\Http\Controllers;

use App\Support\Rumah;
use App\Support\Tuya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

/**
 * Kendali Rumah (Gate 'rumah', config mnp.rumah_emails): satu halaman layar penuh untuk iPhone & tablet
 * (bisa dipasang ke layar utama) yang mengendalikan perangkat Smart Life/Tuya lewat Tuya Cloud API.
 */
class RumahController extends Controller
{
    public function index(): View
    {
        return view('rumah.index', ['siap' => Tuya::siap()]);
    }

    public function data(Request $request): JsonResponse
    {
        return $this->jalankan(fn () => ['perangkat' => Rumah::perangkat($request->boolean('segarkan'))]);
    }

    public function aksi(Request $request, string $id): JsonResponse
    {
        $v = $request->validate([
            'aksi' => 'required|in:nyala,terang,suhu,putih,warna',
            'nilai' => 'nullable',
        ]);

        return $this->jalankan(function () use ($id, $v) {
            Rumah::aksi($id, $v['aksi'], $v['nilai'] ?? null);

            return ['ok' => true];
        });
    }

    public function semuaLampu(Request $request): JsonResponse
    {
        $nyala = $request->boolean('nyala');

        return $this->jalankan(fn () => ['ok' => true, 'jumlah' => Rumah::semuaLampu($nyala)]);
    }

    private function jalankan(callable $f): JsonResponse
    {
        if (! Tuya::siap()) {
            return response()->json(['galat' => 'Kunci Tuya belum diisi di server (TUYA_CLIENT_ID / TUYA_SECRET).'], 503);
        }
        try {
            return response()->json($f());
        } catch (InvalidArgumentException $e) {
            return response()->json(['galat' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['galat' => 'Gagal menghubungi Tuya: '.$e->getMessage()], 502);
        }
    }
}
