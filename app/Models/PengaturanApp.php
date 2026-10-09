<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan aplikasi sederhana (kunci → nilai), mis. rekening debet BCA PT untuk Transfer Massal BCA. */
class PengaturanApp extends Model
{
    protected $table = 'pengaturan_app';

    protected $primaryKey = 'kunci';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kunci', 'nilai', 'user_id'];

    public static function ambil(string $kunci, ?string $bawaan = null): ?string
    {
        return self::find($kunci)?->nilai ?? $bawaan;
    }

    public static function simpan(string $kunci, ?string $nilai, ?int $userId = null): void
    {
        self::updateOrCreate(['kunci' => $kunci], ['nilai' => $nilai, 'user_id' => $userId]);
    }
}
