<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Batch reimburse Kas Harian (menu Reimburse Kas): isi = salinan ("sidik") setiap detail yang ditandai sudah reimburse.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kas_reimburse', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->unsignedBigInteger('target')->nullable();
            $table->unsignedBigInteger('total');
            $table->unsignedInteger('jumlah_transfer');
            $table->unsignedInteger('jumlah_baris');
            $table->json('isi');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_reimburse');
    }
};
