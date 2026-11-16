<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu baris pembelian sparepart (Database Sparepart, menu Plant & Sparepart) — diisi mnp:impor-sparepart, tidak diubah di web. */
class SparepartBeli extends Model
{
    protected $table = 'sparepart_beli';

    protected $guarded = [];

    protected $casts = ['tanggal' => 'date', 'ditarik' => 'date', 'qty' => 'float', 'harga_satuan' => 'float', 'total' => 'float'];

    /** Kategori yang bukan satu item (tidak masuk daftar harga per item, tetap tampil di riwayat). */
    public const BUKAN_ITEM = ['Paket (beberapa item sekaligus)', 'Lain-lain (tidak dirinci)', 'Belum dikenali'];

    public const PAKET = 'Paket (beberapa item sekaligus)';

    /** Urutan kategori di daftar (sama dengan Excel Daftar Harga Sparepart). */
    public const URUT_KATEGORI = ['Ban & Roda', 'Filter', 'Oli & Pelumas', 'Rem & Kopling', 'Kelistrikan & Lampu', 'Mesin & Bahan Bakar', 'AC & Pendingin',
        'Hidrolik & Selang', 'Kaki-kaki & Transmisi', 'Undercarriage & Attachment', 'Body & Kabin', 'Baut, Mur & Pengikat', 'Las & Gas',
        'Alat Kerja & APD', 'Bahan Habis Pakai', 'Jasa Ban', 'Jasa Bengkel', 'Ongkir & Transport', 'Paket (beberapa item sekaligus)',
        'Lain-lain (tidak dirinci)', 'Belum dikenali'];
}
