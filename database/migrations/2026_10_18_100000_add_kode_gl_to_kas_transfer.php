<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kode GL untuk uang MASUK (mis. "Penerimaan Talangan"): ditulis di kolom Kode GL pada baris transfer itu sendiri,
// karena uang masuk tidak punya transaksi detail. Kosong di sheet → ditebak dari keterangan (sheet tidak diubah).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kas_transfer', function (Blueprint $table) {
            $table->foreignId('kode_gl_id')->nullable()->after('saldo')->constrained('kode_gl')->nullOnDelete();
            $table->boolean('kode_gl_ditebak')->default(false)->after('kode_gl_id');
        });
    }

    public function down(): void
    {
        Schema::table('kas_transfer', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kode_gl_id');
            $table->dropColumn('kode_gl_ditebak');
        });
    }
};
