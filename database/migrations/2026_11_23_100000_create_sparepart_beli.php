<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Sparepart (owner 16 Nov 2026, menu Plant & Sparepart): riwayat pembelian sparepart per baris dengan nama item baku, untuk kepala
 * mekanik mencari sparepart & histori harganya. Diisi mnp:impor-sparepart dari hasil skrip katalog (Kas Harian akun Biaya Sparepart* +
 * Kas UJ kategori Sparepart) — isinya selalu diganti utuh tiap impor, tidak diubah di web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sparepart_beli', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('kategori', 60);
            $table->string('nama_item', 200);              // nama baku; paket = "Item A + Item B"
            $table->string('spesifikasi', 150)->nullable(); // merk / ukuran / nomor part yang tertulis
            $table->string('golongan', 40)->nullable();     // DT / Alat Berat / LV / Genset / … / (unit tidak disebut)
            $table->string('tipe_unit', 60)->nullable();    // DT FAW, DT Mercy, Excavator PC200, …
            $table->string('no_unit', 20)->nullable();      // DT 026, LV 003
            $table->decimal('qty', 12, 2)->default(1);
            $table->string('satuan', 20)->nullable();
            $table->decimal('harga_satuan', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('supplier', 150)->nullable();
            $table->string('pic', 100)->nullable();
            $table->string('keterangan', 300)->nullable();   // keterangan asli detail
            $table->string('keterangan_transfer', 300)->nullable(); // keterangan transfer Kas Harian / DO Kas UJ
            $table->string('akun', 100)->nullable();
            $table->string('sumber', 20);                    // Kas Harian | Kas UJ
            $table->string('ref', 40)->nullable();           // NO ID / ID transaksi / ID UJ
            $table->date('ditarik')->nullable();             // tanggal data ditarik dari ERP
            $table->timestamps();
            $table->index(['nama_item', 'satuan']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sparepart_beli');
    }
};
