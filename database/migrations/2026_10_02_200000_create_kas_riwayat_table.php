<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak tambah/hapus kas dari aplikasi ke sheet; isi baris yang dihapus disimpan supaya bisa dikembalikan.
        Schema::create('kas_riwayat', function (Blueprint $table) {
            $table->id();
            $table->string('aksi', 20);
            $table->string('lembar', 10);
            $table->unsignedInteger('baris_awal');
            $table->unsignedInteger('baris_akhir');
            $table->string('ringkasan', 500);
            $table->json('isi')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_riwayat');
    }
};
