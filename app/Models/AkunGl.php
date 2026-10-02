<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AkunGl extends Model
{
    protected $table = 'akun_gl';

    protected $fillable = ['nama', 'kelompok'];

    /** "Non-biaya" = pengembalian talangan, salah transfer/refund, pindah kantong: uang keluar yang bukan beban usaha. */
    public const KELOMPOK = ['HPP', 'Gaji & Tunjangan', 'Biaya', 'Piutang', 'Lainnya', 'Non-biaya'];

    public function kodeGl(): HasMany
    {
        return $this->hasMany(KodeGl::class);
    }
}
