<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu rit dump truck (satu baris lembar "Ritasi"). */
class Ritasi extends Model
{
    protected $table = 'ritasi';

    protected $fillable = ['baris', 'tahap', 'no_seri', 'tanggal', 'jam', 'plat', 'no_lambung', 'no_polisi', 'galian', 'jenis_buangan',
        'jenis_tanah', 'jenis_kendaraan', 'pemilik', 'harga_jual', 'keterangan', 'status_bayar', 'no_do'];

    protected $casts = ['tanggal' => 'date'];

    /** Nama driver dari kolom No Polisi ("B 9231 UIR/Sule" → "Sule"). */
    public function getDriverAttribute(): ?string
    {
        return str_contains((string) $this->no_polisi, '/') ? trim(substr($this->no_polisi, strpos($this->no_polisi, '/') + 1)) ?: null : null;
    }
}
