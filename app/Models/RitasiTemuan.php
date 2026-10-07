<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Temuan validasi (FLAG) satu rit + konfirmasi admin saat input. */
class RitasiTemuan extends Model
{
    protected $table = 'ritasi_temuan';

    protected $fillable = ['tahap', 'no_seri', 'no_do', 'aturan', 'prioritas', 'pesan', 'konfirmasi', 'user_id'];
}
