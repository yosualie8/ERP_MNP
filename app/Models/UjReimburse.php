<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu batch reimburse uang jalan; isi = salinan baris yang direimburse (untuk Excel & riwayat). */
class UjReimburse extends Model
{
    protected $table = 'uj_reimburse';

    protected $fillable = ['tanggal', 'target', 'total', 'jumlah_transfer', 'jumlah_baris', 'isi', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'isi' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
