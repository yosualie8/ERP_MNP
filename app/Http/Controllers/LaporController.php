<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Karyawan;
use App\Models\Lapor;
use App\Models\LaporFoto;
use App\Models\LokasiAbsen;
use App\Support\DriveFoto;
use App\Support\FotoLapor;
use App\Support\PilahLapor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapor Lapangan (menggantikan menu Absen, owner 19 Okt 2026): karyawan melempar kiriman berisi banyak foto — kamera depan /
 * belakang atau galeri — plus keterangan, seperti di grup WhatsApp absensi. Tidak ada pilihan jenis & tidak ada penolakan
 * karena urutan / radius: waktu dari server, GPS & proyek terdekat dicatat, lalu App\Support\PilahLapor yang memilah
 * masuk / pulang / lembur / laporan.
 */
class LaporController extends Controller
{
    private function karyawan(Request $request): ?Karyawan
    {
        return Karyawan::where('email', strtolower((string) $request->user()->email))->where('status', 'aktif')->first();
    }

    public function index(Request $request): View
    {
        $k = $this->karyawan($request);
        $hariIni = $k ? PilahLapor::tanggalKerja($k->id, now()) : now()->toDateString();

        return view('lapor.index', [
            'karyawan' => $k,
            'absenHariIni' => $k ? Absensi::where('karyawan_id', $k->id)->whereDate('tanggal', $hariIni)->orderBy('waktu')->get()->keyBy('jenis') : collect(),
            'riwayat' => $k ? Lapor::with(['foto', 'tahap', 'lokasi'])->where('karyawan_id', $k->id)->where('waktu', '>=', now()->subDays(7))->orderByDesc('waktu')->get() : collect(),
            'maks' => FotoLapor::MAKS_FOTO,
        ]);
    }

    /** Lokasi aktif terdekat dari titik ini: [lokasi, jarak meter] atau null. @return array{0: LokasiAbsen, 1: int}|null */
    private static function terdekat(float $lat, float $lng): ?array
    {
        return LokasiAbsen::with('tahap')->where('aktif', true)->get()->map(fn ($l) => [$l, $l->jarakKe($lat, $lng)])->sortBy(1)->first();
    }

    /** Info lokasi realtime di halaman kirim (hanya keterangan — kiriman tetap bisa dikirim di luar lokasi). */
    public function cek(Request $request): JsonResponse
    {
        $d = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'], 'akurasi' => ['nullable', 'numeric', 'min:0']]);
        $t = self::terdekat((float) $d['lat'], (float) $d['lng']);
        if (! $t) {
            return response()->json(['di_lokasi' => false, 'pesan' => 'Lokasi tercatat (belum ada titik proyek).']);
        }
        [$l, $jarak] = $t;
        $teks = $jarak >= 1000 ? number_format($jarak / 1000, 1, ',', '.').' km' : "{$jarak} m";

        return response()->json($jarak <= $l->radius_m
            ? ['di_lokasi' => true, 'pesan' => ($l->tahap?->nama ?? $l->nama)." · {$teks} dari titik"]
            : ['di_lokasi' => false, 'pesan' => "Di luar lokasi proyek — terdekat {$l->label()} ({$teks}). Tetap bisa dikirim."]);
    }

