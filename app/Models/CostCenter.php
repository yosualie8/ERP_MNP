<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    protected $table = 'cost_center';

    protected $fillable = ['kode', 'nama'];

    public function kodeGl(): HasMany
    {
        return $this->hasMany(KodeGl::class);
    }
}
