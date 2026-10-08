<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Data induk truk milik PT MNP (menu Data Aset). Milik aplikasi (sumber kebenaran), bukan salinan sheet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_truk', function (Blueprint $table) {
            $table->id();
            $table->string('no_lambung', 20)->unique();   // "DT 026"
            $table->string('plat', 20)->nullable();
            $table->string('jenis', 40)->nullable();       // Faw, Mercy, Giga, …
            $table->unsignedSmallInteger('tahun')->nullable();
            $table->string('no_rangka', 60)->nullable();
            $table->string('no_mesin', 60)->nullable();
            $table->date('stnk_berlaku')->nullable();
            $table->date('kir_berlaku')->nullable();
            $table->string('status', 20)->default('aktif'); // aktif | perbaikan | tidak_aktif | dijual
            $table->string('driver_tetap', 60)->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_truk');
    }
};
