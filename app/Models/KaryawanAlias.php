<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nama lain / ejaan sopir di Kas UJ (lihat migrasi master_sopir). karyawan_id kosong = nama itu bukan sopir. */
class KaryawanAlias extends Model
{
    protected $table = 'karyawan_alias';

    protected $fillable = ['karyawan_id', 'nama', 'kunci', 'user_id'];

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }
}
