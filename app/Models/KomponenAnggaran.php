<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master komponen anggaran (HPP / omset) — lihat App\Support\Anggaran untuk sumber realisasi & cara hitung. */
class KomponenAnggaran extends Model
{
    protected $table = 'komponen_anggaran';

    protected $fillable = ['kode', 'jenis', 'nama', 'satuan', 'sumber', 'cara', 'tunda_realisasi', 'nilai_bawaan', 'tipe_bawaan', 'urutan', 'aktif', 'user_id'];

    protected $casts = ['tunda_realisasi' => 'boolean', 'nilai_bawaan' => 'float', 'tipe_bawaan' => 'array', 'aktif' => 'boolean'];

    public const JENIS = ['omset' => 'Omset', 'hpp' => 'HPP'];
}
