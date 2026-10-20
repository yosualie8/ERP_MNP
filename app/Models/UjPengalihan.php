<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengalihan UJ & saldo sopir (owner 20 Okt 2026 — saldo per SOPIR, tanpa proyek tujuan; lihat App\Support\SaldoSopir):
 *  - alih    : UJ (id_uj) belum bongkar dijadikan SALDO SOPIR. Keluar dari biaya proyek asal; belum biaya proyek mana pun
 *              (masa tunggu) sampai terpakai;
 *  - kembali : UJ dikembalikan sopir → keluar dari biaya proyek asal;
 *  - batal   : UJ ditutup tanpa bongkar dengan alasan → tetap biaya proyek asal, tidak lagi "belum bongkar";
 *  - pakai   : saldo sopir berkurang — dipakai (Uang MOB / UJ lewat Pengajuan UJ / lain: biaya proyek tempat dipakai),
 *              dikembalikan sopir (bukan biaya), atau tidak kembali (dibebankan ke proyek asal).
 */
class UjPengalihan extends Model
{
    protected $table = 'uj_pengalihan';

    protected $fillable = ['jenis', 'id_uj', 'no_do', 'no_mobil', 'nama', 'sopir', 'dari_tahap_id', 'ke_tahap_id', 'tanggal', 'nominal', 'peruntukan', 'uj_pengajuan_detail_id', 'keterangan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'nominal' => 'float'];

    public const JENIS = ['alih' => 'Jadi saldo sopir', 'kembali' => 'Dikembalikan', 'batal' => 'Batal (tidak bongkar)', 'pakai' => 'Saldo berkurang'];

    /** Jenis yang menutup UJ asalnya (tidak lagi "belum bongkar"). */
    public const MENUTUP = ['alih', 'kembali', 'batal'];

    /** Cara saldo sopir berkurang (jenis "pakai"). */
    public const PERUNTUKAN = [
        'uj' => 'Dipotong di Pengajuan UJ', 'mob' => 'Uang MOB (pindah lokasi)', 'lain' => 'Dipakai lain-lain',
        'kembali' => 'Dikembalikan sopir (transfer balik)', 'hangus' => 'Tidak kembali (dibebankan ke proyek asal)',
    ];

    /** Peruntukan yang bukan biaya proyek. */
    public const BUKAN_BIAYA = ['kembali'];

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
