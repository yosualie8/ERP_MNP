<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Aset PT MNP (menu Data Aset): dump truck, alat berat, LV, engkel, dll. Nama tabel/model tetap "aset_truk" karena
 * awalnya hanya truk; kolom no_lambung = kode/nama aset ("DT 026", "Excavator Kobelco 07").
 */
class AsetTruk extends Model
{
    protected $table = 'aset_truk';

    public const STATUS = ['aktif' => 'Aktif', 'perbaikan' => 'Perbaikan', 'tidak_aktif' => 'Tidak aktif', 'dijual' => 'Dijual / keluar'];

    public const KATEGORI = [
        'dump_truck' => 'Dump Truck', 'engkel' => 'Engkel', 'alat_berat' => 'Alat Berat', 'lv' => 'Light Vehicle (LV)',
        'water_tank' => 'Water Tank', 'mesin' => 'Mesin / Peralatan', 'lainnya' => 'Lainnya',
    ];

    /** Kategori yang dipakai fitur truk (Uang Jalan, Ritasi, Buangan Truck, Performa Ritasi). */
    public const KATEGORI_TRUK = ['dump_truck'];

    public const KEPEMILIKAN = ['milik' => 'Milik MNP', 'rental' => 'Rental'];

    protected $fillable = ['no_lambung', 'kategori', 'plat', 'jenis', 'kepemilikan', 'vendor', 'tahun', 'no_rangka', 'no_mesin', 'stnk_berlaku', 'kir_berlaku',
        'status', 'driver_tetap', 'catatan', 'user_id'];

    protected $casts = ['stnk_berlaku' => 'date', 'kir_berlaku' => 'date'];

    /** Hanya dump truck (fitur UJ/Ritasi yang memakai No Lambung DT). */
    public function scopeTruk(Builder $q): Builder
    {
        return $q->whereIn('kategori', self::KATEGORI_TRUK);
    }

    public function labelStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    public function labelKategori(): string
    {
        return self::KATEGORI[$this->kategori] ?? $this->kategori;
    }
}
