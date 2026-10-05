<?php

namespace App\Http\Controllers;

use App\Models\KasFoto;
use App\Models\KasTransfer;
use App\Support\DriveFoto;
use App\Support\FotoBon;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Foto bon sebagai referensi: lihat, tambah, hapus. Ditautkan ke NO ID transfer di sheet. */
class KasFotoController extends Controller
{
    public const ATURAN = ['foto' => ['required', 'array', 'max:6'], 'foto.*' => ['image', 'max:15360']];

    public const PESAN = ['foto.max' => 'Maksimal 6 foto sekali unggah.', 'foto.*.image' => 'File harus berupa foto (JPG/PNG).', 'foto.*.max' => 'Satu foto maksimal 15 MB.'];

    public function index(int $noId): View
    {
        $transfer = KasTransfer::where('no_id', $noId)->with('bon', 'kasBulan')->firstOrFail();

        return view('kas.bon', [
            'transfer' => $transfer,
            'foto' => KasFoto::where('no_id', $noId)->with('user')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, int $noId): RedirectResponse
    {
        $transfer = KasTransfer::where('no_id', $noId)->with('kasBulan')->firstOrFail();
        $request->validate(self::ATURAN, self::PESAN);
        foreach ($request->file('foto') as $file) {
            FotoBon::simpan($file, $noId, $transfer->kasBulan->lembar, $request->user()->id);
        }

        return back()->with('success', count($request->file('foto')).' foto bon ditambahkan.');
    }

    /** ?ukuran=kecil → pratinjau di server; selain itu foto penuh (di server bila belum dipindah, atau diambil dari Drive). */
    public function tampil(Request $request, KasFoto $foto): Response|StreamedResponse
    {
        $kepala = ['Cache-Control' => 'private, max-age=604800'];
        if ($request->query('ukuran') === 'kecil' && $foto->path_kecil && Storage::exists($foto->path_kecil)) {
            return Storage::response($foto->path_kecil, null, $kepala);
        }
        if ($foto->path && Storage::exists($foto->path)) {
            return Storage::response($foto->path, $foto->nama_asli, $kepala);
        }
        abort_unless($foto->drive_file_id && ($drive = DriveFoto::terhubung()), 404);
        try {
            $isi = $drive->ambil($foto->drive_file_id);
        } catch (\Throwable $e) {
            report($e);
            abort(502, 'Foto tidak bisa diambil dari Google Drive.');
        }

        return response($isi, 200, [...$kepala, 'Content-Type' => 'image/jpeg']);
    }

    public function destroy(KasFoto $foto): RedirectResponse
    {
        FotoBon::hapus($foto);

        return back()->with('success', 'Foto bon dihapus.');
    }
}
