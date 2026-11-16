<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris sparepart di Pengajuan Sparepart. */
class PengajuanSparepartDetail extends Model
{
    protected $table = 'pengajuan_sparepart_detail';

    protected $fillable = ['pengajuan_sparepart_id', 'urut', 'nama_item', 'jenis', 'satuan', 'part_number', 'merk', 'qty', 'harga', 'subtotal', 'harga_saran', 'dasar_harga', 'keterangan'];

    protected $casts = ['qty' => 'float', 'harga' => 'float', 'subtotal' => 'float', 'harga_saran' => 'float'];
}
