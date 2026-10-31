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
 *  - kasbon  : kasbon sopir dari Kas Harian (Kode GL Piutang Karyawan, owner 27 Okt 2026) → utang sopir bertambah; kas_no_id = NO ID bon-nya
 *              (satu bon boleh dibagi ke beberapa sopir). Bukan biaya proyek (piutang);
 *  - pakai   : utang / saldo sopir berkurang — uang jalan DO baru (no_do; boleh sebagian, sisanya transfer di Kas UJ ber-DO sama),
 *              Uang MOB / lain (biaya proyek tempat dipakai), potong gaji / UM, dikembalikan, sudah dicicil sebelum dicatat (bukan biaya),
 *              atau tidak kembali (dibebankan ke proyek);
 *  - settle  : penyesuaian sekali saat buku Hutang Supir di-settle (owner 30 Okt 2026, SaldoSopir::SETTLE) supaya utang sopir = rekap admin;
 *              nominal + menambah utang (selisih minus dicatat sebagai "pakai" tidak kembali, dibebankan ke proyek asal);
 *  - muatan  : potongan muatan kurang (owner 31 Okt 2026, App\Support\PotonganMuatan): rit ber-LS di bawah batas standar jenis truk →
 *              utang sopir bertambah per hari bongkar, dicatat bertanggal hari berikutnya supaya terpotong dari uang jalan besoknya.
 */
class UjPengalihan extends Model
{
    protected $table = 'uj_pengalihan';

    protected $fillable = ['jenis', 'id_uj', 'no_do', 'no_mobil', 'nama', 'sopir', 'dari_tahap_id', 'ke_tahap_id', 'tanggal', 'nominal', 'peruntukan', 'uj_pengajuan_detail_id',
        'kas_no_id', 'keterangan', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'nominal' => 'float'];

    public const JENIS = ['alih' => 'Jadi saldo sopir', 'kasbon' => 'Kasbon (Kas Harian)', 'kembali' => 'Dikembalikan', 'batal' => 'Batal (tidak bongkar)', 'pakai' => 'Saldo berkurang', 'settle' => 'Penyesuaian settle',
        'muatan' => 'Potongan muatan kurang'];

    /** Jenis yang menutup UJ asalnya (tidak lagi "belum bongkar"). */
    public const MENUTUP = ['alih', 'kembali', 'batal'];

    /** Jenis yang menambah utang / saldo sopir. */
    public const NAMBAH = ['alih', 'kasbon', 'settle', 'muatan'];

    /** Jenis yang tercatat di buku saldo / utang sopir (SaldoSopir::perSopir). */
    public const BUKU = ['alih', 'kasbon', 'pakai', 'settle', 'muatan'];

    /** Cara utang / saldo sopir berkurang (jenis "pakai"). */
    public const PERUNTUKAN = [
        'uj' => 'Uang jalan DO baru', 'mob' => 'Uang MOB (pindah lokasi)', 'gaji' => 'Potong gaji / UM mingguan',
        'kembali' => 'Dikembalikan sopir (transfer balik)', 'sebelum' => 'Sudah dicicil sebelum dicatat', 'lain' => 'Dipakai lain-lain',
        'hangus' => 'Tidak kembali (dibebankan ke proyek)',
    ];

    /** Peruntukan yang bukan biaya proyek (uangnya kembali ke PT). */
    public const BUKAN_BIAYA = ['kembali', 'gaji', 'sebelum'];

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
