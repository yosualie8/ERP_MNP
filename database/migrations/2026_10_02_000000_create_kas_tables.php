<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_center', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->string('nama')->nullable();
            $table->timestamps();
        });

        Schema::create('akun_gl', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->string('kelompok', 30);
            $table->timestamps();
        });

        // Kode GL persis seperti ditulis di sheet → akun baku + cost center + nomor T (hasil urai, bisa dikoreksi).
        Schema::create('kode_gl', function (Blueprint $table) {
            $table->id();
            $table->string('kode_asli')->unique();
            $table->foreignId('akun_gl_id')->nullable()->constrained('akun_gl')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_center')->nullOnDelete();
            $table->string('ref', 20)->nullable();
            $table->boolean('dikoreksi_manual')->default(false);
            $table->timestamps();
        });

        // Ringkasan per lembar bulanan, dipakai untuk rekonsiliasi.
        Schema::create('kas_bulan', function (Blueprint $table) {
            $table->id();
            $table->string('lembar', 10)->unique();
            $table->date('bulan')->unique();
            $table->bigInteger('saldo_awal');
            $table->bigInteger('total_debet');
            $table->bigInteger('total_kredit');
            $table->bigInteger('total_bon');
            $table->bigInteger('saldo_akhir');
            $table->bigInteger('saldo_akhir_sheet')->nullable();
            $table->json('catatan')->nullable();
            $table->timestamp('diimpor_pada');
            $table->timestamps();
        });

        // Satu baris rekening Bank Jago: uang masuk (debet) atau transfer keluar (kredit).
        Schema::create('kas_transfer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kas_bulan_id')->constrained('kas_bulan')->cascadeOnDelete();
            $table->date('tanggal');
            $table->unsignedInteger('baris');
            $table->string('nama_tujuan')->nullable();
            $table->string('no_rek_tujuan', 50)->nullable();
            $table->string('bank_tujuan', 50)->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->bigInteger('debet')->default(0);
            $table->bigInteger('kredit')->default(0);
            $table->bigInteger('saldo');
            $table->timestamps();
            $table->index(['kas_bulan_id', 'baris']);
            $table->index('tanggal');
        });

        // Rincian bon di balik satu transfer (Detail Kredit).
        Schema::create('kas_bon', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kas_transfer_id')->constrained('kas_transfer')->cascadeOnDelete();
            $table->date('tanggal');
            $table->unsignedInteger('baris');
            $table->bigInteger('nominal');
            $table->string('pic', 100)->nullable();
            $table->string('keterangan', 500)->nullable();
            $table->string('gl', 100)->nullable();
            $table->foreignId('kode_gl_id')->nullable()->constrained('kode_gl')->nullOnDelete();
            $table->string('kode_bon', 100)->nullable();
            $table->string('no_id', 30)->nullable();
            $table->string('id_transaksi', 60)->nullable()->index();
            $table->timestamps();
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_bon');
        Schema::dropIfExists('kas_transfer');
        Schema::dropIfExists('kas_bulan');
        Schema::dropIfExists('kode_gl');
        Schema::dropIfExists('akun_gl');
        Schema::dropIfExists('cost_center');
    }
};
