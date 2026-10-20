<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo sopir per SOPIR, tanpa proyek tujuan (owner 20 Okt 2026): sopir sering pindah truk (bahkan kabur), dan saat UJ dialihkan
 * belum diketahui uangnya akan dipakai di proyek mana. uj_pengalihan.sopir = nama sopir baku (huruf besar) pemilik saldo.
 * Pengalihan lama: proyek tujuan dikosongkan; pemakaian lewat Pengajuan UJ juga (proyeknya ditentukan dari DO / keterangan UJ-nya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uj_pengalihan', function (Blueprint $table) {
            $table->string('sopir', 100)->nullable()->after('nama')->index();
        });
        DB::statement('ALTER TABLE uj_pengalihan MODIFY no_mobil VARCHAR(20) NULL');
        DB::table('uj_pengalihan')->whereNotNull('nama')->update(['sopir' => DB::raw("UPPER(TRIM(nama))")]);
        DB::table('uj_pengalihan')->where('jenis', 'alih')->update(['ke_tahap_id' => null]);
        DB::table('uj_pengalihan')->where('jenis', 'pakai')->whereNotNull('uj_pengajuan_detail_id')->update(['ke_tahap_id' => null]);
    }

    public function down(): void
    {
        Schema::table('uj_pengalihan', fn (Blueprint $table) => $table->dropColumn('sopir'));
    }
};
