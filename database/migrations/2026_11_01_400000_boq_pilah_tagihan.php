<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOQ per tahap (owner 16 Okt 2026): item penawaran (volume × harga jual) + modal (material & jasa), kode BOQ per
 * transaksi Kas/BCA (halaman Pilah Transaksi), opname volume terpasang, dan tagihan progres per item — supaya progres
 * bisa dibandingkan secara modal (biaya ÷ modal), terpasang (volume) dan tertagih.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boq_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_tahap_id')->constrained('proyek_tahap')->cascadeOnDelete();
            $table->string('kode', 20);
            $table->string('uraian', 255);
            $table->string('satuan', 20)->nullable();
            $table->decimal('volume', 14, 3)->nullable();
            $table->decimal('harga', 16, 2)->nullable();          // harga jual per satuan
            $table->decimal('modal_material', 16, 2)->nullable(); // total modal material item ini
            $table->decimal('modal_jasa', 16, 2)->nullable();     // total modal jasa / borongan item ini
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
            $table->unique(['proyek_tahap_id', 'kode']);
        });
        Schema::create('transaksi_boq', function (Blueprint $table) {
            $table->id();
            $table->string('sumber', 5);  // kas | bca
            $table->string('kunci', 80);  // kunci kas_alokasi (Kas) / mutasi_pt_tanda (BCA)
            $table->foreignId('boq_item_id')->constrained('boq_item')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sumber', 'kunci']);
        });
        Schema::create('boq_opname', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_item_id')->constrained('boq_item')->cascadeOnDelete();
            $table->date('tanggal');
            $table->decimal('volume', 14, 3); // volume terpasang KUMULATIF s/d tanggal ini
            $table->string('catatan', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('tagihan_proyek', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_tahap_id')->constrained('proyek_tahap')->cascadeOnDelete();
            $table->string('nomor', 60);
            $table->date('tanggal');
            $table->string('catatan', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('tagihan_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_proyek_id')->constrained('tagihan_proyek')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_item')->cascadeOnDelete();
            $table->decimal('volume', 14, 3);
            $table->decimal('nilai', 16, 2); // volume × harga jual saat ditagih
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan_item');
        Schema::dropIfExists('tagihan_proyek');
        Schema::dropIfExists('boq_opname');
        Schema::dropIfExists('transaksi_boq');
        Schema::dropIfExists('boq_item');
    }
};
