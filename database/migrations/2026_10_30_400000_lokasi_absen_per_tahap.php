<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lokasi absen dikaitkan ke proyek DETAIL (tahap di Daftar Proyek), bukan proyek master (permintaan user 14 Okt 2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lokasi_absen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proyek_id');
            $table->foreignId('proyek_tahap_id')->nullable()->after('nama')->constrained('proyek_tahap')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lokasi_absen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proyek_tahap_id');
            $table->foreignId('proyek_id')->nullable()->after('nama')->constrained('proyek')->nullOnDelete();
        });
    }
};
