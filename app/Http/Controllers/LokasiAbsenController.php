<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\LokasiAbsen;
use App\Models\ProyekTahap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Lokasi Absen: titik GPS + radius per proyek; karyawan hanya bisa absen di dalam radius lokasi yang aktif. */
class LokasiAbsenController extends Controller
{
    public function index(): View
    {
        return view('lokasi-absen.index', ['lokasi' => LokasiAbsen::with('tahap')->withCount('absensi')->orderByDesc('aktif')->orderBy('nama')->get()]);
    }

    public function create(): View
    {
        return view('lokasi-absen.form', ['lokasi' => new LokasiAbsen(['radius_m' => 100, 'aktif' => true]), ...$this->daftarTahap()]);
    }

    public function edit(LokasiAbsen $lokasi): View
    {
        return view('lokasi-absen.form', ['lokasi' => $lokasi->load('tahap'), ...$this->daftarTahap()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $l = LokasiAbsen::create([...$this->baca($request), 'user_id' => $request->user()->id]);
        $this->catat($request, 'lokasi-tambah', "Lokasi absen baru {$l->load('tahap')->label()} ({$l->lat}, {$l->lng}, radius {$l->radius_m} m)", $l->toArray());

        return redirect()->route('lokasi-absen.index')->with('success', "Lokasi {$l->label()} disimpan.");
    }

    public function update(Request $request, LokasiAbsen $lokasi): RedirectResponse
    {
        $lama = $lokasi->toArray();
        $lokasi->update([...$this->baca($request), 'user_id' => $request->user()->id]);
        $this->catat($request, 'lokasi-ubah', "Lokasi absen {$lokasi->load('tahap')->label()} diubah", ['sebelum' => $lama, 'sesudah' => $lokasi->fresh()->toArray()]);

        return redirect()->route('lokasi-absen.index')->with('success', "Lokasi {$lokasi->label()} disimpan.");
    }

    /** Proyek detail yang boleh dipilih: tahap di Daftar Proyek (bukan baris "biaya lokasi"); saran = yang belum selesai. */
    private function daftarTahap(): array
    {
        $semua = ProyekTahap::where('nama', 'not like', '%(biaya%')->orderBy('nama')->get(['id', 'nama', 'status', 'lokasi']);

        // tahapSemua: nama tahap → lokasi (dari Daftar Proyek), untuk mengisi Lokasi otomatis di form.
        return ['tahapSemua' => $semua->mapWithKeys(fn ($t) => [$t->nama => $t->lokasi])->all(), 'tahapSaran' => $semua->whereIn('status', ['belum', 'progress'])->pluck('nama')->all()];
    }

    private function baca(Request $request): array
    {
        // Proyek diketik/dipilih dengan nama tahap; harus PERSIS terdaftar di Daftar Proyek.
        $nama = (string) $request->input('proyek');
        $tahap = ProyekTahap::where('nama', $nama)->where('nama', 'not like', '%(biaya%')->first();
        if (! $tahap || $tahap->nama !== $nama) {
            throw \Illuminate\Validation\ValidationException::withMessages(['proyek' => $nama === ''
                ? 'Proyek belum dipilih.' : "Proyek \"{$nama}\" tidak terdaftar di Daftar Proyek — pilih dari daftar (ketik persis sama)."]);
        }
        // Nama lokasi tidak diketik: diambil dari kolom Lokasi tahap di Daftar Proyek (kosong → nama tahap).
        $request->merge(['proyek_tahap_id' => $tahap->id, 'nama' => mb_substr($tahap->lokasi ?: $tahap->nama, 0, 120)]);
        $request->merge(['aktif' => $request->boolean('aktif'),
            'lat' => str_replace(',', '.', (string) $request->input('lat')), 'lng' => str_replace(',', '.', (string) $request->input('lng'))]);

        return $request->validate([
            'nama' => ['required', 'string', 'max:120'], 'proyek_tahap_id' => ['required', 'exists:proyek_tahap,id'],
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
