<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penggunaan BBM: cermin lembar "Penggunaan BBM" (satu baris sheet = satu baris di sini, kunci = nomor baris sheet).
 * Diimpor ulang tiap 5 menit; input dari aplikasi ditulis ke sheet lalu ke tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bbm', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('baris')->index();
            $table->date('tanggal')->nullable()->index();
            $table->string('unit', 100)->nullable();
            $table->string('jenis', 12)->default('cek');          // dari bbm_unit_peta: pakai | masuk | pindah | susut | opname | operasional | cek
            $table->foreignId('aset_id')->nullable()->constrained('aset_truk')->nullOnDelete();
            $table->decimal('hm_awal', 10, 1)->nullable();
            $table->decimal('hm_akhir', 10, 1)->nullable();
            $table->decimal('total_hm', 8, 2)->nullable();
            $table->decimal('masuk', 10, 2)->nullable();
            $table->decimal('keluar', 10, 2)->nullable();
            $table->decimal('stok', 12, 2)->nullable();
            $table->string('jam_mulai', 10)->nullable();
            $table->string('istirahat', 10)->nullable();
            $table->string('jam_selesai', 10)->nullable();
            $table->string('status', 40)->nullable();
            $table->string('keterangan', 300)->nullable();
            $table->decimal('rental', 6, 2)->nullable();          // jam kerja / jam sewa
            $table->string('proyek', 500)->nullable();            // "Tahap A + Tahap B" = dibagi rata
            $table->json('tahap_ids')->nullable();
            $table->string('keterangan_kerja', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bbm');
    }
};
