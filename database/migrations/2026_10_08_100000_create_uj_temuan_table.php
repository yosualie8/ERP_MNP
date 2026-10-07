<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Temuan validasi Input UJ (FLAG) beserta konfirmasi admin — bahan halaman Review Admin.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uj_temuan', function (Blueprint $table) {
            $table->id();
            $table->string('id_uj', 20)->index();
            $table->string('aturan', 10);
            $table->string('prioritas', 10);
            $table->string('pesan', 1000);
            $table->string('konfirmasi', 1000)->nullable();
            $table->string('status', 20)->default('Perlu Review');
            $table->string('catatan_admin', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uj_temuan');
    }
};
