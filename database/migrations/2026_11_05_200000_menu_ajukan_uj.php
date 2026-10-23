<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hak "Buat Pengajuan UJ" (ajukan-uj) dipisah dari melihat Pengajuan UJ (owner 23 Okt 2026). Jabatan yang selama ini punya menu
 * Pengajuan UJ tetap bisa membuat pengajuan, kecuali Admin Mobil (hanya melihat & merealisasikan).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('jabatan_menu')->get() as $j) {
            $menu = json_decode($j->menu ?? '[]', true) ?: [];
            if (in_array('pengajuan-uj', $menu, true) && ! in_array('ajukan-uj', $menu, true) && $j->jabatan !== 'Admin Mobil') {
                $menu[] = 'ajukan-uj';
                DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode(array_values($menu)), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('jabatan_menu')->get() as $j) {
            $menu = json_decode($j->menu ?? '[]', true) ?: [];
            if (in_array('ajukan-uj', $menu, true)) {
                DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode(array_values(array_diff($menu, ['ajukan-uj'])))]);
            }
        }
    }
};
