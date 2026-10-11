<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Input Transfer BCA: daftar transfer yang diinput satu per satu ke KlikBCA Bisnis oleh skrip Playwright di laptop
// (sebagai Maker → masuk Otorisasi Transaksi). Status per baris dilaporkan skrip lewat API.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('input_bca', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->string('bank', 30);             // kode bank (DaftarBank), mis. BCA, BRI
            $table->string('rekening', 34);         // angka saja
            $table->bigInteger('nominal');
            $table->string('berita', 60)->nullable(); // dipecah 2 × 30 karakter di KlikBCA Bisnis
            $table->string('layanan', 10)->nullable(); // bank lain: bifast | online | llg | rtgs
            // menunggu → proses (sedang diinput skrip) → terinput | dilewati | gagal; batal (dibatalkan admin)
            $table->string('status', 12)->default('menunggu');
            $table->string('no_referensi', 40)->nullable();
            $table->string('pesan', 500)->nullable();
            $table->dateTime('mulai_pada')->nullable();
            $table->dateTime('selesai_pada')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('input_bca');
    }
};
