<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koneksi Google yang disimpan aplikasi:
 * - sheets: akun pemilik sheet Kas Harian (izin Sheets + Drive baca-saja)
 * - foto: akun perusahaan untuk menyimpan foto bon di Google Drive (izin Drive)
 */
class GoogleIntegrasi extends Model
{
    protected $table = 'google_integrasi';

    protected $fillable = ['keperluan', 'email', 'refresh_token', 'access_token', 'access_token_kedaluwarsa', 'izin', 'user_id'];

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
        return self::where('keperluan', 'sheets')->latest('id')->first();
    }

    public static function foto(): ?self
    {
        return self::where('keperluan', 'foto')->latest('id')->first();
    }
}
