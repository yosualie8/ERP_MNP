<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Menu yang boleh dibuka karyawan dengan jabatan ini (halaman Hak Akses Jabatan). Jabatan yang belum diatur: hanya Absen. */
class JabatanMenu extends Model
{
    protected $table = 'jabatan_menu';

    protected $fillable = ['jabatan', 'menu', 'um_harian', 'basic_mingguan', 'transport_mingguan', 'kesehatan_mingguan', 'tarif_hm', 'user_id'];

    /** Komponen gaji mingguan selain UM (operator alat berat, owner 3 Nov 2026): kolom => label. */
    public const MINGGUAN = ['basic_mingguan' => 'Basic', 'transport_mingguan' => 'Transport', 'kesehatan_mingguan' => 'Uang kesehatan'];

    protected $casts = ['menu' => 'array'];

    public const BAWAAN = ['absen'];

    public static function untuk(?string $jabatan): array
    {
        if (! $jabatan) {
            return self::BAWAAN; // karyawan aktif yang jabatannya belum diisi: tetap bisa Absen
        }

        $baris = static::where('jabatan', $jabatan)->first();

        return $baris ? ($baris->menu ?? []) : self::BAWAAN;
    }
}
