<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner 15 Okt 2026: pekerjaan luas & volume — komponen tanah & urugan bersatuan m³ (bukan rit). Tipe ritasi tetap per rit. */
return new class extends Migration
{
    public function up(): void
    {
        $t = now();
        $rit = DB::table('komponen_anggaran')->where('kode', 'tanah_rit')->first();
        DB::table('komponen_anggaran')->where('kode', 'tanah_rit')->update(['nama' => 'Tanah urug & angkutan (per rit)', 'tipe_bawaan' => json_encode(['ritasi']), 'updated_at' => $t]);
        DB::table('komponen_anggaran')->insert(['kode' => 'tanah_m3', 'jenis' => 'hpp', 'nama' => 'Tanah urug & angkutan (m³)', 'satuan' => 'm³', 'sumber' => 'ritasi_m3',
            'cara' => 'volume', 'tunda_realisasi' => false, 'nilai_bawaan' => null, 'tipe_bawaan' => json_encode(['luas']), 'urutan' => ($rit->urutan ?? 40) - 5, 'aktif' => true,
            'created_at' => $t, 'updated_at' => $t]);
    }

    public function down(): void
    {
        DB::table('komponen_anggaran')->where('kode', 'tanah_m3')->delete();
        DB::table('komponen_anggaran')->where('kode', 'tanah_rit')->update(['nama' => 'Tanah urug & angkutan (ritasi)', 'tipe_bawaan' => json_encode(['luas', 'ritasi'])]);
    }
};
