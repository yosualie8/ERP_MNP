<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Realisasi pengajuan UJ yang tidak sama dengan pengajuannya: jenis (sesuai | penyesuaian | dialihkan), alasan admin,
// dan isi realisasinya (pengajuan asli tetap tidak diubah). Status detail bertambah "dialihkan".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uj_pengajuan_detail', function (Blueprint $table) {
            $table->string('jenis_realisasi', 12)->nullable()->after('id_uj');
            $table->text('alasan')->nullable()->after('jenis_realisasi');
            $table->json('realisasi')->nullable()->after('alasan');
        });
    }

    public function down(): void
    {
        Schema::table('uj_pengajuan_detail', function (Blueprint $table) {
            $table->dropColumn(['jenis_realisasi', 'alasan', 'realisasi']);
        });
    }
};
