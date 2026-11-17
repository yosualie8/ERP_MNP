<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Satu baris sparepart dalam Pengajuan Sparepart. */
class PengajuanSparepartDetail extends Model
{
    protected $table = 'pengajuan_sparepart_detail';

    protected $fillable = ['pengajuan_sparepart_id', 'urut', 'nama_item', 'jenis', 'satuan', 'part_number', 'merk', 'supplier', 'qty', 'harga', 'subtotal', 'harga_saran', 'dasar_harga', 'keterangan',
        'progres', 'progres_pada', 'progres_oleh', 'asal_detail_id'];

    protected $casts = ['qty' => 'float', 'harga' => 'float', 'subtotal' => 'float', 'harga_saran' => 'float', 'progres_pada' => 'datetime'];

    /**
     * Status per baris (revisi Admin Plant 17 Nov 2026), urut alur barang. Kosong = Belum. "diulang" tidak dipilih manual:
     * otomatis saat baris dibawa ke pengajuan berikutnya (Ajukan ulang yang belum di-ACC).
     */
    public const PROGRES = ['disetujui' => 'Disetujui', 'dibeli' => 'Dibeli', 'terpasang' => 'Terpasang', 'ditolak' => 'Ditolak'];

    public const DIULANG = 'diulang';

    public static function labelProgres(?string $k): string
    {
        return $k === self::DIULANG ? 'Diajukan ulang' : (self::PROGRES[$k] ?? 'Belum');
    }

    public function penanda(): BelongsTo
    {
        return $this->belongsTo(User::class, 'progres_oleh');
    }

    /** Baris di pengajuan berikutnya yang membawa baris ini (Ajukan ulang). */
    public function lanjutan(): HasOne
    {
        return $this->hasOne(self::class, 'asal_detail_id')->orderByDesc('id');
    }

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(PengajuanSparepart::class, 'pengajuan_sparepart_id');
    }
}
