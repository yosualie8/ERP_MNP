<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ritasi dump truck: lembar "Ritasi" spreadsheet "Proyek ASG - Gsheet" (satu baris = satu rit), diimpor dari sheet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ritasi', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('baris')->index();
            $table->string('tahap', 120)->nullable()->index();
            $table->string('no_seri', 30)->nullable()->index();
            $table->date('tanggal')->nullable()->index();
            $table->string('jam', 10)->nullable();
            $table->string('plat', 30)->nullable();
            $table->string('no_lambung', 20)->nullable()->index();
            $table->string('no_polisi', 80)->nullable();
            $table->string('galian', 80)->nullable();
            $table->string('jenis_buangan', 40)->nullable();
            $table->string('jenis_tanah', 60)->nullable();
            $table->string('jenis_kendaraan', 40)->nullable();
            $table->string('pemilik', 60)->nullable();
            $table->bigInteger('harga_jual')->nullable();
            $table->string('keterangan', 300)->nullable();
            $table->string('status_bayar', 30)->nullable();
            $table->string('no_do', 30)->nullable()->index();
            $table->timestamps();
        });

        // Temuan validasi Input Ritasi (FLAG) + konfirmasi admin.
        Schema::create('ritasi_temuan', function (Blueprint $table) {
            $table->id();
            $table->string('tahap', 120)->nullable();
            $table->string('no_seri', 30)->nullable()->index();
            $table->string('no_do', 30)->nullable();
            $table->string('aturan', 10);
            $table->string('prioritas', 10);
            $table->string('pesan', 1000);
            $table->string('konfirmasi', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ritasi_temuan');
        Schema::dropIfExists('ritasi');
    }
};
