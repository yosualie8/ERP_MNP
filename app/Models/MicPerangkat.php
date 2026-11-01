<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Laptop yang dipasangkan sebagai penerima Mic ke Laptop (aplikasi Windows "Mic HP"). */
class MicPerangkat extends Model
{
    protected $table = 'mic_perangkat';

    protected $fillable = ['user_id', 'nama', 'kunci_hash', 'terakhir_aktif'];

    protected $casts = ['terakhir_aktif' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $kunci): string
    {
        return hash('sha256', $kunci);
    }
}
