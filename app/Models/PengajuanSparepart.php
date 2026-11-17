<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pengajuan Sparepart ke manajemen (menu Plant & Sparepart): satu nomor (yyMMdd + urut) berisi beberapa baris sparepart. */
class PengajuanSparepart extends Model
{
    protected $table = 'pengajuan_sparepart';

    protected $fillable = ['nomor', 'tanggal', 'keperluan', 'catatan', 'status', 'total', 'user_id', 'diputus_oleh', 'diputus_pada', 'catatan_putusan'];

    protected $casts = ['tanggal' => 'date', 'diputus_pada' => 'datetime', 'total' => 'float'];

    public const STATUS = ['diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'batal' => 'Dibatalkan'];

    /** Warna label status (kelas .label di layout). */
    public const WARNA = ['diajukan' => 'kuning', 'disetujui' => 'hijau', 'ditolak' => 'merah', 'batal' => ''];

    public function detail(): HasMany
    {
        return $this->hasMany(PengajuanSparepartDetail::class)->orderBy('urut')->orderBy('id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh');
    }

    /**
     * Nomor berikutnya (owner 17 Nov 2026): yyMMdd tanggal pengajuan + urut 3 digit per tanggal itu, mis. 261117001, 261117002.
     * Dipanggil di dalam transaksi: lockForUpdate menahan pengajuan lain bertanggal sama sampai nomor ini tersimpan.
     */
    public static function nomorBaru($tanggal): string
    {
        $awal = \Illuminate\Support\Carbon::parse($tanggal)->format('ymd');
        $akhir = static::query()->where('nomor', 'like', $awal.'%')->lockForUpdate()->pluck('nomor')
            ->map(fn ($n) => (int) substr($n, 6))->max() ?? 0;

        return $awal.str_pad((string) ($akhir + 1), 3, '0', STR_PAD_LEFT);
    }
}
