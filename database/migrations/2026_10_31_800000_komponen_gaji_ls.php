<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Gaji & UM tim lapangan direkomendasikan sebagai lumpsum (Rp per m³ × volume) — satuan ls, bukan bulan. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('komponen_anggaran')->where('kode', 'gaji')->update(['satuan' => 'ls', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('komponen_anggaran')->where('kode', 'gaji')->update(['satuan' => 'bulan']);
    }
};
