<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rekap Bayar Tanah (RBT-0001): daftar DO + harga tanah yang menunggu dibayar lewat Input UJ. */
class BayarTanahRekap extends Model
{
    protected $table = 'bayar_tanah_rekap';

    protected $fillable = ['status', 'jumlah', 'total', 'isi', 'catatan', 'no_uj', 'dibayar_pada', 'dibayar_oleh', 'user_id'];

    protected $casts = ['isi' => 'array', 'dibayar_pada' => 'datetime'];

    public const STATUS = ['menunggu' => 'Menunggu dibayar', 'dibayar' => 'Dibayar', 'batal' => 'Batal'];

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
        foreach (self::where('status', 'menunggu')->get() as $r) {
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
