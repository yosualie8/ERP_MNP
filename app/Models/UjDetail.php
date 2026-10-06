<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UjDetail extends Model
{
    protected $table = 'uj_detail';

    protected $fillable = ['uj_transaksi_id', 'baris', 'id_uj', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan',
        'no_mobil', 'no_do', 'status', 'tanggal_reimburse', 'bon', 'biaya_transfer'];

    protected $casts = ['tanggal' => 'date', 'tanggal_reimburse' => 'date', 'biaya_transfer' => 'boolean'];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(UjTransaksi::class, 'uj_transaksi_id');
    }
}
