<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekap absen mingguan → penggajian UM (owner 30 Okt 2026): keputusan per karyawan per hari yang menimpa hasil absen —
 * persetujuan admin untuk absen belum lengkap, absen tukang yang diisi mandor, tahap kerja hari itu. UM bisa beda per orang
 * walau jabatannya sama → karyawan.um_harian menimpa tarif jabatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rekap_absen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $table->date('tanggal');
            // null = ikut hasil absen; 1 = dibayar; 0 = tidak dibayar
            $table->boolean('hadir')->nullable();
            $table->boolean('lembur')->nullable();
            $table->foreignId('proyek_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->string('sumber', 20)->nullable(); // mandor | admin
            $table->string('catatan', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['karyawan_id', 'tanggal']);
            $table->index('tanggal');
        });
        Schema::table('karyawan', function (Blueprint $table) {
            $table->unsignedInteger('um_harian')->nullable()->after('jabatan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekap_absen');
        Schema::table('karyawan', function (Blueprint $table) {
            $table->dropColumn('um_harian');
        });
    }
};
