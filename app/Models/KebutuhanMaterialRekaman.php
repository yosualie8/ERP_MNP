<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu rekaman suara (atau ketikan) di menu Kebutuhan Material; satu rekaman bisa menghasilkan beberapa item. */
class KebutuhanMaterialRekaman extends Model
{
    protected $table = 'kebutuhan_material_rekaman';

    protected $fillable = ['uid', 'proyek_tahap_id', 'sumber', 'audio', 'durasi', 'transkrip', 'status', 'galat', 'direkam_pada', 'user_id'];

    protected $casts = ['direkam_pada' => 'datetime'];

    public function item(): HasMany
    {
        return $this->hasMany(KebutuhanMaterial::class, 'rekaman_id');
    }
}
