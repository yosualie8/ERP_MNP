<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transfer Kas Harian (NO ID + tanggal) yang membiayai sebuah Pengajuan UJ; data transfernya disalin saat ditautkan. */
class UjPengajuanTransfer extends Model
{
    protected $table = 'uj_pengajuan_transfer';

    protected $fillable = ['uj_pengajuan_id', 'kas_no_id', 'kas_tanggal', 'nominal', 'nama_tujuan', 'keterangan', 'user_id'];

    protected $casts = ['kas_tanggal' => 'date'];

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(UjPengajuan::class, 'uj_pengajuan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ID transaksi seperti di Kas Harian / Mutasi Reimburse, mis. "261008-Jago-31459". */
    public function idKas(): string
    {
        return $this->kas_tanggal->format('ymd').'-Jago-'.$this->kas_no_id;
    }
}
