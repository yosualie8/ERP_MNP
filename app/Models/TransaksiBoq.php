<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kode BOQ satu transaksi: sumber kas (kunci kas_alokasi) atau bca (kunci mutasi_pt_tanda). */
class TransaksiBoq extends Model
{
    protected $table = 'transaksi_boq';

    protected $fillable = ['sumber', 'kunci', 'boq_item_id', 'user_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }
}
