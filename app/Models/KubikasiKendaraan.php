<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kubikasi standar (m³) per jenis kendaraan: nilai awal kolom Kubikasi saat input ritasi (admin boleh mengganti). */
class KubikasiKendaraan extends Model
{
    protected $table = 'kubikasi_kendaraan';

    protected $fillable = ['jenis', 'kubikasi', 'user_id'];

    protected $casts = ['kubikasi' => 'float'];

    /** @return array<string, float> jenis (huruf kecil) → kubikasi */
    public static function peta(): array
    {
        return self::pluck('kubikasi', 'jenis')->mapWithKeys(fn ($k, $j) => [mb_strtolower($j) => (float) $k])->all();
    }

    /** Kubikasi standar untuk jenis ini; tanpa jenis = 0, jenis belum ada di master = null. */
    public static function untuk(?string $jenis, ?array $peta = null): ?float
    {
        $jenis = mb_strtolower(trim((string) $jenis));

        return $jenis === '' ? 0.0 : (($peta ?? self::peta())[$jenis] ?? null);
    }
}
