<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tujuan buangan (tahap/proyek bongkar) per truk yang diatur admin pengurus truk (menu Ritasi > Buangan Truck).
// Truk yang belum diatur memakai tahap rit terakhirnya.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truk_buangan', function (Blueprint $table) {
            $table->id();
            $table->string('no_lambung', 20)->unique();
            $table->string('tujuan', 100);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truk_buangan');
    }
};
