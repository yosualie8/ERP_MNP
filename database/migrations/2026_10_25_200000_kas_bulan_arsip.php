<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lembar Kas Harian lama (spreadsheet "Kas Harian MNP - 2025", Jan 2024–Des 2025): arsip, hanya dilihat, tidak ikut antrean reimburse. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kas_bulan', function (Blueprint $table) {
            $table->boolean('arsip')->default(false)->after('lembar');
        });
    }

    public function down(): void
    {
        Schema::table('kas_bulan', function (Blueprint $table) {
            $table->dropColumn('arsip');
        });
    }
};
