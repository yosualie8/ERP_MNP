<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Database Sparepart (owner 16 Nov 2026): kolom Part Number per item (diisi Admin Plant; kosong = usulan dari spesifikasi pembelian). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sparepart_item', function (Blueprint $table) {
            $table->string('part_number', 80)->nullable()->after('satuan');
        });
    }

    public function down(): void
    {
        Schema::table('sparepart_item', function (Blueprint $table) {
            $table->dropColumn('part_number');
        });
    }
};
