<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penawaran & SPK dari Gmail MNP (owner 11 Nov 2026): penawaran = lampiran "NNN Penawaran …pdf" di email terkirim,
 * SPK = lampiran email dari spk_admasglr@yahoo.com (scan → OCR). Satu baris = satu lampiran PDF.
 * Angka hasil baca bisa dikoreksi owner; SPK ditautkan ke tahap → omset tahap = jumlah nilai SPK tertaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penawaran_spk', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 10);                        // penawaran | spk
            $table->string('gmail_id', 40);
            $table->string('gmail_thread', 40)->nullable();
            $table->dateTime('waktu_email')->nullable();
            $table->string('dari', 200)->nullable();
            $table->string('ke', 300)->nullable();
            $table->string('subjek', 300)->nullable();
            $table->string('nama_file', 250);
            $table->string('path', 300);
            $table->string('nomor', 100)->nullable();            // penawaran: 121/SPH/PIV/XI/2026 · SPK: CAM0/PO/10/26/4100006742
            $table->string('kode', 60)->nullable();              // ringkas: penawaran "121" · SPK "CAM 4100006742"
            $table->date('tanggal')->nullable();
            $table->string('pihak', 200)->nullable();            // penawaran: kepada · SPK: pemberi kerja
            $table->text('pekerjaan')->nullable();
            $table->string('lokasi', 250)->nullable();
            $table->decimal('volume', 16, 3)->nullable();
            $table->decimal('harga', 16, 2)->nullable();
            $table->unsignedBigInteger('nilai')->nullable();
            $table->string('kode_penawaran', 20)->nullable();    // SPK: nomor penawaran yang dirujuk (mis. "113")
            $table->foreignId('penawaran_id')->nullable()->constrained('penawaran_spk')->nullOnDelete();
            $table->longText('teks')->nullable();                // teks PDF / hasil OCR
            $table->string('status', 12)->default('baru');      // baru | ditautkan | diabaikan
            $table->foreignId('proyek_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->foreignId('saran_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->string('saran_alasan', 250)->nullable();
            $table->string('catatan', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('dicek_at')->nullable();
            $table->timestamps();
            $table->unique(['gmail_id', 'nama_file']);
            $table->index(['jenis', 'status']);
            $table->index('kode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penawaran_spk');
    }
};
