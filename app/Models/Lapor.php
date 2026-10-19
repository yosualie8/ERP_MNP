<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kiriman Lapor Lapangan (seperti satu kiriman di grup WhatsApp absensi): banyak foto + keterangan, waktu server, GPS.
 * "jenis" diisi pemilah (App\Support\PilahLapor); "jenis_admin" = koreksi admin yang selalu menang.
 */
class Lapor extends Model
{
    protected $table = 'lapor';

    protected $fillable = ['karyawan_id', 'waktu', 'tanggal', 'lat', 'lng', 'akurasi_m', 'lokasi_absen_id', 'proyek_tahap_id', 'jarak_m', 'di_lokasi',
        'keterangan', 'jenis', 'jenis_admin', 'boq_item_id', 'catatan_admin', 'ip', 'perangkat', 'user_id'];

    protected $casts = ['waktu' => 'datetime', 'tanggal' => 'date', 'lat' => 'float', 'lng' => 'float', 'di_lokasi' => 'boolean'];

    public const JENIS = ['masuk' => 'Absen masuk', 'pulang' => 'Absen pulang', 'lembur' => 'Lembur', 'laporan' => 'Laporan / progres'];

    /** Pilihan koreksi admin (abaikan = tidak dipakai untuk absen maupun progres, mis. foto salah kirim). */
    public const JENIS_ADMIN = self::JENIS + ['abaikan' => 'Abaikan'];

    public function foto(): HasMany
    {
        return $this->hasMany(LaporFoto::class)->orderBy('urutan')->orderBy('id');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiAbsen::class, 'lokasi_absen_id');
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    /** Jenis akhir: koreksi admin bila ada, selain itu hasil pilah sistem. */
    public function jenisAkhir(): string
    {
        return $this->jenis_admin ?: $this->jenis;
    }

    /** Ada foto dari kamera langsung (bukan galeri) → boleh jadi bukti absen. */
    public function adaFotoKamera(): bool
    {
        return $this->foto->contains('sumber', 'kamera');
    }
}
