<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laptop penerima "Mic ke Laptop" (aplikasi Windows). Kunci hanya disimpan sebagai hash SHA-256.
        Schema::create('mic_perangkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nama', 60);
            $table->char('kunci_hash', 64)->unique();
            $table->timestamp('terakhir_aktif')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_perangkat');
    }
};
