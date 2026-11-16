<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pengajuan Sparepart ke manajemen (menu Plant & Sparepart): satu nomor PSP berisi beberapa baris sparepart. */
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

    /** Nomor berikutnya: PSP-00001, PSP-00002, … (melanjutkan nomor terbesar yang ada). */
    public static function nomorBaru(): string
    {
        $akhir = (int) static::query()->selectRaw("MAX(CAST(SUBSTRING(nomor, 5) AS UNSIGNED)) AS n")->value('n');

        return 'PSP-'.str_pad((string) ($akhir + 1), 5, '0', STR_PAD_LEFT);
    }
}
