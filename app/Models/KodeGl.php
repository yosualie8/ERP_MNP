<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KodeGl extends Model
{
    protected $table = 'kode_gl';

    protected $fillable = ['kode_asli', 'akun_gl_id', 'cost_center_id', 'ref', 'dikoreksi_manual'];

    protected $casts = ['dikoreksi_manual' => 'boolean'];

    public function akun(): BelongsTo
    {
        return $this->belongsTo(AkunGl::class, 'akun_gl_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function bon(): HasMany
    {
        return $this->hasMany(KasBon::class);
    }

    /** Uang masuk ber-Kode GL ini (mis. Penerimaan Talangan). */
    public function transferMasuk(): HasMany
    {
        return $this->hasMany(KasTransfer::class);
    }
}
