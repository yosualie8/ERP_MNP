<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Titik GPS lokasi kerja (per proyek) — karyawan hanya bisa absen di dalam radiusnya. */
class LokasiAbsen extends Model
{
    protected $table = 'lokasi_absen';

    protected $fillable = ['nama', 'proyek_tahap_id', 'lat', 'lng', 'radius_m', 'aktif', 'user_id'];

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'aktif' => 'boolean'];

    public function absensi(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Absensi::class, 'lokasi_absen_id');
    }

    /** Proyek detail (tahap di Daftar Proyek). */
    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }

    /** Lokasi proyek = kolom Lokasi tahap di Daftar Proyek (selalu terbaru); bila di sana kosong, nama tahapnya. */
    public function lokasiProyek(): string
    {
        return $this->tahap?->lokasi ?: ($this->tahap?->nama ?? $this->nama);
    }

    /** "ASG Tahap 116 · Parkiran Ginza" — dipakai di halaman Absen, watermark selfie & Data Absensi. */
    public function label(): string
    {
        $t = $this->tahap;

        return $t ? $t->nama.($t->lokasi ? " · {$t->lokasi}" : '') : $this->nama;
    }

    /** Jarak (meter) titik ini ke koordinat lain — rumus haversine. */
    public function jarakKe(float $lat, float $lng): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat - $this->lat);
        $dLng = deg2rad($lng - $this->lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->lat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }

    public function urlPeta(): string
    {
        return "https://www.google.com/maps?q={$this->lat},{$this->lng}";
    }
}
