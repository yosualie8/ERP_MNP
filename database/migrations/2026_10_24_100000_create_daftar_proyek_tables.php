<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Daftar Proyek: proyek induk (ASG, Infra, Pantai Mutiara 2, …) → tahap (unit biaya & tagihan) → alias (nama lama/variasi
// tulisan di Ritasi, Penggunaan BBM, Cost Project, Kas Harian yang dipetakan ke tahap itu). Data awal dari lembar "Cost Project ERP".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyek', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->string('cost_center', 30)->nullable(); // kode cost center Kas Harian
            $table->unsignedSmallInteger('urutan')->default(99);
            $table->timestamps();
        });

        Schema::create('proyek_tahap', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_id')->constrained('proyek')->cascadeOnDelete();
            $table->string('nama', 150)->unique();
            $table->string('tipe', 10)->default('luas');     // luas (luas & volume) | ritasi | biaya (biaya tanpa tahap / lokasi)
            $table->string('status', 10)->default('belum');  // belum | progress | selesai | batal
            $table->string('lokasi', 200)->nullable();       // lokasi / surveyor
            $table->decimal('volume', 14, 2)->nullable();    // m³
            $table->decimal('luas', 14, 2)->nullable();      // m²
            $table->unsignedBigInteger('harga_m3')->nullable();
            $table->unsignedBigInteger('omset')->nullable(); // kosong = volume × harga per m³
            $table->unsignedInteger('tarif_bbm')->nullable();    // Rp per liter
            $table->unsignedInteger('tarif_rental')->nullable(); // Rp per jam HM
            $table->decimal('komisi_lapangan', 5, 2)->nullable(); // % dari omset
            $table->decimal('komisi_yosua', 5, 2)->nullable();
            $table->decimal('komisi_verlix', 5, 2)->nullable();
            $table->date('bayar_lapangan')->nullable();
            $table->date('bayar_yosua')->nullable();
            $table->date('bayar_verlix')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('proyek_alias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_tahap_id')->constrained('proyek_tahap')->cascadeOnDelete();
            $table->string('sumber', 12);   // ritasi | bbm | cost_project | kas (cost center + kode tahap) | kas_cc (cost center tanpa tahap)
            $table->string('nama', 200);    // tulisan asli (spasi dirapikan)
            $table->string('kunci', 200);   // huruf kecil, untuk pencocokan
            $table->timestamps();
            $table->unique(['sumber', 'kunci']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyek_alias');
        Schema::dropIfExists('proyek_tahap');
        Schema::dropIfExists('proyek');
    }
};
