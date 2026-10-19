<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Absen hasil Lapor Lapangan boleh tanpa GPS (HP menolak izin lokasi) — kiriman tetap diterima, absennya ditandai tanpa lokasi. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE absensi MODIFY lat DECIMAL(10,7) NULL, MODIFY lng DECIMAL(10,7) NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE absensi SET lat = 0 WHERE lat IS NULL');
        DB::statement('UPDATE absensi SET lng = 0 WHERE lng IS NULL');
        DB::statement('ALTER TABLE absensi MODIFY lat DECIMAL(10,7) NOT NULL, MODIFY lng DECIMAL(10,7) NOT NULL');
    }
};
