<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tanya AI: percakapan per pengguna & setiap pesan (pertanyaan, jawaban, tool data ERP yang dipakai) — log lengkap.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_percakapan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('judul', 200)->nullable();
            $table->string('response_id', 120)->nullable(); // jawaban terakhir di OpenAI (lanjutan percakapan)
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_percakapan_id')->constrained('ai_percakapan')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('peran', 10); // tanya | jawab | galat
            $table->longText('isi');
            $table->string('sumber', 10)->nullable(); // ketik | suara (pertanyaan)
            $table->json('tool')->nullable();         // tool data ERP yang dipanggil AI: nama, argumen, ukuran hasil
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('token_masuk')->nullable();
            $table->unsignedInteger('token_keluar')->nullable();
            $table->unsignedInteger('durasi_ms')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pesan');
        Schema::dropIfExists('ai_percakapan');
    }
};
