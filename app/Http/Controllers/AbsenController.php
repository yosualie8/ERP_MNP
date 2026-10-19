<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Karyawan;
use App\Models\LokasiAbsen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Absen karyawan dari HP sendiri: selfie dari kamera langsung + titik GPS, WAKTU DARI SERVER (jam HP diabaikan).
 * Ditolak bila akurasi GPS buruk atau di luar radius lokasi absen aktif mana pun.
 */
class AbsenController extends Controller
{
    /** GPS dengan akurasi lebih buruk dari ini ditolak (meter). */
    public const AKURASI_MAKS = 100;

    private function karyawan(Request $request): ?Karyawan
    {
        return Karyawan::where('email', strtolower((string) $request->user()->email))->where('status', 'aktif')->first();
    }

    public function index(Request $request): View
    {
        $k = $this->karyawan($request);

        return view('absen.index', [
            'karyawan' => $k,
            'berikut' => $k ? Absensi::berikutnya($k) : null,
            'riwayat' => $k ? Absensi::with('lokasi.tahap')->where('karyawan_id', $k->id)->where('waktu', '>=', now()->subDays(7))->orderByDesc('waktu')->get() : collect(),
            'adaLokasi' => LokasiAbsen::where('aktif', true)->exists(),
        ]);
    }

    /**
     * Lokasi absen aktif terdekat dari titik ini: [lokasi, jarak meter] atau null bila belum ada lokasi.
     *
     * @return array{0: LokasiAbsen, 1: int}|null
     */
    private static function terdekat(float $lat, float $lng): ?array
    {
        return LokasiAbsen::with('tahap')->where('aktif', true)->get()->map(fn ($l) => [$l, $l->jarakKe($lat, $lng)])->sortBy(1)->first();
    }

