<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KasTransfer extends Model
{
    protected $table = 'kas_transfer';

    protected $fillable = ['kas_bulan_id', 'tanggal', 'baris', 'nama_tujuan', 'no_rek_tujuan', 'bank_tujuan', 'keterangan', 'debet', 'kredit', 'saldo'];

    protected $casts = ['tanggal' => 'date'];

    public function kasBulan(): BelongsTo
    {
        return $this->belongsTo(KasBulan::class);
    }

    public function bon(): HasMany
    {
        return $this->hasMany(KasBon::class)->orderBy('baris');
    }
}
