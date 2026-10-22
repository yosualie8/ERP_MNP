<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kata kunci Kode GL per tahap (owner 22 Okt 2026): Kode GL Kas Harian yang memuat salah satu kata ini dihitung ke tahap itu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->string('kata_gl', 500)->nullable()->after('kata_uj');
        });
    }

    public function down(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->dropColumn('kata_gl');
        });
    }
};
