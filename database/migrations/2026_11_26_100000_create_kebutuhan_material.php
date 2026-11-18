<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kebutuhan Material (owner 18 Nov 2026): owner diskusi dengan orang lapangan, tiap material yang sudah fix direkam suara (🎤 pendek),
 * suara → teks → item (nama, qty, satuan, keterangan) dengan saran harga & satuan dari riwayat transaksi. Rekaman bisa dibuat tanpa sinyal
 * (disimpan di HP/tablet) lalu dikirim saat sinyal ada; uid dari perangkat mencegah rekaman yang sama diproses dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kebutuhan_material_rekaman', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 40)->unique();                 // dibuat perangkat saat merekam (idempoten saat kirim ulang)
            $table->foreignId('proyek_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->string('sumber', 10)->default('suara');      // suara | ketik
            $table->string('audio', 200)->nullable();             // path di storage/app
            $table->unsignedInteger('durasi')->nullable();        // detik
            $table->text('transkrip')->nullable();
            $table->string('status', 10)->default('baru');       // baru | selesai | gagal
            $table->string('galat', 500)->nullable();
            $table->timestamp('direkam_pada')->nullable();        // jam di perangkat saat merekam (bisa jauh sebelum terkirim)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kebutuhan_material', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->foreignId('rekaman_id')->nullable()->constrained('kebutuhan_material_rekaman')->nullOnDelete();
            $table->date('tanggal');
            $table->string('nama_item', 200);
            $table->decimal('qty', 12, 2)->nullable();
            $table->string('satuan', 20)->nullable();
            $table->decimal('harga', 15, 2)->nullable();          // harga yang dipastikan owner (null = belum dicek)
            $table->decimal('harga_saran', 15, 2)->nullable();    // saran sistem saat item dibuat / nama diubah
            $table->string('dasar_harga', 300)->nullable();        // ringkasan asal saran (mis. "Kas Harian 29 Jun 2026: 10 sak Semen Jempolan")
            $table->string('keterangan', 300)->nullable();         // untuk pekerjaan / lokasi / spesifikasi
            $table->boolean('dicek')->default(false);              // harga sudah dipastikan → jadi riwayat harga untuk saran berikutnya
            $table->unsignedSmallInteger('urut')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['proyek_tahap_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kebutuhan_material');
        Schema::dropIfExists('kebutuhan_material_rekaman');
    }
};
