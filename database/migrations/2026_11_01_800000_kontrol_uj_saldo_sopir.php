<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrol UJ & saldo sopir (owner 18 Okt 2026):
 *  - uj_pengalihan.no_do + uj_pengajuan_detail_id: pemakaian saldo sopir lewat Pengajuan UJ (DO baru, wajib ada bongkarnya);
 *    jenis baru "batal" = UJ ditutup tanpa bongkar dengan alasan (tetap biaya proyek asal).
 *  - uj_pengajuan_detail.saldo: bagian nominal detail yang dibayar dari saldo sopir (tidak ditransfer).
 *  - proyek_tahap.batas_bongkar: batas wajar (hari) UJ belum bongkar per proyek; kosong = 2 hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uj_pengalihan', function (Blueprint $table) {
            $table->string('no_do', 40)->nullable()->after('id_uj');
            $table->foreignId('uj_pengajuan_detail_id')->nullable()->after('peruntukan')->constrained('uj_pengajuan_detail')->cascadeOnDelete();
        });
        Schema::table('uj_pengajuan_detail', function (Blueprint $table) {
            $table->unsignedBigInteger('saldo')->default(0)->after('nominal');
        });
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->unsignedTinyInteger('batas_bongkar')->nullable()->after('kata_uj');
        });
    }

    public function down(): void
    {
        Schema::table('uj_pengalihan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uj_pengajuan_detail_id');
            $table->dropColumn('no_do');
        });
        Schema::table('uj_pengajuan_detail', fn (Blueprint $table) => $table->dropColumn('saldo'));
        Schema::table('proyek_tahap', fn (Blueprint $table) => $table->dropColumn('batas_bongkar'));
    }
};
