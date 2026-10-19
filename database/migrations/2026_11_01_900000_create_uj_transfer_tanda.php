<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda transfer Pengajuan UJ (owner 19 Okt 2026): saat owner mentransfer, ia menandai pengajuan mana yang dibayar dengan
 * nominal & jam transfer itu — sebelum transaksinya ada di Kas Harian. H+1 setelah Kas Harian direkap, penanda dipakai untuk
 * menautkan transfer Kas Harian yang sebenarnya (kas_no_id terisi = selesai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uj_transfer_tanda', function (Blueprint $table) {
            $table->id();
            $table->dateTime('waktu');
            $table->unsignedBigInteger('nominal');
            $table->string('catatan', 300)->nullable();
            $table->unsignedInteger('kas_no_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('uj_transfer_tanda_pengajuan', function (Blueprint $table) {
            $table->foreignId('uj_transfer_tanda_id')->constrained('uj_transfer_tanda')->cascadeOnDelete();
            $table->foreignId('uj_pengajuan_id')->constrained('uj_pengajuan')->cascadeOnDelete();
            $table->primary(['uj_transfer_tanda_id', 'uj_pengajuan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uj_transfer_tanda_pengajuan');
        Schema::dropIfExists('uj_transfer_tanda');
    }
};
