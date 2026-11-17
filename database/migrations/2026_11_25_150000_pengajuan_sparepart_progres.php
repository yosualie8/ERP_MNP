<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Sparepart: status per baris (revisi Admin Plant 17 Nov 2026) — tiap sparepart ditandai Disetujui / Dibeli / Terpasang / Ditolak
 * (kosong = belum), dengan siapa & kapan menandai, supaya atasan melihat progresnya. Baris yang belum di-ACC bisa diajukan ulang ke pengajuan
 * baru: pengajuan baru menyimpan asal_id, barisnya asal_detail_id, dan baris asal bertanda "diulang" (Diajukan ulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_sparepart_detail', function (Blueprint $table) {
            $table->string('progres', 12)->nullable()->after('keterangan'); // null = belum | disetujui | dibeli | terpasang | ditolak | diulang
            $table->timestamp('progres_pada')->nullable()->after('progres');
            $table->foreignId('progres_oleh')->nullable()->after('progres_pada')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('asal_detail_id')->nullable()->after('progres_oleh')->index(); // baris pengajuan sebelumnya yang diajukan ulang
        });
        Schema::table('pengajuan_sparepart', function (Blueprint $table) {
            $table->foreignId('asal_id')->nullable()->after('catatan_putusan')->constrained('pengajuan_sparepart')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_sparepart', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asal_id');
        });
        Schema::table('pengajuan_sparepart_detail', function (Blueprint $table) {
            $table->dropConstrainedForeignId('progres_oleh');
            $table->dropColumn(['progres', 'progres_pada', 'asal_detail_id']);
        });
    }
};
