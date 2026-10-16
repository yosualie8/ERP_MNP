<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keterangan tambahan admin pada mutasi BCA (owner 16 Okt 2026): catatan diperpanjang ke 500 huruf, dan status boleh
 * kosong (= ikut penandaan otomatis) supaya mengisi keterangan saja tidak membekukan status otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mutasi_pt_tanda MODIFY status VARCHAR(10) NULL, MODIFY catatan VARCHAR(500) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE mutasi_pt_tanda SET status = 'belum' WHERE status IS NULL");
        DB::statement('ALTER TABLE mutasi_pt_tanda MODIFY status VARCHAR(10) NOT NULL, MODIFY catatan VARCHAR(200) NULL');
    }
};
