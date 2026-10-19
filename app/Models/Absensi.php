<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu absen karyawan (masuk / pulang / mulai lembur / selesai lembur) — waktu dari server, lokasi & selfie dari HP. */
class Absensi extends Model
{
    protected $table = 'absensi';

    protected $fillable = ['karyawan_id', 'tanggal', 'jenis', 'waktu', 'lokasi_absen_id', 'lat', 'lng', 'akurasi_m', 'jarak_m', 'foto', 'lapor_id', 'otomatis', 'ip', 'perangkat', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'waktu' => 'datetime', 'lat' => 'float', 'lng' => 'float'];

    public const JENIS = ['masuk' => 'Masuk', 'pulang' => 'Pulang', 'lembur_mulai' => 'Mulai lembur', 'lembur_selesai' => 'Selesai lembur'];

    /** Satu sesi kerja dianggap berakhir bila lewat dari sekian jam sejak absen Masuk (lupa absen Pulang). */
    public const SESI_JAM = 20;

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    /** Kiriman Lapor Lapangan asal absen ini (absen otomatis). */
    public function lapor(): BelongsTo
    {
        return $this->belongsTo(Lapor::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiAbsen::class, 'lokasi_absen_id');
    }

    /**
     * Jenis absen yang boleh dilakukan karyawan sekarang, urut: Masuk → Pulang → Mulai lembur → Selesai lembur.
     * Sesi = sejak absen Masuk terakhir; sesi baru dimulai bila sesi lama lewat SESI_JAM jam, atau sudah ganti hari & sesi selesai.
     *
     * @return array{jenis: ?string, sesi: \Illuminate\Support\Collection, tanggal: string}
     */
    public static function berikutnya(Karyawan $k): array
    {
        $masuk = self::where('karyawan_id', $k->id)->where('jenis', 'masuk')->orderByDesc('waktu')->first();
        $hariIni = now()->toDateString();
        if (! $masuk || $masuk->waktu->lt(now()->subHours(self::SESI_JAM))) {
            return ['jenis' => 'masuk', 'sesi' => collect(), 'tanggal' => $hariIni];
        }
        $sesi = self::where('karyawan_id', $k->id)->where('waktu', '>=', $masuk->waktu)->orderBy('waktu')->get();
        $akhir = $sesi->last()->jenis;
        $selesai = in_array($akhir, ['pulang', 'lembur_selesai'], true);
        // Sesi kemarin sudah ditutup → hari ini mulai sesi baru.
        if ($selesai && $masuk->tanggal->toDateString() < $hariIni) {
            return ['jenis' => 'masuk', 'sesi' => collect(), 'tanggal' => $hariIni];
        }
        $lanjut = ['masuk' => 'pulang', 'pulang' => 'lembur_mulai', 'lembur_mulai' => 'lembur_selesai', 'lembur_selesai' => null][$akhir];

        return ['jenis' => $lanjut, 'sesi' => $sesi, 'tanggal' => $masuk->tanggal->toDateString()];
    }
}