    /**
     * Cek lokasi realtime saat karyawan di halaman Absen (dipanggil tiap posisi GPS berubah). Dihitung di server —
     * titik koordinat proyek tidak dikirim ke HP. Hasil: di dalam area (lokasi & proyek mana) atau di luar (jarak ke terdekat).
     */
    public function cek(Request $request): \Illuminate\Http\JsonResponse
    {
        $d = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'], 'akurasi' => ['nullable', 'numeric', 'min:0']]);
        $akurasi = isset($d['akurasi']) ? (int) round($d['akurasi']) : null;
        if ($akurasi !== null && $akurasi > self::AKURASI_MAKS) {
            return response()->json(['boleh' => false, 'pesan' => "Sinyal GPS lemah (±{$akurasi} m) — pindah ke tempat terbuka."]);
        }
        $t = self::terdekat((float) $d['lat'], (float) $d['lng']);
        if (! $t) {
            return response()->json(['boleh' => false, 'pesan' => 'Belum ada lokasi absen yang aktif. Hubungi admin.']);
        }
        [$l, $jarak] = $t;
        $teksJarak = $jarak >= 1000 ? number_format($jarak / 1000, 1, ',', '.').' km' : "{$jarak} m";

        // Tahap & lokasi proyek ditampilkan di halaman Absen saat mengambil selfie.
        return response()->json($jarak <= $l->radius_m
            ? ['boleh' => true, 'pesan' => "{$teksJarak} dari titik, maks. {$l->radius_m} m", 'proyek' => $l->tahap?->nama ?? $l->nama, 'lokasi' => $l->lokasiProyek()]
            : ['boleh' => false, 'pesan' => "Di luar area absen — lokasi terdekat {$l->label()} berjarak {$teksJarak} (maks. {$l->radius_m} m)."]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $k = $this->karyawan($request);
        if (! $k) {
            return back()->with('error', 'Akun Google Anda tidak terhubung ke Data Karyawan aktif. Hubungi admin.');
        }
        $data = $request->validate([
            'jenis' => ['required', 'in:'.implode(',', array_keys(Absensi::JENIS))],
            'foto' => ['required', 'file', 'mimes:jpg,jpeg', 'max:3072'],
            'lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'],
            'akurasi' => ['nullable', 'numeric', 'min:0'],
        ], ['foto.required' => 'Ambil selfie dulu.', 'lat.required' => 'Lokasi GPS belum didapat — izinkan akses lokasi lalu coba lagi.']);

        // Urutan absen ditentukan server (bukan dari tombol yang dikirim HP).
        $berikut = Absensi::berikutnya($k);
        if ($berikut['jenis'] !== $data['jenis']) {
            return redirect()->route('absen.index')->with('error', $berikut['jenis']
                ? 'Absen berikutnya adalah '.Absensi::JENIS[$berikut['jenis']].' — halaman dimuat ulang.' : 'Absen sesi ini sudah lengkap.');
        }
        $akurasi = $data['akurasi'] !== null ? (int) round($data['akurasi']) : null;
        if ($akurasi !== null && $akurasi > self::AKURASI_MAKS) {
            return back()->with('error', "Sinyal GPS kurang akurat (±{$akurasi} m). Pindah ke tempat terbuka, tunggu sebentar, lalu coba lagi.");
        }
        // Lokasi absen aktif terdekat; harus di dalam radiusnya.
        $lokasi = self::terdekat((float) $data['lat'], (float) $data['lng']);
        if (! $lokasi) {
            return back()->with('error', 'Belum ada lokasi absen yang aktif. Hubungi admin.');
        }
        [$l, $jarak] = $lokasi;
        if ($jarak > $l->radius_m) {
            return back()->with('error', "Anda di luar area absen: {$jarak} m dari {$l->label()} (maks. {$l->radius_m} m). Absen hanya bisa di lokasi proyek.");
        }

        $waktu = now();
        $path = $request->file('foto')->storeAs('absen/'.$waktu->format('Y/m'), $k->id.'-'.$waktu->format('Ymd-His').'-'.$data['jenis'].'.jpg');
        // Watermark dari data yang sudah divalidasi server (jenis, waktu server, GPS, proyek & lokasi, nama).
        \App\Support\WatermarkAbsen::pasang(Storage::path($path), [
            mb_strtoupper(Absensi::JENIS[$data['jenis']]).' · '.$waktu->translatedFormat('j M Y H:i:s').' WIB',
            sprintf('GPS %.7f, %.7f', $data['lat'], $data['lng']).($akurasi !== null ? " ±{$akurasi} m" : ''),
            "{$l->label()} ({$jarak} m)",
            $k->nama,
        ]);
        Absensi::create([
            'karyawan_id' => $k->id, 'tanggal' => $berikut['tanggal'], 'jenis' => $data['jenis'], 'waktu' => $waktu,
            'lokasi_absen_id' => $l->id, 'lat' => $data['lat'], 'lng' => $data['lng'], 'akurasi_m' => $akurasi, 'jarak_m' => $jarak,
            'foto' => $path, 'ip' => $request->ip(), 'perangkat' => mb_substr((string) $request->userAgent(), 0, 255), 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('absen.index')->with('success', Absensi::JENIS[$data['jenis']].' tercatat pukul '.$waktu->format('H:i').' di '.$l->label().'.');
    }

    /** Foto absen: karyawan hanya fotonya sendiri; admin dengan menu Data Absensi semua. */
    public function foto(Request $request, Absensi $absensi): Response
    {
        $u = $request->user();
        $milikSendiri = $absensi->karyawan?->email && strtolower($absensi->karyawan->email) === strtolower((string) $u->email);
        abort_unless($milikSendiri || $u->bolehMenu('absensi'), 403);
        // Absen hasil pilah Lapor Lapangan: foto kamera kirimannya
        if ($absensi->lapor_id && ($f = \App\Models\LaporFoto::where('lapor_id', $absensi->lapor_id)->orderByRaw("sumber = 'kamera' DESC")->orderBy('urutan')->first())) {
            return redirect()->route('lapor.foto', $f);
        }
        abort_unless($absensi->foto && Storage::exists($absensi->foto), 404);

        return response()->file(Storage::path($absensi->foto), ['Cache-Control' => 'private, max-age=86400']);
    }
}
