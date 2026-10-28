<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris mutasi kantong Jago dari PDF riwayat transaksi (unik per kantong + ID# bank). Lihat App\Support\MutasiJago. */
class JagoMutasi extends Model
{
    protected $table = 'jago_mutasi';

    protected $fillable = ['kantong', 'id_bank', 'waktu', 'nama', 'bank', 'rekening', 'jenis', 'arah', 'biaya', 'catatan', 'jumlah', 'saldo', 'jago_unggah_id', 'dipakai', 'dipakai_pada', 'dipakai_oleh'];

    protected $casts = ['waktu' => 'datetime', 'biaya' => 'boolean', 'jumlah' => 'float', 'saldo' => 'float', 'dipakai_pada' => 'datetime'];

    public function unggah(): BelongsTo
    {
        return $this->belongsTo(JagoUnggah::class, 'jago_unggah_id');
    }
}
