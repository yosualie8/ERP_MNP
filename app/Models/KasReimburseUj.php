<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu transaksi detail Kas Harian yang berasal dari satu baris Kas UJ yang direimburse. */
class KasReimburseUj extends Model
{
    protected $table = 'kas_reimburse_uj';

    protected $fillable = ['no_id', 'no_id_transfer', 'tanggal_reimburse', 'id_uj', 'baris_uj', 'tanggal_uj', 'jenis_kendaraan', 'no_mobil',
        'no_do', 'galian', 'kategori', 'nominal', 'user_id'];

    protected $casts = ['tanggal_reimburse' => 'date', 'tanggal_uj' => 'date'];
}
