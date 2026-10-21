<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekap Bayar Tanah lewat Pengajuan UJ (owner 21 Okt 2026): rekap (RBT) yang diajukan berstatus "diajukan" dengan pengajuannya;
 * menjadi "dibayar" begitu uang tanahnya terealisasi di Input UJ, kembali "menunggu" bila pengajuannya dibatalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bayar_tanah_rekap', function (Blueprint $table) {
            $table->foreignId('uj_pengajuan_id')->nullable()->after('no_uj')->constrained('uj_pengajuan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bayar_tanah_rekap', fn (Blueprint $table) => $table->dropConstrainedForeignId('uj_pengajuan_id'));
    }
};
