<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penandaan mutasi Rekening PT (BCA): dihitung / tidak dihitung sebagai biaya + cost center (+ tahap opsional).
 * Terpisah dari mutasi_pt karena tabel itu diganti utuh tiap impor; dicocokkan lewat kunci isi baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutasi_pt_tanda', function (Blueprint $table) {
            $table->id();
            $table->char('kunci', 40)->unique();        // sha1(tanggal|dbcr|jumlah|saldo|keterangan)
            $table->string('status', 10);               // dihitung | tidak | belum
            $table->string('cost_center', 30)->nullable();
            $table->foreignId('proyek_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->string('catatan', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_pt_tanda');
    }
};
