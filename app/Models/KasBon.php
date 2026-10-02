<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KasBon extends Model
{
    protected $table = 'kas_bon';

    protected $fillable = ['kas_transfer_id', 'tanggal', 'baris', 'nominal', 'pic', 'keterangan', 'gl', 'kode_gl_id', 'kode_gl_ditebak', 'kode_bon', 'no_id', 'id_transaksi'];

    protected $casts = ['tanggal' => 'date', 'kode_gl_ditebak' => 'boolean'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(KasTransfer::class, 'kas_transfer_id');
    }

    public function kodeGl(): BelongsTo
    {
        return $this->belongsTo(KodeGl::class);
    }
}
