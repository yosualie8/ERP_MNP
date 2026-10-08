<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UjPengajuanDetail extends Model
{
    protected $table = 'uj_pengajuan_detail';

    protected $fillable = ['uj_pengajuan_id', 'urut', 'nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do',
        'konfirmasi', 'temuan', 'status', 'id_uj', 'realisasi_pada', 'realisasi_oleh'];

    protected $casts = ['temuan' => 'array', 'realisasi_pada' => 'datetime'];

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(UjPengajuan::class, 'uj_pengajuan_id');
    }
}
