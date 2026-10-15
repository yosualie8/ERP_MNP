<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Menu yang boleh dibuka karyawan dengan jabatan ini (halaman Hak Akses Jabatan). Jabatan yang belum diatur: hanya Absen. */
class JabatanMenu extends Model
{
    protected $table = 'jabatan_menu';

    protected $fillable = ['jabatan', 'menu', 'user_id'];

    protected $casts = ['menu' => 'array'];

    public const BAWAAN = ['absen'];

    public static function untuk(?string $jabatan): array
    {
        if (! $jabatan) {
            return [];
        }

        $baris = static::where('jabatan', $jabatan)->first();

        return $baris ? ($baris->menu ?? []) : self::BAWAAN;
    }
}
