<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tautan transaksi detail Kas Harian (NO ID) ↔ baris Kas UJ yang direimburse lewat Input Kas → "Input reimburse Kas UJ".
// Data UJ disalin (bukan dirujuk) karena tabel uj_detail dibuat ulang setiap impor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kas_reimburse_uj', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('no_id')->unique();       // NO ID baris detail di Kas Harian
            $table->unsignedInteger('no_id_transfer')->index(); // NO ID baris transfer (master)
            $table->date('tanggal_reimburse')->index();
            $table->string('id_uj', 30)->nullable()->index();
            $table->unsignedInteger('baris_uj')->nullable();
            $table->date('tanggal_uj')->nullable();
            $table->string('jenis_kendaraan', 100)->nullable();
            $table->string('no_mobil', 100)->nullable();
            $table->string('no_do', 150)->nullable();
            $table->string('galian', 150)->nullable();
            $table->string('kategori', 100)->nullable();
            $table->unsignedBigInteger('nominal');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_reimburse_uj');
    }
};
