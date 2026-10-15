<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 15 Okt 2026: (1) komisi lapangan tidak dihitung di HPP rencana sebelum direalisasikan; (3) biaya umum di Kas
 * (pos "tanpa tahap", kantor, dll.) dibagi ke tahap yang berjalan — tahap tujuan dipilih manual dari rekomendasi
 * (proyek induk yang sama / semua proyek), dibagi proporsional omset atau rata. Alokasi per bulan per pos.
 */
return new class extends Migration
{
    /** Pos biaya umum bawaan: id tahap => cakupan rekomendasi (induk = tahap proyek induk yang sama; semua = semua proyek). */
    private const POS = [125 => 'induk', 131 => 'induk', 133 => 'induk', 157 => 'semua', 159 => 'semua', 161 => 'semua', 162 => 'semua'];

    public function up(): void
    {
        Schema::table('komponen_anggaran', function (Blueprint $table) {
            $table->boolean('tunda_realisasi')->default(false)->after('cara'); // tidak masuk HPP rencana sebelum direalisasikan
        });
        DB::table('komponen_anggaran')->where('kode', 'komisi_lapangan')->update(['tunda_realisasi' => true]);

        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->string('pos_overhead', 10)->nullable(); // null = bukan pos biaya umum; induk | semua = cakupan rekomendasi
            $table->string('metode_overhead', 10)->default('omset'); // omset | rata
        });
        foreach (self::POS as $id => $cakupan) {
            DB::table('proyek_tahap')->where('id', $id)->update(['pos_overhead' => $cakupan]);
        }

        Schema::create('alokasi_overhead', function (Blueprint $table) {
            $table->id();
            $table->char('bulan', 7);                       // YYYY-MM
            $table->foreignId('pos_tahap_id')->constrained('proyek_tahap')->cascadeOnDelete();
            $table->string('metode', 10);                   // omset | rata
            $table->json('tahap_ids');                      // tahap tujuan yang dipilih
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['bulan', 'pos_tahap_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alokasi_overhead');
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->dropColumn(['pos_overhead', 'metode_overhead']);
        });
        Schema::table('komponen_anggaran', function (Blueprint $table) {
            $table->dropColumn('tunda_realisasi');
        });
    }
};
