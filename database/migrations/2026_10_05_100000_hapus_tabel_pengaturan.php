<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tabel pengaturan hanya dipakai untuk API key Claude (scan foto bon), fitur yang dibatalkan 5 Okt 2026.
    public function up(): void
    {
        Schema::dropIfExists('pengaturan');
    }

    public function down(): void
    {
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->id();
            $table->string('kunci')->unique();
            $table->text('nilai')->nullable();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};
