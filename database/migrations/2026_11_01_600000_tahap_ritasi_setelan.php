<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap tipe RITASI (owner 17 Okt 2026): tanpa anggaran HPP/omset — cukup dasar bayar (per rit / per m³ bongkaran) + harga;
 * omset & biaya realisasi mengikuti data. kata_uj = kata kunci keterangan Kas UJ untuk UJ yang belum bongkar (DO belum ada di Ritasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->string('dasar_bayar', 5)->nullable()->after('harga_m3'); // m3 | rit
            $table->decimal('harga_rit', 14, 2)->nullable()->after('dasar_bayar');
            $table->string('kata_uj', 200)->nullable()->after('harga_rit');
        });
        DB::table('proyek_tahap')->where('nama', 'Kawasan Industri Kendal')->update(['dasar_bayar' => 'm3', 'kata_uj' => 'KIK, Kendal']);
    }

    public function down(): void
    {
        Schema::table('proyek_tahap', fn (Blueprint $table) => $table->dropColumn(['dasar_bayar', 'harga_rit', 'kata_uj']));
    }
};
