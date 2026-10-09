<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Transfer Kas Harian yang membiayai sebuah Pengajuan UJ (dipilih admin). Dikunci lewat NO ID Kas Harian + tanggalnya
// (id baris kas_transfer berubah setiap impor ulang); tanggal, nominal, tujuan & keterangan disalin saat ditautkan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uj_pengajuan_transfer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uj_pengajuan_id')->constrained('uj_pengajuan')->cascadeOnDelete();
            $table->unsignedInteger('kas_no_id');
            $table->date('kas_tanggal');
            $table->bigInteger('nominal');
            $table->string('nama_tujuan', 150)->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['uj_pengajuan_id', 'kas_no_id']);
            $table->index('kas_no_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uj_pengajuan_transfer');
    }
};
