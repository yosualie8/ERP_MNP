<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto progres pekerjaan satu Pengajuan Sparepart (owner 17 Nov 2026): penuh + pratinjau kecil di server. */
class PengajuanSparepartFoto extends Model
{
    protected $table = 'pengajuan_sparepart_foto';

    protected $fillable = ['pengajuan_sparepart_id', 'keterangan', 'path', 'path_kecil', 'ukuran', 'user_id'];

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(PengajuanSparepart::class, 'pengajuan_sparepart_id');
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
