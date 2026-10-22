<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database rekening bersama Kas Harian & Kas UJ (owner 22 Okt 2026): satu nomor rekening = satu nama pemilik. Kas UJ tidak punya
 * kolom penerima sendiri (kolom F = nama sopir), jadi pemilik rekeningnya diambil dari sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rekening', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 40)->unique();           // angka saja tanpa nol di depan (sheet sering menghapus nol depan)
            $table->string('no_rekening', 40);               // tulisan yang ditampilkan (angka, nol depan dipertahankan)
            $table->string('bank', 60)->nullable();
            $table->string('nama', 150)->nullable();         // nama pemilik rekening; null = belum diketahui
            $table->string('sumber', 20);                    // kas_harian | karyawan | kas_uj | admin | input
            $table->string('catatan', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekening');
    }
};
