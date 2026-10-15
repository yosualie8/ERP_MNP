<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Alokasi satu pos biaya umum di satu bulan ke tahap-tahap tujuan yang dipilih (lihat App\Support\Overhead). */
class AlokasiOverhead extends Model
{
    protected $table = 'alokasi_overhead';

    protected $fillable = ['bulan', 'pos_tahap_id', 'metode', 'tahap_ids', 'user_id'];

    protected $casts = ['tahap_ids' => 'array'];

    public const METODE = ['omset' => 'Proporsional omset', 'rata' => 'Rata per proyek'];
}
