<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Jenis, Part Number, Merk & Ori/KW satu item sparepart (nama baku × satuan), diisi Admin Plant di Database Sparepart. Bertahan saat sparepart_beli diimpor ulang. */
class SparepartItem extends Model
{
    protected $table = 'sparepart_item';

    protected $fillable = ['nama_item', 'satuan', 'jenis', 'part_number', 'merk', 'kualitas', 'catatan', 'user_id'];

    public const KUALITAS = ['ori' => 'Ori', 'kw' => 'KW'];

    public static function kunci(?string $nama, ?string $satuan): string
    {
        return $nama.'|'.($satuan ?? '');
    }
}
