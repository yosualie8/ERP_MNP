<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keputusan rekap absen satu karyawan satu hari (lihat App\Support\RekapAbsenMingguan): hadir / lembur dibayar atau tidak
 * (null = ikut hasil absen), HM alat (operator), UM / lembur hari itu yang diubah admin + alasannya, tahap kerja, dan siapa yang mengisi (mandor untuk tukang tanpa absen HP, admin untuk persetujuan).
 */
class RekapAbsen extends Model
{
    protected $table = 'rekap_absen';

    protected $fillable = ['karyawan_id', 'tanggal', 'hadir', 'lembur', 'hm', 'bayar_ubah', 'bayar_lembur_ubah', 'bayar_alasan', 'proyek_tahap_id', 'sumber', 'catatan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'hadir' => 'boolean', 'lembur' => 'boolean', 'hm' => 'float', 'bayar_ubah' => 'integer', 'bayar_lembur_ubah' => 'integer'];

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }
}
