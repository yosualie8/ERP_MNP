<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Pengajuan UJ = daftar transaksi yang diajukan (belum ada penerima transfer): nama penerima tidak wajib lagi.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE uj_pengajuan MODIFY nama VARCHAR(150) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE uj_pengajuan SET nama = '' WHERE nama IS NULL");
        DB::statement('ALTER TABLE uj_pengajuan MODIFY nama VARCHAR(150) NOT NULL');
    }
};
