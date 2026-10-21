<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekap Bayar Tanah (RBT-0001): CATATAN daftar DO + harga tanah, supaya admin tahu bayar tanah n rit itu untuk DO mana saja
 * (owner 21 Okt 2026). Tidak ditautkan ke Pengajuan UJ & tidak memblokir apa pun; "dibayar" hanya keterangan bila dipakai di Input UJ.
 */
class BayarTanahRekap extends Model
{
    protected $table = 'bayar_tanah_rekap';

    protected $fillable = ['status', 'jumlah', 'total', 'isi', 'catatan', 'no_uj', 'uj_pengajuan_id', 'dibayar_pada', 'dibayar_oleh', 'user_id'];

    protected $casts = ['isi' => 'array', 'dibayar_pada' => 'datetime'];

    /** diajukan = sudah dimasukkan ke Pengajuan UJ (uj_pengajuan_id), menunggu realisasinya di Input UJ. */
    public const STATUS = ['menunggu' => 'Menunggu dibayar', 'diajukan' => 'Diajukan (Pengajuan UJ)', 'dibayar' => 'Dibayar', 'batal' => 'Batal'];

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(UjPengajuan::class, 'uj_pengajuan_id');
    }

    public function kode(): string
    {
        return 'RBT-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> DO → kode RBT yang masih menunggu (DO itu tidak boleh direkap lagi). */
    public static function doMenunggu(): array
    {
        $hasil = [];
        foreach (self::whereIn('status', ['menunggu', 'diajukan'])->get() as $r) {
            foreach ($r->isi as $x) {
                $hasil[(string) $x['do']] = $r->kode();
            }
        }

        return $hasil;
    }

    /** @return array<string, int> DO → harga */
    public function harga(): array
    {
        return collect($this->isi)->mapWithKeys(fn ($x) => [(string) $x['do'] => (int) $x['harga']])->all();
    }
}
