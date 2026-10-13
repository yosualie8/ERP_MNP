<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tautan detail Kas Harian ↔ batch Reimburse UJ (beberapa reimburse UJ dalam satu hari dicatat terpisah). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kas_reimburse_uj', function (Blueprint $table) {
            $table->foreignId('uj_reimburse_id')->nullable()->after('no_id_transfer')->constrained('uj_reimburse')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kas_reimburse_uj', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uj_reimburse_id');
        });
    }
};
