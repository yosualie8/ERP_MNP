<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 11 Nov 2026: muatan kurang (MK) ditandai per surat jalan (semua proyek, Kendal juga — manual), nominal bawaan Rp25.000.
 * Tanda MK = hutang sopir bertambah (uj_pengalihan jenis "muatan", muatan_id); lunas begitu dipasangkan ke uang jalan berikutnya
 * yang ditransfer kurang di Input UJ (uj_pengalihan "pakai" "uj", pakai_id — terhapus bersama transaksi UJ-nya → kembali belum dipotong).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muatan_kurang', function (Blueprint $table) {
            $table->id();
            $table->string('no_seri', 100);
            $table->string('kunci_seri', 100)->index();
            $table->string('no_do', 30)->nullable();
            $table->string('no_mobil', 30)->nullable();
            $table->string('sopir', 100);
            $table->date('tanggal');
            $table->string('tahap', 150)->nullable();
            $table->string('galian', 100)->nullable();
            $table->unsignedInteger('nominal');
            $table->string('alasan', 255)->nullable();
            $table->foreignId('muatan_id')->nullable()->constrained('uj_pengalihan')->cascadeOnDelete();
            $table->foreignId('pakai_id')->nullable()->constrained('uj_pengalihan')->nullOnDelete();
            $table->string('id_uj', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muatan_kurang');
    }
};
