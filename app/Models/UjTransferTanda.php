<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Penanda transfer Pengajuan UJ (lihat migrasi create_uj_transfer_tanda): pengajuan mana yang dibayar transfer bernominal & berjam ini. */
class UjTransferTanda extends Model
{
    protected $table = 'uj_transfer_tanda';

    protected $fillable = ['uj_reimburse_id', 'waktu', 'nominal', 'catatan', 'kas_no_id', 'user_id'];

    /** Batch Reimburse UJ yang menjadi acuan nominal & waktu transfer (opsional). */
    public function reimburse(): BelongsTo
    {
        return $this->belongsTo(UjReimburse::class, 'uj_reimburse_id');
    }

    protected $casts = ['waktu' => 'datetime'];

    public function pengajuan(): BelongsToMany
    {
        return $this->belongsToMany(UjPengajuan::class, 'uj_transfer_tanda_pengajuan')->orderBy('uj_pengajuan.id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kode(): string
    {
        return 'TF-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function selesai(): bool
    {
        return $this->kas_no_id !== null;
    }
}
