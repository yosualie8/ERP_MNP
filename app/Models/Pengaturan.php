<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    protected $fillable = ['kunci', 'nilai', 'diubah_oleh'];

    protected $casts = ['nilai' => 'encrypted'];

    protected $hidden = ['nilai'];

    public const API_KEY_CLAUDE = 'anthropic_api_key';

    public static function ambil(string $kunci): ?string
    {
        return rescue(fn () => self::firstWhere('kunci', $kunci)?->nilai, null, false);
    }

    public static function simpan(string $kunci, ?string $nilai, ?int $userId = null): void
    {
        self::updateOrCreate(['kunci' => $kunci], ['nilai' => $nilai, 'diubah_oleh' => $userId]);
    }
}
