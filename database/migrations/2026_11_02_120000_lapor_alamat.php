<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nama tempat sebenarnya (reverse geocoding) untuk kiriman Lapor Lapangan di luar lokasi proyek. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lapor', fn (Blueprint $table) => $table->string('alamat', 300)->nullable()->after('di_lokasi'));
    }

    public function down(): void
    {
        Schema::table('lapor', fn (Blueprint $table) => $table->dropColumn('alamat'));
    }
};
