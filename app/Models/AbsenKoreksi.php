<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Koreksi jam satu absen (masuk / pulang / lembur) oleh admin — absen asli tetap di tabel absensi. Lihat Absensi::denganKoreksi(). */
class AbsenKoreksi extends Model
{
    protected $table = 'absen_koreksi';

    protected $fillable = ['karyawan_id', 'tanggal', 'jenis', 'waktu', 'catatan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'waktu' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }
}
