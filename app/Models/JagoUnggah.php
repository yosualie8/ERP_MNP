<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu PDF riwayat transaksi kantong Jago yang diunggah (file asli di storage/app/mutasi-jago/{sha256}.pdf). */
class JagoUnggah extends Model
{
    protected $table = 'jago_unggah';

    protected $fillable = ['kantong', 'nomor_kantong', 'pemilik', 'dari', 'sampai', 'nama_file', 'sha256', 'jumlah_baris', 'baris_baru', 'peringatan', 'user_id'];

    protected $casts = ['dari' => 'date', 'sampai' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
