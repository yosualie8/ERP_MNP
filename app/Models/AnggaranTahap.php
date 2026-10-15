<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris anggaran tahap: komponen × volume × harga (atau % dari total omset). */
class AnggaranTahap extends Model
{
    protected $table = 'anggaran_tahap';

    protected $fillable = ['proyek_tahap_id', 'komponen_id', 'uraian', 'volume', 'harga', 'persen', 'urutan', 'user_id'];

    protected $casts = ['volume' => 'float', 'harga' => 'float', 'persen' => 'float'];

    public function komponen(): BelongsTo
    {
        return $this->belongsTo(KomponenAnggaran::class, 'komponen_id');
    }
}
