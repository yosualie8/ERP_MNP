<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu tahap proyek: unit biaya & tagihan. Nama lama/variasi tulisan di sumber lain ada di alias. */
class ProyekTahap extends Model
{
    protected $table = 'proyek_tahap';

    protected $fillable = ['proyek_id', 'nama', 'tipe', 'status', 'lokasi', 'volume', 'luas', 'harga_m3', 'omset', 'tarif_bbm', 'tarif_rental',
        'komisi_lapangan', 'komisi_yosua', 'komisi_verlix', 'bayar_lapangan', 'bayar_yosua', 'bayar_verlix', 'catatan', 'user_id'];

    protected $casts = ['volume' => 'float', 'luas' => 'float', 'komisi_lapangan' => 'float', 'komisi_yosua' => 'float', 'komisi_verlix' => 'float',
        'bayar_lapangan' => 'date', 'bayar_yosua' => 'date', 'bayar_verlix' => 'date'];

    public const TIPE = ['luas' => 'Luas & volume', 'ritasi' => 'Ritasi', 'biaya' => 'Biaya umum']; // biaya = biaya tanpa tahap / biaya lokasi

    public const STATUS = ['belum' => 'Belum mulai', 'progress' => 'Progress', 'selesai' => 'Selesai', 'batal' => 'Batal'];

    /** Tarif bawaan (sama dengan sheet Cost Project); bisa diubah per tahap. */
    public const BAWAAN = ['harga_m3' => 139000, 'tarif_bbm' => 12000, 'tarif_rental' => 160000, 'komisi_lapangan' => 3.5, 'komisi_yosua' => 2, 'komisi_verlix' => 2];

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function alias(): HasMany
    {
        return $this->hasMany(ProyekAlias::class);
    }

    public function tinggiUrugan(): ?float
    {
        return $this->volume && $this->luas ? round($this->volume / $this->luas, 3) : null;
    }

    /** Omset: diisi manual, atau volume × harga per m³. */
    public function omsetHitung(): ?int
    {
        return $this->omset ?? ($this->volume && $this->harga_m3 ? (int) round($this->volume * $this->harga_m3) : null);
    }
}
