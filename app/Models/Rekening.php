<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Database rekening bersama Kas Harian & Kas UJ: satu nomor rekening = satu nama pemilik (menu Daftar Rekening). */
class Rekening extends Model
{
    protected $table = 'rekening';

    public const SUMBER = ['kas_harian' => 'Kas Harian', 'karyawan' => 'Data Karyawan', 'kas_uj' => 'Kas UJ (perlu dicek)', 'admin' => 'Diisi admin', 'input' => 'Input baru',
        // Nomor salah tulis / tidak ada (dicek admin): tetap tercatat, tidak ditawarkan sebagai saran di Input Kas / Input UJ.
        'salah' => 'Nomor salah / tidak ada'];

    protected $fillable = ['kunci', 'no_rekening', 'bank', 'nama', 'sumber', 'catatan', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "0081-2345 678" → "812345678": nomor yang sama walau beda tanda baca / nol depan. */
    public static function kunci(?string $no): string
    {
        return ltrim(preg_replace('/\D/', '', (string) $no), '0');
    }
}
