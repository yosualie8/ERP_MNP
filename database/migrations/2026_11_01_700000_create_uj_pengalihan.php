<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengalihan UJ (owner 17 Okt 2026): UJ yang sudah diberikan untuk satu proyek ritasi tetapi truknya dipindah sebelum bongkar.
 * Transaksi Kas UJ aslinya TIDAK diubah. Buku per mobil:
 *  - alih    : UJ (id_uj) pindah dari dari_tahap ke ke_tahap → biaya proyek tujuan, saldo sopir bertambah;
 *  - kembali : UJ (id_uj) dikembalikan sopir → keluar dari biaya proyek asal;
 *  - pakai   : pemakaian saldo sopir di proyek tujuan (MOB / dipotong dari UJ berikutnya / lain) → saldo berkurang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uj_pengalihan', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 10); // alih | kembali | pakai
            $table->string('id_uj', 20)->nullable()->unique();
            $table->string('no_mobil', 20);
            $table->string('nama', 100)->nullable();
            $table->foreignId('dari_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->foreignId('ke_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->date('tanggal');
            $table->decimal('nominal', 14, 2);
            $table->string('peruntukan', 10)->nullable(); // pakai: mob | uj | lain
            $table->string('keterangan', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['ke_tahap_id', 'no_mobil']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uj_pengalihan');
    }
};
