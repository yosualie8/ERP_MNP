<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Transfer Massal BCA (Multi Auto-Transfer KlikBCA Bisnis): pengaturan aplikasi (mis. rekening debet BCA PT) & riwayat batch
// file Excel yang dibuat (Transaction ID harus unik 3 bulan, jadi nomor batch dicatat).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_app', function (Blueprint $table) {
            $table->string('kunci', 60)->primary();
            $table->text('nilai')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('transfer_bca', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal_efektif');
            $table->string('rekening_debet', 20);
            $table->unsignedInteger('jumlah');
            $table->bigInteger('total');
            $table->json('isi');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_bca');
        Schema::dropIfExists('pengaturan_app');
    }
};
