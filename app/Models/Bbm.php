<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris lembar "Penggunaan BBM": pemakaian solar oleh aset, solar masuk, pindah, susut, atau stock opname. */
class Bbm extends Model
{
    protected $table = 'bbm';

    protected $fillable = ['baris', 'tanggal', 'unit', 'jenis', 'aset_id', 'hm_awal', 'hm_akhir', 'total_hm', 'masuk', 'keluar', 'stok',
        'jam_mulai', 'istirahat', 'jam_selesai', 'status', 'keterangan', 'rental', 'proyek', 'tahap_ids', 'keterangan_kerja'];

    protected $casts = ['tanggal' => 'date', 'tahap_ids' => 'array', 'hm_awal' => 'float', 'hm_akhir' => 'float', 'total_hm' => 'float',
        'masuk' => 'float', 'keluar' => 'float', 'stok' => 'float', 'rental' => 'float'];

    public function aset(): BelongsTo
    {
        return $this->belongsTo(AsetTruk::class, 'aset_id');
    }
}
