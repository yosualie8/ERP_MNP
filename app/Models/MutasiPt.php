<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris mutasi Rekening PT (BCA) — cermin file Drive, hanya dilihat (lihat App\Support\ImporMutasiPt). */
class MutasiPt extends Model
{
    protected $table = 'mutasi_pt';

    protected $fillable = ['baris', 'tanggal', 'keterangan', 'cabang', 'dbcr', 'jumlah', 'saldo'];

    protected $casts = ['tanggal' => 'date', 'jumlah' => 'float', 'saldo' => 'float'];
}
