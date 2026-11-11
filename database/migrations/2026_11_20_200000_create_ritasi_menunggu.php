<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 11 Nov 2026: surat jalan yang sudah bongkar tetapi belum ada uang jalan / DO-nya (upload Monitor Ritasi → "menunggu uang jalan")
 * disimpan supaya tampil di menu Bayar Tanah dan uang tanahnya bisa dibayar lebih dulu memakai No Surat Jalan.
 * Selesai dengan sendirinya begitu No Surat Jalan-nya tercatat di Ritasi (UnggahBongkar::sudahAda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ritasi_menunggu', function (Blueprint $table) {
            $table->id();
            $table->string('no_mobil', 30);
            $table->string('sopir', 100)->nullable();
            $table->string('jenis_kendaraan', 50)->nullable();
            $table->date('tanggal');
            $table->string('jam', 10)->nullable();
            $table->string('tahap', 150);
            $table->string('galian', 100)->nullable();
            $table->string('jenis_tanah', 100)->nullable();
            $table->string('no_seri', 100);
            $table->string('kubikasi', 20)->nullable();
            $table->string('keterangan', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['tahap', 'no_seri']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ritasi_menunggu');
    }
};
