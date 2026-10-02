<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    protected $table = 'cost_center';

    protected $fillable = ['kode', 'nama'];
}
