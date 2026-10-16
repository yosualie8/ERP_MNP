<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi Rekening PT (BCA) — hanya untuk dilihat (owner 16 Okt 2026). Cermin sheet "Mutasi Rekening PT" di file Drive
 * "Mutasi Rek PT Gabungan MNP.xlsx"; diimpor ulang utuh setiap file berubah (mnp:impor-mutasi-pt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutasi_pt', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('baris');           // nomor baris di sheet (urutan asli)
            $table->date('tanggal')->nullable();
            $table->text('keterangan')->nullable();
            $table->string('cabang', 40)->nullable();
            $table->char('dbcr', 2)->nullable();       // DB = keluar, CR = masuk
            $table->decimal('jumlah', 18, 2)->default(0);
            $table->decimal('saldo', 18, 2)->nullable();
            $table->timestamps();
            $table->index(['tanggal', 'baris']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_pt');
    }
};
