<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KasFoto extends Model
{
    protected $table = 'kas_foto';

    protected $fillable = ['no_id', 'lembar', 'path', 'nama_asli', 'ukuran', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
