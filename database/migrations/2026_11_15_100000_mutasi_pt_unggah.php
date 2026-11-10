<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upload file mutasi KlikBCA Bisnis (owner 10 Nov 2026): tiap unggahan mengganti baris mutasi_pt di rentang periode file;
 * baris "PEND" (belum dibukukan bank) ditandai pending dan diganti saat file berikutnya memuat transaksi yang sama.
 * Begitu ada unggahan, impor file Drive tidak lagi mengganti tabel (lihat ImporMutasiPt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutasi_pt_unggah', function (Blueprint $t) {
            $t->id();
            $t->string('nama_file', 200);
            $t->string('rekening', 30);
            $t->date('dari');
            $t->date('sampai');
            $t->unsignedInteger('baris');
            $t->unsignedInteger('pending')->default(0);
            $t->decimal('saldo_awal', 18, 2);
            $t->decimal('saldo_akhir', 18, 2);
            $t->json('ringkasan')->nullable();   // baru / sama / diganti / peringatan
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('mutasi_pt', function (Blueprint $t) {
            $t->boolean('pending')->default(false)->after('saldo');
            $t->foreignId('unggah_id')->nullable()->after('pending')->constrained('mutasi_pt_unggah')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mutasi_pt', function (Blueprint $t) {
            $t->dropConstrainedForeignId('unggah_id');
            $t->dropColumn('pending');
        });
        Schema::dropIfExists('mutasi_pt_unggah');
    }
};
