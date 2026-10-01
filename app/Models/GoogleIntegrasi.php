<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleIntegrasi extends Model
{
    protected $table = 'google_integrasi';

    protected $fillable = ['email', 'refresh_token', 'access_token', 'access_token_kedaluwarsa', 'izin', 'user_id'];

    protected $hidden = ['refresh_token', 'access_token'];

    protected $casts = [
        'refresh_token' => 'encrypted',
        'access_token' => 'encrypted',
        'access_token_kedaluwarsa' => 'datetime',
        'izin' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function aktif(): ?self
    {
        return self::latest('id')->first();
    }
}
