<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Sparepart (owner 16 Nov 2026, menu Plant & Sparepart): daftar sparepart yang diajukan ke manajemen.
 * Harga tiap baris diusulkan dari Database Sparepart (harga terakhir) dan boleh diubah; harga_saran menyimpan usulan saat diajukan
 * supaya manajemen bisa membandingkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_sparepart', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 20)->unique();          // PSP-00001
            $table->date('tanggal');
            $table->string('keperluan', 200)->nullable();   // untuk unit / alasan (bebas)
            $table->string('catatan', 500)->nullable();
            $table->string('status', 10)->default('diajukan'); // diajukan | disetujui | ditolak | batal
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diputus_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diputus_pada')->nullable();
            $table->string('catatan_putusan', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'tanggal']);
        });

        Schema::create('pengajuan_sparepart_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengajuan_sparepart_id')->constrained('pengajuan_sparepart')->cascadeOnDelete();
            $table->unsignedSmallInteger('urut')->default(0);
            $table->string('nama_item', 200);
            $table->string('jenis', 80)->nullable();
            $table->string('satuan', 20)->nullable();
            $table->string('part_number', 80)->nullable();
            $table->string('merk', 80)->nullable();
            $table->decimal('qty', 12, 2);
            $table->decimal('harga', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('harga_saran', 15, 2)->nullable(); // harga terakhir di Database Sparepart saat diajukan (null = item tidak ada di database)
            $table->string('keterangan', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_sparepart_detail');
        Schema::dropIfExists('pengajuan_sparepart');
    }
};
