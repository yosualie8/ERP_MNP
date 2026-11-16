<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard HM & KM (owner 16 Nov 2026): angka HM alat berat / KM truk & kendaraan lain yang dicatat manual per aset per tanggal.
 * HM alat berat dari Data BBM (bbm.hm_akhir) TIDAK disalin ke sini — dibaca langsung; catatan di sini menimpa BBM pada tanggal yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aset_meter', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aset_id')->constrained('aset_truk')->cascadeOnDelete();
            $table->date('tanggal');
            $table->decimal('nilai', 12, 1);                 // HM (jam) atau KM, satuan mengikuti kategori aset
            $table->boolean('reset')->default(false);         // meter diganti / direset: angka ini awal hitungan baru
            $table->string('catatan', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['aset_id', 'tanggal']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aset_meter');
    }
};
