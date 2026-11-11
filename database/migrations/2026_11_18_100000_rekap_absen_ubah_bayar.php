<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubah pembayaran satu orang satu hari (owner 11 Nov 2026): admin mengisi nominal UM hari itu yang menimpa UM per hari orang itu,
 * wajib dengan alasan. Lembur (½ UM biasa), HM, dan tunjangan mingguan tetap ikut aturannya. Nominal terisi = hari itu dibayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekap_absen', function (Blueprint $table) {
            $table->unsignedInteger('bayar_ubah')->nullable()->after('hm');
            $table->string('bayar_alasan', 300)->nullable()->after('bayar_ubah');
        });
    }

    public function down(): void
    {
        Schema::table('rekap_absen', function (Blueprint $table) {
            $table->dropColumn(['bayar_ubah', 'bayar_alasan']);
        });
    }
};
