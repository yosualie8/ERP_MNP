<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Proyek induk (ASG, Infra, Pantai Mutiara 2, …); biayanya dikelompokkan per tahap. */
class Proyek extends Model
{
    protected $table = 'proyek';

    protected $fillable = ['nama', 'urutan']; // cost center dimiliki tiap tahap, bukan induk

    public function tahap(): HasMany
    {
        return $this->hasMany(ProyekTahap::class);
    }
}
