<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master Supplier (menu Plant & Sparepart). Rekening dibaca dari Daftar Rekening (rekening.nama = nama), pembelian dari sparepart_beli.supplier. */
class Supplier extends Model
{
    protected $table = 'supplier';

    protected $fillable = ['nama', 'kontak', 'telepon', 'alamat', 'barang', 'catatan', 'aktif', 'user_id'];

    protected $casts = ['aktif' => 'boolean'];
}
