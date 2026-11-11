<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisi owner 11 Nov 2026: pembayaran hari yang diubah admin dipisah UM (rekap_absen.bayar_ubah) dan lembur (bayar_lembur_ubah);
 * masing-masing kosong = hitungan biasa, total = UM + lembur. Alasan tetap wajib (bayar_alasan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekap_absen', function (Blueprint $table) {
            $table->unsignedInteger('bayar_lembur_ubah')->nullable()->after('bayar_ubah');
        });
    }

    public function down(): void
    {
        Schema::table('rekap_absen', function (Blueprint $table) {
            $table->dropColumn('bayar_lembur_ubah');
        });
    }
};
