<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu batch reimburse Kas Harian yang disetujui owner lewat menu Reimburse Kas. */
class KasReimburse extends Model
{
    protected $table = 'kas_reimburse';

    protected $fillable = ['tanggal', 'target', 'total', 'jumlah_transfer', 'jumlah_baris', 'isi', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'isi' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
