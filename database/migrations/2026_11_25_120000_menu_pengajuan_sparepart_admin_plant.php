<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hak menu Pengajuan Sparepart (pengajuan-sparepart) diberikan ke jabatan Admin Plant (owner 17 Nov 2026): membuat, mengubah &
 * membatalkan pengajuan. Menyetujui / menolak (pengajuan-sparepart-putus) tetap hanya manajemen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $j = DB::table('jabatan_menu')->where('jabatan', 'Admin Plant')->first();
        if ($j) {
            $menu = json_decode($j->menu ?? '[]', true) ?: [];
            if (! in_array('pengajuan-sparepart', $menu, true)) {
                $menu[] = 'pengajuan-sparepart';
                DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode(array_values($menu)), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $j = DB::table('jabatan_menu')->where('jabatan', 'Admin Plant')->first();
        if ($j) {
            $menu = array_values(array_diff(json_decode($j->menu ?? '[]', true) ?: [], ['pengajuan-sparepart']));
            DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode($menu)]);
        }
    }
};
