<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost center dimiliki tiap tahap (proyek detail), tidak lagi "ikut proyek induk". Tahap yang selama ini ikut induk
 * diberi cost center induknya (hasilnya sama dengan yang tampil sebelumnya), lalu kolom cost center di induk dihapus.
 * Kecuali "Kawasan Industri Kendal" (dibuat 15 Okt 2026, cost center belum dipilih owner — bukan ASG).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('proyek_tahap as t')->join('proyek as p', 'p.id', '=', 't.proyek_id')
            ->whereNull('t.cost_center')->whereNotNull('p.cost_center')->where('t.nama', '!=', 'Kawasan Industri Kendal')
            ->update(['t.cost_center' => DB::raw('p.cost_center')]);
        Schema::table('proyek', function (Blueprint $table) {
            $table->dropColumn('cost_center');
        });
    }

    public function down(): void
    {
        Schema::table('proyek', function (Blueprint $table) {
            $table->string('cost_center', 30)->nullable()->after('nama');
        });
    }
};
