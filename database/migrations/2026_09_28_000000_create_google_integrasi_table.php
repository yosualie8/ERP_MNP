<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu akun Google yang sheet-nya dibaca aplikasi (izin Sheets + Drive baca-saja).
        Schema::create('google_integrasi', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_kedaluwarsa')->nullable();
            $table->json('izin')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_integrasi');
    }
};
