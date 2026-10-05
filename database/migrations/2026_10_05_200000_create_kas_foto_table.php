<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NO ID baris transfer di sheet (kolom Q): kunci tetap, tidak berubah saat lembar diimpor ulang.
        Schema::table('kas_transfer', function (Blueprint $table) {
            $table->unsignedBigInteger('no_id')->nullable()->after('baris')->index();
        });

        // Foto bon sebagai referensi, ditautkan ke NO ID transfer (bukan id baris yang dibuat ulang tiap impor).
        Schema::create('kas_foto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('no_id')->index();
            $table->string('lembar', 10);
            $table->string('path');
            $table->string('nama_asli')->nullable();
            $table->unsignedInteger('ukuran')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_foto');
        Schema::table('kas_transfer', function (Blueprint $table) {
            $table->dropColumn('no_id');
        });
    }
};
