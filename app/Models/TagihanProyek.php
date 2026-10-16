<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tagihan progres satu tahap (termin): nomor, tanggal, volume ditagih per item BOQ. */
class TagihanProyek extends Model
{
    protected $table = 'tagihan_proyek';

    protected $fillable = ['proyek_tahap_id', 'nomor', 'tanggal', 'catatan', 'user_id'];

    protected $casts = ['tanggal' => 'date'];

    public function item(): HasMany
    {
        return $this->hasMany(TagihanItem::class);
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }
}
