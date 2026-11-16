<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Database Sparepart (owner 16 Nov 2026): kolom Jenis per item — untuk Alat Berat, Truck, atau jenis lain (diisi Admin Plant; kosong = usulan dari unit pembelian). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sparepart_item', function (Blueprint $table) {
            $table->string('jenis', 80)->nullable()->after('satuan');
        });
    }

    public function down(): void
    {
        Schema::table('sparepart_item', function (Blueprint $table) {
            $table->dropColumn('jenis');
        });
    }
};
