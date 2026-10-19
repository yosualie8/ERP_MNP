<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto satu kiriman Lapor Lapangan: penuh sementara di server lalu dipindah ke Google Drive; pratinjau kecil tetap di server. */
class LaporFoto extends Model
{
    protected $table = 'lapor_foto';

    protected $fillable = ['lapor_id', 'urutan', 'sumber', 'kamera', 'path', 'path_kecil', 'ukuran', 'drive_file_id', 'drive_link', 'status_drive', 'percobaan', 'pesan_drive'];

    public function lapor(): BelongsTo
    {
        return $this->belongsTo(Lapor::class);
    }
}
