<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master sopir di Data Karyawan (owner 20 Okt 2026): sopir = karyawan berjabatan "Supir".
 *  - karyawan.no_mobil  : truk yang sedang dibawa sopir (dump truck, "DT 0xx");
 *  - karyawan_alias     : ejaan / nama lain yang dipakai di Kas UJ (WSAL → WISAL). karyawan_id kosong = nama itu BUKAN sopir
 *                         (sudah dijawab admin, tidak ditanyakan lagi). kunci = nama huruf besar, spasi tunggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawan', function (Blueprint $table) {
            $table->string('no_mobil', 20)->nullable()->after('jabatan')->index();
        });
        Schema::create('karyawan_alias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->nullable()->constrained('karyawan')->cascadeOnDelete();
            $table->string('nama', 150);
            $table->string('kunci', 150)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan_alias');
        Schema::table('karyawan', function (Blueprint $table) {
            $table->dropIndex(['no_mobil']);
            $table->dropColumn('no_mobil');
        });
    }
};
