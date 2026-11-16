<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu catatan angka HM / KM aset pada satu tanggal (Dashboard HM & KM). Satuan mengikuti kategori aset (AsetMeter::satuan). */
class AsetMeter extends Model
{
    protected $table = 'aset_meter';

    protected $fillable = ['aset_id', 'tanggal', 'nilai', 'reset', 'catatan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'nilai' => 'float', 'reset' => 'boolean'];

    /** Kategori aset yang diukur dengan hour meter (jam); lainnya kilometer. */
    public const KATEGORI_HM = ['alat_berat', 'mesin'];

    public static function satuan(?string $kategori): string
    {
        return in_array($kategori, self::KATEGORI_HM, true) ? 'HM' : 'KM';
    }

    public function aset(): BelongsTo
    {
        return $this->belongsTo(AsetTruk::class, 'aset_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
