<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** QR login sekali pakai untuk perangkat lain (token hanya disimpan sebagai hash SHA-256). */
class LoginPerangkat extends Model
{
    protected $table = 'login_perangkat';

    protected $fillable = ['user_id', 'token_hash', 'kedaluwarsa_pada', 'ip_buat', 'dipakai_pada', 'ip_pakai', 'perangkat', 'sesi_id', 'dikeluarkan_pada'];

    protected $casts = ['kedaluwarsa_pada' => 'datetime', 'dipakai_pada' => 'datetime', 'dikeluarkan_pada' => 'datetime'];

    public const MENIT_BERLAKU = 5;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Token yang masih bisa dipakai (belum dipakai, belum kedaluwarsa). */
    public static function berlaku(string $token): ?self
    {
        return self::where('token_hash', self::hash($token))->whereNull('dipakai_pada')->where('kedaluwarsa_pada', '>', now())->first();
    }

    /** Sesi perangkat ini masih ada (belum dikeluarkan & belum lewat umur sesi). */
    public function sesiAktif(): bool
    {
        return $this->dipakai_pada && $this->sesi_id && ! $this->dikeluarkan_pada
            && $this->dipakai_pada->gt(now()->subMinutes((int) config('session.lifetime')));
    }
}
