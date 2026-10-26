<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tarif dasar uang makan (UM) per hari per jabatan (owner 26 Okt 2026) — dasar penggajian UM mingguan dari absensi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jabatan_menu', function (Blueprint $table) {
            $table->unsignedInteger('um_harian')->nullable()->after('menu');
        });
    }

    public function down(): void
    {
        Schema::table('jabatan_menu', function (Blueprint $table) {
            $table->dropColumn('um_harian');
        });
    }
};
