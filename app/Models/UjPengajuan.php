<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pengajuan uang jalan (belum ditransfer); direalisasikan per detail lewat Input UJ. */
class UjPengajuan extends Model
{
    protected $table = 'uj_pengajuan';

    public const STATUS = ['diajukan' => 'Diajukan', 'sebagian' => 'Sebagian terealisasi', 'selesai' => 'Selesai', 'batal' => 'Dibatalkan'];

    protected $fillable = ['tanggal', 'nama', 'bank', 'rekening', 'nominal', 'status', 'user_id'];

    protected $casts = ['tanggal' => 'date'];

    public function detail(): HasMany
    {
        return $this->hasMany(UjPengajuanDetail::class)->orderBy('urut');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kode(): string
    {
        return 'PUJ-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /** Status dari detailnya: semua menunggu = diajukan, sebagian terealisasi, semua selesai/batal. */
    public function hitungStatus(): void
    {
        $d = $this->detail()->get();
        $real = $d->where('status', 'terealisasi')->count();
        $tunggu = $d->where('status', 'menunggu')->count();
        $this->update(['status' => $tunggu === 0 ? ($real ? 'selesai' : 'batal') : ($real ? 'sebagian' : 'diajukan')]);
    }
}
