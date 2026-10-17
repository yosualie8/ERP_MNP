<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengalihan UJ antar proyek ritasi (alih / kembali / pakai) — lihat migrasi create_uj_pengalihan. */
class UjPengalihan extends Model
{
    protected $table = 'uj_pengalihan';

    protected $fillable = ['jenis', 'id_uj', 'no_mobil', 'nama', 'dari_tahap_id', 'ke_tahap_id', 'tanggal', 'nominal', 'peruntukan', 'keterangan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'nominal' => 'float'];

    public const JENIS = ['alih' => 'Dialihkan', 'kembali' => 'Dikembalikan', 'pakai' => 'Dipakai'];

    public const PERUNTUKAN = ['mob' => 'Uang MOB (pindah lokasi)', 'uj' => 'Dipotong dari UJ berikutnya', 'lain' => 'Lain-lain'];

    public function dari(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'dari_tahap_id');
    }

    public function ke(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'ke_tahap_id');
    }
}
