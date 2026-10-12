<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Proyek induk (ASG, Infra, Pantai Mutiara 2, …); biayanya dikelompokkan per tahap. */
class Proyek extends Model
{
    protected $table = 'proyek';

    protected $fillable = ['nama', 'cost_center', 'urutan'];

    public function tahap(): HasMany
    {
        return $this->hasMany(ProyekTahap::class);
    }
}
