<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login di perangkat lain lewat QR sekali pakai (owner 27 Okt 2026: tes di HP pinjaman tanpa memasukkan akun Google).
 * Token disimpan sebagai hash; sesi yang terbentuk dicatat supaya bisa dikeluarkan dari laptop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_perangkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('kedaluwarsa_pada');
            $table->string('ip_buat', 45)->nullable();
            $table->dateTime('dipakai_pada')->nullable();
            $table->string('ip_pakai', 45)->nullable();
            $table->string('perangkat', 255)->nullable();
            $table->string('sesi_id', 100)->nullable();
            $table->dateTime('dikeluarkan_pada')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_perangkat');
    }
};
