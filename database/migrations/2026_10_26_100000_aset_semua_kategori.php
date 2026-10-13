<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Aset untuk semua aset (bukan hanya dump truck): kategori (Dump Truck, Alat Berat, LV, Engkel, …), kepemilikan
 * (milik MNP / rental + vendor). Kolom no_lambung = kode/nama aset (DT 026, Excavator Kobelco 07, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aset_truk', function (Blueprint $table) {
            $table->string('kategori', 20)->default('dump_truck')->after('no_lambung');
            $table->string('kepemilikan', 10)->default('milik')->after('jenis'); // milik | rental
            $table->string('vendor', 100)->nullable()->after('kepemilikan');
            $table->string('no_lambung', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('aset_truk', function (Blueprint $table) {
            $table->dropColumn(['kategori', 'kepemilikan', 'vendor']);
        });
    }
};
