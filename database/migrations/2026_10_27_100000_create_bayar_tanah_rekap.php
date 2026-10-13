<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekap Bayar Tanah (RBT-0001): DO + harga tanah yang disiapkan di menu Bayar Tanah, lalu dibayar admin lewat Input UJ
 * (form terisi otomatis). Status: menunggu → dibayar (UJ-xxxx) / batal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bayar_tanah_rekap', function (Blueprint $table) {
            $table->id();
            $table->string('status', 15)->default('menunggu'); // menunggu | dibayar | batal
            $table->unsignedInteger('jumlah');
            $table->unsignedBigInteger('total');
            $table->json('isi'); // [{do, harga}]
            $table->string('catatan', 500)->nullable();
            $table->unsignedInteger('no_uj')->nullable();
            $table->timestamp('dibayar_pada')->nullable();
            $table->foreignId('dibayar_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bayar_tanah_rekap');
    }
};
