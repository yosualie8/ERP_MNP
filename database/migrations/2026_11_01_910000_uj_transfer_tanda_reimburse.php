<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Penanda transfer berdasar batch Reimburse UJ (owner 19 Okt 2026): satu batch reimburse = satu penanda; pengajuan bisa ditambahkan bertahap. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uj_transfer_tanda', function (Blueprint $table) {
            $table->foreignId('uj_reimburse_id')->nullable()->unique()->after('id')->constrained('uj_reimburse')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uj_transfer_tanda', fn (Blueprint $table) => $table->dropConstrainedForeignId('uj_reimburse_id'));
    }
};
