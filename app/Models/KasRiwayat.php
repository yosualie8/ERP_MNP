<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KasRiwayat extends Model
{
    protected $table = 'kas_riwayat';

    protected $fillable = ['aksi', 'lembar', 'baris_awal', 'baris_akhir', 'ringkasan', 'isi', 'user_id'];

    protected $casts = ['isi' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
