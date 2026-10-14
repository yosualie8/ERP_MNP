<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kolom PIC gaji & Catatan tidak dipakai (permintaan user 14 Okt 2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawan', function (Blueprint $table) {
            $table->dropColumn(['pic_gaji', 'catatan']);
        });
    }

    public function down(): void
    {
        Schema::table('karyawan', function (Blueprint $table) {
            $table->string('pic_gaji', 120)->nullable();
            $table->text('catatan')->nullable();
        });
    }
};
