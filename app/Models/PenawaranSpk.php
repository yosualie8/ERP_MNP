<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu lampiran PDF penawaran (email terkirim) atau SPK (email dari admin SPK ASG) — lihat App\Support\SinkronGmail. */
class PenawaranSpk extends Model
{
    protected $table = 'penawaran_spk';

    protected $guarded = ['id'];

    protected $casts = [
        'waktu_email' => 'datetime',
        'tanggal' => 'date',
        'volume' => 'float',
        'harga' => 'float',
        'nilai' => 'integer',
        'dicek_at' => 'datetime',
    ];

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }

    public function saranTahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'saran_tahap_id');
    }

    public function penawaran(): BelongsTo
    {
        return $this->belongsTo(self::class, 'penawaran_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
