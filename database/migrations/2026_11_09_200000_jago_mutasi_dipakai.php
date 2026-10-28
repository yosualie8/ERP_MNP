<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master transaksi hanya dari mutasi Jago (owner 28 Okt 2026): baris mutasi yang dipakai sebagai master Input UJ / Input Kas ditandai
 * ("uj:{no_uj}" / "kas:{NO ID}") supaya tidak dipakai dua kali dan master-nya tetap terkunci saat diedit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jago_mutasi', function (Blueprint $table) {
            $table->string('dipakai', 40)->nullable()->after('saldo')->index();
            $table->dateTime('dipakai_pada')->nullable()->after('dipakai');
            $table->foreignId('dipakai_oleh')->nullable()->after('dipakai_pada')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jago_mutasi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dipakai_oleh');
            $table->dropColumn(['dipakai', 'dipakai_pada']);
        });
    }
};
