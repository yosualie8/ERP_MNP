<?php

namespace App\Support;

use App\Models\Absensi;
use App\Models\Lapor;
use App\Models\PengaturanApp;
use App\Models\ProyekTahap;
use Illuminate\Support\Carbon;

/**
 * Pemilah Lapor Lapangan (owner 19 Okt 2026): karyawan cukup melempar kiriman (seperti grup WhatsApp absensi), sistem yang
 * menentukan per karyawan per tanggal kerja:
 *  - MASUK  = kiriman pertama yang punya foto kamera langsung (foto galeri bisa foto lama → bukan bukti absen);
 *  - PULANG = kiriman terakhir sampai jam pulang + toleransi;
 *  - LEMBUR = kiriman terakhir yang lewat jam pulang + toleransi → pulang dicatat pada jam pulang standar, lembur sejak jam itu;
 *  - sisanya LAPORAN / progres (dikaitkan ke proyek tahap dari lokasinya; admin memilih item BOQ).
 * Koreksi admin (jenis_admin) selalu menang. Jam kerja: per proyek tahap (jam_masuk/jam_pulang) atau standar di pengaturan.
 * Hasil absen ditulis ke tabel absensi (otomatis = true) supaya Data Absensi tetap dipakai.
 */
class PilahLapor
{
    public const BAWAAN = ['masuk' => '08:00', 'pulang' => '17:00', 'toleransi' => 60];

    /** Kiriman sebelum jam ini ikut tanggal kerja kemarin (lembur lewat tengah malam). */
    public const BATAS_SUBUH = 4;

    /** @return array{masuk: string, pulang: string, toleransi: int} */
    public static function jam(?ProyekTahap $t = null): array
    {
        return [
            'masuk' => $t?->jam_masuk ? substr($t->jam_masuk, 0, 5) : PengaturanApp::ambil('absen_jam_masuk', self::BAWAAN['masuk']),
            'pulang' => $t?->jam_pulang ? substr($t->jam_pulang, 0, 5) : PengaturanApp::ambil('absen_jam_pulang', self::BAWAAN['pulang']),
            'toleransi' => (int) PengaturanApp::ambil('absen_toleransi_lembur', (string) self::BAWAAN['toleransi']),
        ];
    }

    /** Tanggal kerja kiriman: sebelum jam 04.00 dan kemarin ada kiriman → masih sesi kemarin. */
    public static function tanggalKerja(int $karyawanId, Carbon $waktu): string
    {
        if ($waktu->hour < self::BATAS_SUBUH) {
            $kemarin = $waktu->copy()->subDay()->toDateString();
            if (Lapor::where('karyawan_id', $karyawanId)->whereDate('tanggal', $kemarin)->exists()) {
                return $kemarin;
            }
        }

        return $waktu->toDateString();
    }

    /** Pilah ulang semua kiriman satu karyawan di satu tanggal kerja, lalu tulis ulang absensi otomatisnya. */
    public static function hari(int $karyawanId, string $tanggal): void
    {
        $kiriman = Lapor::with(['foto', 'tahap'])->where('karyawan_id', $karyawanId)->whereDate('tanggal', $tanggal)->orderBy('waktu')->orderBy('id')->get();
        $bebas = fn (Lapor $l) => ! $l->jenis_admin && $l->adaFotoKamera(); // calon absen tanpa koreksi admin
        $masuk = $kiriman->firstWhere('jenis_admin', 'masuk') ?? $kiriman->first($bebas);
        $tahap = $masuk?->tahap ?? $kiriman->first(fn ($l) => $l->tahap)?->tahap;
        $jam = self::jam($tahap);
        $jamPulang = Carbon::parse("{$tanggal} {$jam['pulang']}");
        $batas = $jamPulang->copy()->addMinutes($jam['toleransi']);

        $pulangAdmin = $kiriman->where('jenis_admin', 'pulang')->last();
        $lemburAdmin = $kiriman->where('jenis_admin', 'lembur');
        $terakhir = $kiriman->filter($bebas)->reject(fn ($l) => $masuk && $l->id === $masuk->id)->last();
        $pulang = $pulangAdmin;
        $lembur = $lemburAdmin->last();
        if (! $pulang && ! $lembur && $terakhir) {
            if ($terakhir->waktu->gt($batas)) {
                $lembur = $terakhir;
            } else {
                $pulang = $terakhir;
            }
        } elseif (! $lembur && $terakhir && $terakhir->waktu->gt($batas) && (! $pulang || $terakhir->waktu->gt($pulang->waktu))) {
            $lembur = $terakhir;
        }

        foreach ($kiriman as $l) {
            $j = match (true) {
                $masuk && $l->id === $masuk->id => 'masuk',
                $pulang && $l->id === $pulang->id => 'pulang',
                ($lembur && $l->id === $lembur->id) || $l->jenis_admin === 'lembur' => 'lembur',
                default => 'laporan',
            };
            if ($l->jenis !== $j) {
                $l->forceFill(['jenis' => $j])->saveQuietly();
            }
        }

        // Absensi otomatis ditulis ulang; absen lama dari menu Absen (otomatis = false) hari itu tidak disentuh.
        Absensi::where('karyawan_id', $karyawanId)->whereDate('tanggal', $tanggal)->where('otomatis', true)->delete();
        if (! $masuk || Absensi::where('karyawan_id', $karyawanId)->whereDate('tanggal', $tanggal)->where('otomatis', false)->exists()) {
            return;
        }
        $baris = function (string $jenis, Lapor $l, ?Carbon $waktu = null) use ($karyawanId, $tanggal) {
            Absensi::create(['karyawan_id' => $karyawanId, 'tanggal' => $tanggal, 'jenis' => $jenis, 'waktu' => $waktu ?? $l->waktu,
                // lokasi proyek hanya bila memang di dalam radiusnya (di luar → nama tempat dari kiriman, lihat Data Absensi)
                'lokasi_absen_id' => $l->di_lokasi ? $l->lokasi_absen_id : null, 'lat' => $l->lat, 'lng' => $l->lng, 'akurasi_m' => $l->akurasi_m, 'jarak_m' => $l->jarak_m,
                'foto' => '', 'lapor_id' => $l->id, 'otomatis' => true, 'ip' => $l->ip, 'perangkat' => $l->perangkat, 'user_id' => $l->user_id]);
        };
        $baris('masuk', $masuk);
        if ($pulang) {
            $baris('pulang', $pulang);
        }
        if ($lembur) {
            // Lembur tanpa kiriman pulang: pulang dicatat pada jam pulang standar, lembur dihitung sejak jam itu.
            $mulai = $pulang && $pulang->waktu->gt($jamPulang) ? $pulang->waktu : $jamPulang;
            if (! $pulang) {
                $baris('pulang', $lembur, $jamPulang->copy()->max($masuk->waktu));
            }
            $baris('lembur_mulai', $lembur, $mulai->copy()->max($masuk->waktu));
            $baris('lembur_selesai', $lembur);
        }
    }
}
