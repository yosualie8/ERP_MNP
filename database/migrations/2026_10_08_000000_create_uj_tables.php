<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kas uang jalan dump truck: lembar "Kas Seabank" (spreadsheet "KAS MMP Uang Jalan dan UM"), diimpor dari sheet.
return new class extends Migration
{
    public function up(): void
    {
        // Satu transfer (baris master: bank, rekening, nominal master) beserta baris detail di bawahnya.
        Schema::create('uj_transaksi', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('baris');
            $table->unsignedInteger('baris_akhir');
            $table->date('tanggal')->nullable()->index();
            $table->string('bank', 40)->nullable();
            $table->string('rekening', 40)->nullable();
            $table->string('nama', 150)->nullable();
            $table->bigInteger('nominal')->nullable();
            // Nomor ID UJ baris master (UJ-12950 → 12950): kunci tetap untuk Edit/Hapus/foto.
            $table->unsignedInteger('no_uj')->nullable()->index();
            $table->bigInteger('biaya')->nullable();
            $table->timestamps();
        });

        Schema::create('uj_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uj_transaksi_id')->constrained('uj_transaksi')->cascadeOnDelete();
            $table->unsignedInteger('baris');
            $table->string('id_uj', 20)->nullable()->index();
            $table->date('tanggal')->nullable();
            $table->string('nama', 150)->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->bigInteger('nominal')->nullable();
            $table->string('kategori', 60)->nullable();
            $table->string('jenis_kendaraan', 40)->nullable();
            $table->string('no_mobil', 40)->nullable();
            $table->string('no_do', 40)->nullable();
            $table->string('status', 40)->nullable();
            $table->date('tanggal_reimburse')->nullable();
            $table->string('bon', 255)->nullable();
            $table->boolean('biaya_transfer')->default(false);
            $table->timestamps();
        });

        // Foto bon dipakai Kas Harian (NO ID) dan Kas UJ (nomor ID UJ): dibedakan lewat sumber.
        Schema::table('kas_foto', function (Blueprint $table) {
            $table->string('sumber', 10)->default('kas')->after('id');
            $table->index(['sumber', 'no_id']);
        });
    }

    public function down(): void
    {
        Schema::table('kas_foto', function (Blueprint $table) {
            $table->dropIndex(['sumber', 'no_id']);
            $table->dropColumn('sumber');
        });
        Schema::dropIfExists('uj_detail');
        Schema::dropIfExists('uj_transaksi');
    }
};
