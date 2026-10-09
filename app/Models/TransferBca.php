<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu batch file Multi Auto-Transfer BCA yang dibuat dari menu Transfer Massal BCA. */
class TransferBca extends Model
{
    protected $table = 'transfer_bca';

    protected $fillable = ['tanggal_efektif', 'rekening_debet', 'jumlah', 'total', 'isi', 'user_id'];

    protected $casts = ['tanggal_efektif' => 'date', 'isi' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Transaction ID per baris: MNP + yymmdd + nomor batch (4) + urutan (3), maks. 18 karakter & unik. */
    public function idTransaksi(int $urut): string
    {
        return 'MNP'.$this->created_at->format('ymd').str_pad((string) $this->id, 4, '0', STR_PAD_LEFT).str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }
}
