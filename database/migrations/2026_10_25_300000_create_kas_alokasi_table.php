<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alokasi tahap per transaksi detail Kas Harian (mis. dari lembar "Biaya Operasional" sheet Cost Project), mengalahkan
 * pemetaan dari Kode GL. Kunci tetap walau lembar diimpor ulang: ID transaksi, atau "lembar:baris" untuk lembar lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kas_alokasi', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 80)->unique();
            $table->foreignId('proyek_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->boolean('abaikan')->default(false); // bukan biaya proyek (mis. "Ignore" = cashbon/piutang)
            $table->string('sumber', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_alokasi');
    }
};
