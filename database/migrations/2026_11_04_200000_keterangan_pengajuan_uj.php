<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keterangan / keperluan pengajuan UJ (owner 22 Okt 2026) — satu per pengajuan, tampil di grid Pengajuan UJ. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uj_pengajuan', function (Blueprint $table) {
            $table->string('keterangan', 300)->nullable()->after('tanggal');
        });
    }

    public function down(): void
    {
        Schema::table('uj_pengajuan', function (Blueprint $table) {
            $table->dropColumn('keterangan');
        });
    }
};
