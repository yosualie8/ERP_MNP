<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Karyawan;
use App\Support\DaftarSopir;
use App\Support\PilahLapor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Review Absen Harian (owner 24 Okt 2026): per karyawan wajib absen (karyawan aktif, kecuali sopir DT) apakah absen masuk & pulangnya
 * lengkap. Admin mengirim review lewat teks ringkasan untuk grup WhatsApp atau WA pribadi per karyawan (No HP di Data Karyawan).
 */
class ReviewAbsenController extends Controller
{
    public const STATUS = [
        'tidak_absen' => ['Tidak absen', 'merah'],
        'tanpa_pulang' => ['Belum absen pulang', 'kuning'],
        'tanpa_masuk' => ['Belum absen masuk', 'kuning'],
        'lengkap' => ['Lengkap', 'hijau'],
    ];

    public function index(Request $request): View
    {
        $jam = PilahLapor::jam();
        // Bawaan: hari ini bila sudah lewat 1 jam dari jam pulang standar, selain itu kemarin (hari ini masih berjalan).
        $bawaan = now()->format('H:i') >= Carbon::parse($jam['pulang'])->addHour()->format('H:i') ? today() : today()->subDay();
        $tanggal = (RitasiController::tanggal($request->query('tanggal')) ?? $bawaan)->copy()->startOfDay();
        $absen = Absensi::with('lokasi.tahap')->whereDate('tanggal', $tanggal)->orderBy('waktu')->get()->groupBy('karyawan_id');
        // Sama dengan rekap Data Absensi: karyawan aktif kecuali sopir DT (jabatan Supir yang membawa truk) + siapa pun yang absen hari itu.
        $karyawan = Karyawan::where(fn ($q) => $q->where('status', 'aktif')->where(fn ($w) => $w->whereNull('jabatan')->orWhere('jabatan', '!=', DaftarSopir::JABATAN)->orWhereNull('no_mobil')))
            ->orWhereIn('id', $absen->keys())->orderBy('nama')->get();
        $hari = $tanggal->translatedFormat('l, j F Y');
        $baris = $karyawan->map(function ($k) use ($absen, $hari) {
            $a = ($absen[$k->id] ?? collect())->keyBy('jenis');
            $masuk = $a['masuk'] ?? null;
            $pulang = $a['pulang'] ?? null;
            $status = $masuk && $pulang ? 'lengkap' : ($masuk ? 'tanpa_pulang' : ($pulang ? 'tanpa_masuk' : 'tidak_absen'));
            $jm = $masuk?->waktu->format('H:i');
            $jp = $pulang?->waktu->format('H:i');
            $pesan = "Halo {$k->nama}, review absen *{$hari}*:\n"
                .'• Absen masuk: '.($jm ? "{$jm} ✅" : 'belum ada ❌')."\n"
                .'• Absen pulang: '.($jp ? "{$jp} ✅" : 'belum ada ❌')."\n"
                .match ($status) {
                    'lengkap' => 'Absen Anda lengkap. Terima kasih 🙏',
                    'tidak_absen' => 'Tidak ada absen hari itu. Mohon konfirmasi ke admin (izin / sakit / lupa absen).',
                    default => 'Mohon dilengkapi lewat Lapor Lapangan atau konfirmasi ke admin. Terima kasih.',
                }."\n— Admin MNP";

            return (object) ['k' => $k, 'status' => $status, 'masuk' => $jm, 'pulang' => $jp,
                'jam_kerja' => $masuk && $pulang ? $masuk->waktu->diffInMinutes($pulang->waktu) : null,
                'lokasi' => ($masuk ?? $pulang)?->lokasi?->tahap?->nama ?? ($masuk ?? $pulang)?->lokasi?->nama,
                'wa' => $k->nomorWa(), 'pesan' => $pesan];
        })->sortBy(fn ($b) => [array_search($b->status, array_keys(self::STATUS)), $b->k->nama])->values();

        return view('absen.review', ['tanggal' => $tanggal, 'hari' => $hari, 'baris' => $baris, 'jam' => $jam,
            'jumlah' => $baris->countBy('status')->all(),
            'dataJs' => $baris->map(fn ($b) => ['nama' => $b->k->nama, 'status' => $b->status, 'masuk' => $b->masuk, 'pulang' => $b->pulang])->values(), 'tanpaHp' => $baris->where('status', '!=', 'lengkap')->whereNull('wa')->count()]);
    }
}
