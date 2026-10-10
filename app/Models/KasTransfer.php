<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KasTransfer extends Model
{
    protected $table = 'kas_transfer';

    protected $fillable = ['kas_bulan_id', 'tanggal', 'baris', 'no_id','nama_tujuan', 'no_rek_tujuan', 'bank_tujuan', 'keterangan', 'debet', 'kredit', 'saldo', 'kode_gl_id', 'kode_gl_ditebak'];

    protected $casts = ['tanggal' => 'date'];

    public function kasBulan(): BelongsTo
    {
        return $this->belongsTo(KasBulan::class);
    }

    /** Kode GL uang masuk (uang keluar ber-Kode GL per transaksi detail). */
    public function kodeGl(): BelongsTo
    {
        return $this->belongsTo(KodeGl::class);
    }

    public function bon(): HasMany
    {
        return $this->hasMany(KasBon::class)->orderBy('baris');
    }
}
