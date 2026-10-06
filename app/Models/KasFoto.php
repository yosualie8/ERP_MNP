<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto bon. sumber "kas": no_id = NO ID transfer Kas Harian; sumber "uj": no_id = nomor ID UJ master di Kas Seabank. */
class KasFoto extends Model
{
    protected $table = 'kas_foto';

    protected $fillable = ['sumber', 'no_id', 'lembar', 'path', 'path_kecil', 'drive_file_id', 'drive_link', 'status_drive', 'percobaan', 'pesan_drive', 'nama_asli', 'ukuran', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeKas(Builder $q): Builder
    {
        return $q->where('sumber', 'kas');
    }

    public function scopeUj(Builder $q): Builder
    {
        return $q->where('sumber', 'uj');
    }
}
