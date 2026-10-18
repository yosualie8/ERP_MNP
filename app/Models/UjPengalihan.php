<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengalihan UJ & saldo sopir (lihat migrasi create_uj_pengalihan & kontrol_uj_saldo_sopir):
 *  - alih    : UJ (id_uj) belum bongkar pindah ke proyek lain → jadi SALDO SOPIR; baru menjadi biaya proyek tujuan saat terpakai
 *              (DO lamanya bongkar di proyek tujuan, atau dipakai lewat "pakai");
 *  - kembali : UJ dikembalikan sopir → keluar dari biaya proyek asal;
 *  - batal   : UJ ditutup tanpa bongkar dengan alasan → tetap biaya proyek asal, tidak lagi "belum bongkar";
 *  - pakai   : pemakaian saldo sopir (Uang MOB / UJ ber-DO baru lewat Pengajuan UJ / lain) → biaya proyek tujuan.
 */
class UjPengalihan extends Model
{
    protected $table = 'uj_pengalihan';

    protected $fillable = ['jenis', 'id_uj', 'no_do', 'no_mobil', 'nama', 'dari_tahap_id', 'ke_tahap_id', 'tanggal', 'nominal', 'peruntukan', 'uj_pengajuan_detail_id', 'keterangan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'nominal' => 'float'];

    public const JENIS = ['alih' => 'Dialihkan', 'kembali' => 'Dikembalikan', 'batal' => 'Batal (tidak bongkar)', 'pakai' => 'Dipakai'];

    /** Jenis yang menutup UJ asalnya (tidak lagi "belum bongkar"). */
    public const MENUTUP = ['alih', 'kembali', 'batal'];

    public const PERUNTUKAN = ['mob' => 'Uang MOB (pindah lokasi)', 'uj' => 'Uang Jalan (DO baru, dari Pengajuan UJ)', 'lain' => 'Lain-lain'];

    public function dari(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'dari_tahap_id');
    }

    public function ke(): BelongsTo
    {
        return $this->belongsTo(ProyekTahap::class, 'ke_tahap_id');
    }

    public function detailPengajuan(): BelongsTo
    {
        return $this->belongsTo(UjPengajuanDetail::class, 'uj_pengajuan_detail_id');
    }
}
