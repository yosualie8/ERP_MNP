<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database Sparepart v2 (owner 16 Nov 2026):
 * - sparepart_item: Merk & Ori/KW per item (nama baku × satuan) yang diisi Admin Plant — terpisah dari sparepart_beli supaya tidak hilang
 *   saat riwayat pembelian diimpor ulang (mnp:impor-sparepart mengganti isi sparepart_beli utuh).
 * - supplier: Master Supplier (menu Plant & Sparepart). Rekening TIDAK disimpan di sini — dibaca dari Daftar Rekening (rekening.nama = nama supplier).
 *   Diisi awal dari supplier terverifikasi di sparepart_beli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sparepart_item', function (Blueprint $table) {
            $table->id();
            $table->string('nama_item', 200);
            $table->string('satuan', 20)->default('');   // '' = tanpa satuan (bukan null supaya kunci unik berlaku)
            $table->string('merk', 80)->nullable();
            $table->string('kualitas', 3)->nullable();   // ori | kw
            $table->string('catatan', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['nama_item', 'satuan']);
        });

        Schema::create('supplier', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150)->unique();
            $table->string('kontak', 100)->nullable();   // nama orang yang dihubungi
            $table->string('telepon', 40)->nullable();
            $table->string('alamat', 300)->nullable();
            $table->string('barang', 200)->nullable();   // barang yang biasa dibeli (bebas)
            $table->string('catatan', 500)->nullable();
            $table->boolean('aktif')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (Schema::hasTable('sparepart_beli')) {
            $sekarang = now();
            foreach (DB::table('sparepart_beli')->whereNotNull('supplier')->distinct()->orderBy('supplier')->pluck('supplier') as $nama) {
                DB::table('supplier')->insertOrIgnore(['nama' => $nama, 'aktif' => true, 'created_at' => $sekarang, 'updated_at' => $sekarang]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier');
        Schema::dropIfExists('sparepart_item');
    }
};
