<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Transfer di lembar "Kas Seabank" (uang jalan dump truck): baris master + detail di bawahnya. */
class UjTransaksi extends Model
{
    protected $table = 'uj_transaksi';

    protected $fillable = ['baris', 'baris_akhir', 'tanggal', 'bank', 'rekening', 'nama', 'nominal', 'no_uj', 'biaya'];

    protected $casts = ['tanggal' => 'date'];

    public function detail(): HasMany
    {
        return $this->hasMany(UjDetail::class)->orderBy('baris');
    }

    /** Ada baris yang sudah direimburse (kolom Tanggal Reimburse terisi) → tidak boleh diubah lewat aplikasi. */
    public function sudahReimburse(): bool
    {
        return $this->detail->contains(fn (UjDetail $d) => $d->tanggal_reimburse !== null);
    }
}
