<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu kali upload file mutasi KlikBCA Bisnis ke Mutasi Rekening PT (lihat App\Support\ImporMutasiBca). */
class MutasiPtUnggah extends Model
{
    protected $table = 'mutasi_pt_unggah';

    protected $fillable = ['nama_file', 'rekening', 'dari', 'sampai', 'baris', 'pending', 'saldo_awal', 'saldo_akhir', 'ringkasan', 'user_id'];

    protected $casts = ['dari' => 'date', 'sampai' => 'date', 'saldo_awal' => 'float', 'saldo_akhir' => 'float', 'ringkasan' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
