<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KasBulan extends Model
{
    protected $table = 'kas_bulan';

    protected $fillable = ['lembar', 'bulan', 'saldo_awal', 'total_debet', 'total_kredit', 'total_bon', 'saldo_akhir', 'saldo_akhir_sheet', 'catatan', 'diimpor_pada'];

    protected $casts = [
        'bulan' => 'date',
        'catatan' => 'array',
        'diimpor_pada' => 'datetime',
    ];

    public function transfer(): HasMany
    {
        return $this->hasMany(KasTransfer::class)->orderBy('baris');
    }

    /** Rekonsiliasi bersih: saldo cocok dengan sheet dan seluruh bon sama dengan transfernya. */
    public function cocok(): bool
    {
        return empty($this->catatan) && $this->saldo_akhir === $this->saldo_akhir_sheet;
    }
}
