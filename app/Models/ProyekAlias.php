<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nama lama / variasi tulisan suatu tahap di Ritasi, Penggunaan BBM, Cost Project, atau Kas Harian. */
class ProyekAlias extends Model
{
    protected $table = 'proyek_alias';

    protected $fillable = ['proyek_tahap_id', 'sumber', 'nama', 'kunci'];

    public const SUMBER = ['ritasi' => 'Ritasi', 'bbm' => 'Penggunaan BBM', 'cost_project' => 'Cost Project', 'kas' => 'Kas Harian (kode tahap)', 'kas_cc' => 'Kas Harian (cost center)'];

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'proyek_tahap_id');
    }

    /** Kunci pencocokan: spasi dirapikan, huruf kecil. */
    public static function kunci(?string $nama): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $nama)));
    }
}
