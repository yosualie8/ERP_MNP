<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ubahan admin atas tunjangan mingguan operator (basic / transport / uang kesehatan) untuk satu orang satu minggu
 * (owner 3 Nov 2026: bawaan dibayar penuh, admin boleh mengatur). Kolom null = ikut tarif Master Jabatan.
 */
class TunjanganMingguan extends Model
{
    protected $table = 'tunjangan_mingguan';

    protected $fillable = ['karyawan_id', 'senin', 'basic_mingguan', 'transport_mingguan', 'kesehatan_mingguan', 'catatan', 'user_id'];

    protected $casts = ['senin' => 'date'];
}
