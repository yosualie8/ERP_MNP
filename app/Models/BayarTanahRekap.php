<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rekap Bayar Tanah (RBT-0001): daftar DO + harga tanah yang menunggu dibayar lewat Input UJ. */
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

    /**
     * Setelah detail pengajuan terealisasi di Input UJ: rekap "diajukan" yang semua Uang Tanah DO-nya di pengajuan itu sudah
     * terealisasi → "dibayar". @param  int[]  $pengajuanId  @return string[] kode rekap yang jadi dibayar
     */
    public static function cekRealisasi(array $pengajuanId, ?int $userId): array
    {
        $kode = [];
        foreach (self::where('status', 'diajukan')->whereIn('uj_pengajuan_id', $pengajuanId ?: [0])->get() as $r) {
            $do = array_map('strval', array_column($r->isi, 'do'));
            $tanah = UjPengajuanDetail::where('uj_pengajuan_id', $r->uj_pengajuan_id)->where('kategori', 'Uang Tanah')->whereIn('no_do', $do)->get();
            if ($tanah->isNotEmpty() && $tanah->every(fn ($d) => $d->status === 'terealisasi')) {
                $noUj = UjTransaksi::find(UjDetail::where('id_uj', $tanah->last()->id_uj)->value('uj_transaksi_id'))?->no_uj;
                $r->update(['status' => 'dibayar', 'no_uj' => $noUj, 'dibayar_pada' => now(), 'dibayar_oleh' => $userId]);
                $kode[] = $r->kode();
            }
        }

        return $kode;
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
