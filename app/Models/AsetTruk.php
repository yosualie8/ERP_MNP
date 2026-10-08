<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Truk milik PT MNP (menu Data Aset). */
class AsetTruk extends Model
{
    protected $table = 'aset_truk';

    public const STATUS = ['aktif' => 'Aktif', 'perbaikan' => 'Perbaikan', 'tidak_aktif' => 'Tidak aktif', 'dijual' => 'Dijual / keluar'];

    protected $fillable = ['no_lambung', 'plat', 'jenis', 'tahun', 'no_rangka', 'no_mesin', 'stnk_berlaku', 'kir_berlaku', 'status', 'driver_tetap', 'catatan', 'user_id'];

    protected $casts = ['stnk_berlaku' => 'date', 'kir_berlaku' => 'date'];

    public function labelStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }
}
