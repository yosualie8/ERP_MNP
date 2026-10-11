<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu transfer yang diinput skrip ke KlikBCA Bisnis (lihat migrasi input_bca). */
class InputBca extends Model
{
    protected $table = 'input_bca';

    protected $fillable = ['nama', 'bank', 'rekening', 'nominal', 'berita', 'layanan', 'status', 'no_referensi', 'pesan', 'mulai_pada', 'selesai_pada', 'user_id'];

    protected $casts = ['mulai_pada' => 'datetime', 'selesai_pada' => 'datetime'];

    public const STATUS = ['menunggu' => 'Menunggu', 'proses' => 'Sedang diinput', 'terinput' => 'Terinput', 'dilewati' => 'Dilewati', 'gagal' => 'Gagal', 'batal' => 'Dibatalkan'];

    public const LAYANAN = ['bifast' => 'BI-FAST', 'online' => 'Online', 'llg' => 'LLG', 'rtgs' => 'RTGS'];

    /** Batas BI-FAST / Online per transaksi. */
    public const MAKS_BIFAST = 250_000_000;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sesama BCA tidak memakai layanan; bank lain: BI-FAST bila ≤ Rp 250 jt, selain itu RTGS. */
    public static function layananBawaan(string $bank, int $nominal): ?string
    {
        return $bank === 'BCA' ? null : ($nominal <= self::MAKS_BIFAST ? 'bifast' : 'rtgs');
    }

    /** Status "proses" yang terlalu lama: skrip mungkin terhenti setelah Kirim — wajib dicek di KlikBCA Bisnis. */
    public function perluCek(): bool
    {
        return $this->status === 'proses' && $this->mulai_pada?->lt(now()->subMinutes(5));
    }
}
