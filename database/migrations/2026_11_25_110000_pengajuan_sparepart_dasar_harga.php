<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengajuan Sparepart: dasar harga usulan per baris (owner 16 Nov 2026 — harga per jenis alat / tipe unit, mis. "Truck · DT Isuzu Giga"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_sparepart_detail', function (Blueprint $table) {
            $table->string('dasar_harga', 120)->nullable()->after('harga_saran');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_sparepart_detail', function (Blueprint $table) {
            $table->dropColumn('dasar_harga');
        });
    }
};
