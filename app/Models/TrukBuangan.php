<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tujuan buangan sebuah truk yang diatur admin (menimpa tahap rit terakhirnya). */
class TrukBuangan extends Model
{
    protected $table = 'truk_buangan';

    protected $fillable = ['no_lambung', 'tujuan', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
