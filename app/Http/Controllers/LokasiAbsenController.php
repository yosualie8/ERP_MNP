<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\LokasiAbsen;
use App\Models\Proyek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Lokasi Absen: titik GPS + radius per proyek; karyawan hanya bisa absen di dalam radius lokasi yang aktif. */
class LokasiAbsenController extends Controller
{
    public function index(): View
    {
        return view('lokasi-absen.index', ['lokasi' => LokasiAbsen::with('proyek')->withCount('absensi')->orderByDesc('aktif')->orderBy('nama')->get()]);
    }

    public function create(): View
    {
        return view('lokasi-absen.form', ['lokasi' => new LokasiAbsen(['radius_m' => 100, 'aktif' => true]), 'proyek' => Proyek::orderBy('nama')->get()]);
    }

    public function edit(LokasiAbsen $lokasi): View
    {
        return view('lokasi-absen.form', ['lokasi' => $lokasi, 'proyek' => Proyek::orderBy('nama')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $l = LokasiAbsen::create([...$this->baca($request), 'user_id' => $request->user()->id]);
        $this->catat($request, 'lokasi-tambah', "Lokasi absen baru {$l->nama} ({$l->lat}, {$l->lng}, radius {$l->radius_m} m)", $l->toArray());

        return redirect()->route('lokasi-absen.index')->with('success', "Lokasi {$l->nama} disimpan.");
    }

    public function update(Request $request, LokasiAbsen $lokasi): RedirectResponse
    {
        $lama = $lokasi->toArray();
        $lokasi->update([...$this->baca($request), 'user_id' => $request->user()->id]);
        $this->catat($request, 'lokasi-ubah', "Lokasi absen {$lokasi->nama} diubah", ['sebelum' => $lama, 'sesudah' => $lokasi->fresh()->toArray()]);

        return redirect()->route('lokasi-absen.index')->with('success', "Lokasi {$lokasi->nama} disimpan.");
    }

    private function baca(Request $request): array
    {
        $request->merge(['nama' => trim(preg_replace('/\s+/', ' ', (string) $request->input('nama'))), 'aktif' => $request->boolean('aktif'),
            'lat' => str_replace(',', '.', (string) $request->input('lat')), 'lng' => str_replace(',', '.', (string) $request->input('lng'))]);

        return $request->validate([
            'nama' => ['required', 'string', 'max:120'], 'proyek_id' => ['nullable', 'exists:proyek,id'],
            'lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:20', 'max:2000'], 'aktif' => ['boolean'],
        ], ['lat.required' => 'Titik GPS belum diisi — tekan "Ambil titik saya" saat berada di lokasi, atau tempel koordinat dari Google Maps.']);
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Absen', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
