<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penandaan manual satu mutasi Rekening PT (BCA): dihitung / tidak, cost center, tahap (lihat App\Support\TandaMutasiPt). */
class MutasiPtTanda extends Model
{
    protected $table = 'mutasi_pt_tanda';

    protected $fillable = ['kunci', 'status', 'cost_center', 'proyek_tahap_id', 'catatan', 'user_id'];

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }
}
