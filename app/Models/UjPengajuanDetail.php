<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UjPengajuanDetail extends Model
{
    protected $table = 'uj_pengajuan_detail';

    protected $fillable = ['uj_pengajuan_id', 'urut', 'nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do',
        'konfirmasi', 'temuan', 'status', 'id_uj', 'jenis_realisasi', 'alasan', 'realisasi', 'realisasi_pada', 'realisasi_oleh'];

    protected $casts = ['temuan' => 'array', 'realisasi' => 'array', 'realisasi_pada' => 'datetime'];

    /** Status detail: menunggu → terealisasi (sesuai / dengan penyesuaian) | dialihkan | batal. */
    public const STATUS = ['menunggu' => '⏳ Menunggu', 'terealisasi' => '✓ Terealisasi', 'dialihkan' => '↪ Dialihkan', 'batal' => 'Dibatalkan'];

    /** Realisasinya tidak sama dengan pengajuan (penyesuaian atau dialihkan). */
    public function berbeda(): bool
    {
        return in_array($this->jenis_realisasi, ['penyesuaian', 'dialihkan'], true);
    }

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(UjPengajuan::class, 'uj_pengajuan_id');
    }
}
