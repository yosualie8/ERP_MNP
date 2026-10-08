<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pengajuan uang jalan (menu Pengajuan UJ): disimpan di aplikasi, divalidasi sama seperti Input UJ, lalu direalisasikan
// per detail lewat Input UJ (detail terealisasi menyimpan ID UJ-nya di Kas Seabank).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uj_pengajuan', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('nama', 150);
            $table->string('bank', 60)->nullable();
            $table->string('rekening', 40)->nullable();
            $table->unsignedBigInteger('nominal');
            $table->string('status', 20)->default('diajukan'); // diajukan | sebagian | selesai | batal
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('uj_pengajuan_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uj_pengajuan_id')->constrained('uj_pengajuan')->cascadeOnDelete();
            $table->unsignedSmallInteger('urut');
            $table->string('nama', 150)->nullable();
            $table->string('keterangan', 500);
            $table->unsignedBigInteger('nominal');
            $table->string('kategori', 60);
            $table->string('jenis_kendaraan', 40)->nullable();
            $table->string('no_mobil', 40)->nullable();
            $table->string('no_do', 40)->nullable();
            $table->string('konfirmasi', 1000)->nullable();
            $table->json('temuan')->nullable();
            $table->string('status', 20)->default('menunggu'); // menunggu | terealisasi | batal
            $table->string('id_uj', 30)->nullable()->index();
            $table->timestamp('realisasi_pada')->nullable();
            $table->foreignId('realisasi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'no_do']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uj_pengajuan_detail');
        Schema::dropIfExists('uj_pengajuan');
    }
};
