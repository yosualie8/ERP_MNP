<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris mutasi Rekening PT (BCA) — dari upload file KlikBCA Bisnis (ImporMutasiBca) / dulu file Drive (ImporMutasiPt). pending = "PEND" belum dibukukan. */
class MutasiPt extends Model
{
    protected $table = 'mutasi_pt';

    protected $fillable = ['baris', 'tanggal', 'keterangan', 'cabang', 'dbcr', 'jumlah', 'saldo', 'pending', 'unggah_id'];

    protected $casts = ['tanggal' => 'date', 'jumlah' => 'float', 'saldo' => 'float', 'pending' => 'boolean'];
}
