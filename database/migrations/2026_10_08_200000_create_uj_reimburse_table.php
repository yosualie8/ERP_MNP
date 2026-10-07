<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Batch reimburse uang jalan: transaksi Kas Seabank yang ditandai sudah reimburse sekaligus (+ isi untuk file Excel).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uj_reimburse', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->bigInteger('target')->nullable();
            $table->bigInteger('total');
            $table->unsignedInteger('jumlah_transfer');
            $table->unsignedInteger('jumlah_baris');
            $table->json('isi');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uj_reimburse');
    }
};
