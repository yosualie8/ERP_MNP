<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kas Harian: nomor truk per transaksi detail (kolom S "NO MOBIL" di lembar bulanan), untuk biaya per truk.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kas_bon', function (Blueprint $table) {
            $table->string('no_mobil', 20)->nullable()->after('id_transaksi')->index();
        });
    }

    public function down(): void
    {
        Schema::table('kas_bon', function (Blueprint $table) {
            $table->dropIndex(['no_mobil']);
            $table->dropColumn('no_mobil');
        });
    }
};
