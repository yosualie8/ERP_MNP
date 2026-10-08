<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Salinan lembar "Sudah Reimburse" (spreadsheet "(0) Transaksi Belum Reimburse V3"): ID transaksi kas yang sudah direimburse.
// Status di Mutasi Reimburse kolom R = ada/tidaknya ID di lembar ini; diperbarui berkala (mnp:status-reimburse).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kas_sudah_reimburse', function (Blueprint $table) {
            $table->string('id_transaksi', 40)->primary();
            $table->date('tanggal_reimburse')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_sudah_reimburse');
    }
};
