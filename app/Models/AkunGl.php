<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AkunGl extends Model
{
    protected $table = 'akun_gl';

    protected $fillable = ['nama', 'kelompok'];

    public const KELOMPOK = ['HPP', 'Gaji & Tunjangan', 'Biaya', 'Piutang', 'Lainnya'];
}
