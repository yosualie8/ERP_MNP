<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku utang sopir (owner 27 Okt 2026): saldo sopir & kasbon = uang PT yang dipegang sopir. Kasbon keluar dari Kas Harian (Kode GL Piutang Karyawan)
 * → uj_pengalihan jenis "kasbon" ditautkan ke NO ID bon Kas Harian (satu bon bisa dibagi ke beberapa sopir). Transfer balik juga boleh mencatat NO ID-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uj_pengalihan', function (Blueprint $table) {
            $table->string('kas_no_id', 30)->nullable()->after('uj_pengajuan_detail_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('uj_pengalihan', function (Blueprint $table) {
            $table->dropColumn('kas_no_id');
        });
    }
};
