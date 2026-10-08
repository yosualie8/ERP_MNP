<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Baris Kas UJ yang ditandai admin "bukan untuk truk tertentu" di halaman Rapikan Kas UJ (tidak ditampilkan lagi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uj_tanpa_mobil', function (Blueprint $table) {
            $table->id();
            $table->string('id_uj', 30)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uj_tanpa_mobil');
    }
};
