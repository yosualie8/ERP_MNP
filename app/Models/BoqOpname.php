<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Opname volume terpasang (kumulatif) satu item BOQ pada satu tanggal. */
class BoqOpname extends Model
{
    protected $table = 'boq_opname';

    protected $fillable = ['boq_item_id', 'tanggal', 'volume', 'catatan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'volume' => 'float'];
}
