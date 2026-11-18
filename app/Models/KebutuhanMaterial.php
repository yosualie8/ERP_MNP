<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu material yang dibutuhkan proyek (dari rekaman suara / ketikan di menu Kebutuhan Material). */
class KebutuhanMaterial extends Model
{
    protected $table = 'kebutuhan_material';

    protected $fillable = ['proyek_tahap_id', 'rekaman_id', 'tanggal', 'nama_item', 'qty', 'satuan', 'harga', 'harga_saran', 'dasar_harga', 'keterangan', 'dicek', 'urut', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'qty' => 'float', 'harga' => 'float', 'harga_saran' => 'float', 'dicek' => 'boolean'];

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }

    public function rekaman(): BelongsTo
    {
        return $this->belongsTo(KebutuhanMaterialRekaman::class, 'rekaman_id');
    }
}
