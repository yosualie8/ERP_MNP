<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arti satu tulisan UNIT di lembar Penggunaan BBM: pemakaian aset, solar masuk, pindah lokasi, susut, atau perlu dicek. */
class BbmUnitPeta extends Model
{
    protected $table = 'bbm_unit_peta';

    protected $fillable = ['nama', 'kunci', 'jenis', 'aset_id', 'sumber', 'catatan', 'user_id'];

    public const JENIS = ['pakai' => 'Pemakaian aset', 'masuk' => 'Solar masuk', 'pindah' => 'Solar pindah lokasi', 'susut' => 'Solar rusak / susut', 'cek' => 'Perlu dicek'];

    public static function kunci(?string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));
    }

    public function aset(): BelongsTo
    {
        return $this->belongsTo(AsetTruk::class, 'aset_id');
    }
}
