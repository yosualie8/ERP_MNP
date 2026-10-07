<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Temuan validasi (FLAG) satu baris ID UJ + konfirmasi admin saat input. */
class UjTemuan extends Model
{
    protected $table = 'uj_temuan';

    protected $fillable = ['id_uj', 'aturan', 'prioritas', 'pesan', 'konfirmasi', 'status', 'catatan_admin', 'user_id'];
}
