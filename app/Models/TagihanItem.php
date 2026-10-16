<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Volume & nilai satu item BOQ dalam satu tagihan progres. */
class TagihanItem extends Model
{
    protected $table = 'tagihan_item';

    protected $fillable = ['tagihan_proyek_id', 'boq_item_id', 'volume', 'nilai'];

    protected $casts = ['volume' => 'float', 'nilai' => 'float'];
}
