<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Item BOQ satu tahap: volume × harga jual, modal material & jasa (lihat App\Support\ProgresBoq). */
class BoqItem extends Model
{
    protected $table = 'boq_item';

    protected $fillable = ['proyek_tahap_id', 'kode', 'uraian', 'satuan', 'volume', 'harga', 'modal_material', 'modal_jasa', 'urutan'];

    protected $casts = ['volume' => 'float', 'harga' => 'float', 'modal_material' => 'float', 'modal_jasa' => 'float'];

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }

    public function opname(): HasMany
    {
        return $this->hasMany(BoqOpname::class);
    }

    public function nilai(): float
    {
        return round(($this->volume ?? 0) * ($this->harga ?? 0), 2);
    }

    public function modal(): float
    {
        return (float) ($this->modal_material ?? 0) + (float) ($this->modal_jasa ?? 0);
    }

    /** "D1 · Beton K-350 …" untuk pilihan & rekomendasi. */
    public function label(): string
    {
        return $this->kode.' · '.$this->uraian;
    }
}