    public function simpan(Request $request): JsonResponse
    {
        $k = $this->karyawan($request);
        if (! $k) {
            return response()->json(['galat' => ['Akun Anda tidak terhubung ke Data Karyawan aktif. Hubungi admin.']], 422);
        }
        $d = $request->validate([
            'foto' => ['required', 'array', 'min:1', 'max:'.FotoLapor::MAKS_FOTO], 'foto.*' => ['image', 'max:15360'],
            'sumber' => ['array'], 'sumber.*' => ['in:kamera,galeri'], 'kamera' => ['array'], 'kamera.*' => ['nullable', 'in:depan,belakang'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'], 'akurasi' => ['nullable', 'numeric', 'min:0'],
        ], ['foto.required' => 'Ambil atau pilih minimal satu foto.', 'foto.max' => 'Maksimal '.FotoLapor::MAKS_FOTO.' foto sekali kirim.', 'foto.*.image' => 'File harus foto.', 'foto.*.max' => 'Foto terlalu besar (maks. 15 MB).']);

        $waktu = now();
        $akurasi = isset($d['akurasi']) ? (int) round($d['akurasi']) : null;
        $lok = isset($d['lat'], $d['lng']) ? self::terdekat((float) $d['lat'], (float) $d['lng']) : null;
        [$l, $jarak] = $lok ?? [null, null];
        $diLokasi = $l && $jarak <= $l->radius_m;
        $tanggal = PilahLapor::tanggalKerja($k->id, $waktu);

        $lapor = DB::transaction(function () use ($request, $d, $k, $waktu, $akurasi, $l, $jarak, $diLokasi, $tanggal) {
            $lapor = Lapor::create(['karyawan_id' => $k->id, 'waktu' => $waktu, 'tanggal' => $tanggal, 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null,
                'akurasi_m' => $akurasi, 'lokasi_absen_id' => $l?->id, 'proyek_tahap_id' => $diLokasi ? $l->proyek_tahap_id : null, 'jarak_m' => $jarak, 'di_lokasi' => $diLokasi,
                'keterangan' => trim((string) ($d['keterangan'] ?? '')) ?: null, 'ip' => $request->ip(), 'perangkat' => mb_substr((string) $request->userAgent(), 0, 255),
                'user_id' => $request->user()->id]);
            $watermark = [$waktu->translatedFormat('j M Y H:i:s').' WIB',
                isset($d['lat']) ? sprintf('GPS %.7f, %.7f', $d['lat'], $d['lng']).($akurasi !== null ? " ±{$akurasi} m" : '') : 'GPS tidak tersedia',
                $l ? ($diLokasi ? "{$l->label()} ({$jarak} m)" : "di luar lokasi · terdekat {$l->label()} ({$jarak} m)") : '',
                $k->nama];
            foreach (array_values($request->file('foto')) as $i => $file) {
                FotoLapor::simpan($lapor, $file, $i + 1, $d['sumber'][$i] ?? 'galeri', $d['kamera'][$i] ?? null, array_values(array_filter($watermark)));
            }

            return $lapor;
        });
        // Kiriman & foto sudah tersimpan: bila pemilahan gagal, karyawan tetap mendapat "terkirim" (galat dicatat di log, dipilah ulang nanti).
        rescue(fn () => PilahLapor::hari($k->id, $tanggal), null, true);
        FotoLapor::unggahSegera();
        $lapor->refresh();

        return response()->json(['ok' => true, 'pesan' => 'Terkirim pukul '.$waktu->format('H:i').' · '.count($request->file('foto')).' foto'
            .($diLokasi ? ' · '.($l->tahap?->nama ?? $l->nama) : ($l ? ' · di luar lokasi proyek' : ''))
            // pulang / lembur baru pasti di akhir hari (kiriman terakhir) → yang pasti hanya absen masuk
            .($lapor->jenis === 'masuk' ? ' · tercatat sebagai absen masuk' : ' · tersimpan')]);
    }

    /** Foto kiriman: pemiliknya sendiri, atau admin dengan menu Data Lapor Lapangan / Data Absensi. ?kecil=1 = pratinjau. */
    public function foto(Request $request, LaporFoto $foto): Response
    {
        $u = $request->user();
        $k = $foto->lapor?->karyawan;
        abort_unless(($k?->email && strtolower($k->email) === strtolower((string) $u->email)) || $u->bolehMenu('lapor-data') || $u->bolehMenu('absensi'), 403);
        $kepala = ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400'];
        if ($request->boolean('kecil') && $foto->path_kecil && Storage::exists($foto->path_kecil)) {
            return response()->file(Storage::path($foto->path_kecil), $kepala);
        }
        if ($foto->path && Storage::exists($foto->path)) {
            return response()->file(Storage::path($foto->path), $kepala);
        }
        if ($foto->drive_file_id && ($drive = DriveFoto::terhubung())) {
            return response($drive->ambil($foto->drive_file_id), 200, $kepala);
        }
        abort_unless($foto->path_kecil && Storage::exists($foto->path_kecil), 404);

        return response()->file(Storage::path($foto->path_kecil), $kepala);
    }
}
