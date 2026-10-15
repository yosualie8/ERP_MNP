<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Nama lokasi absen tidak lagi diketik: disamakan dengan kolom Lokasi tahap di Daftar Proyek (kosong → nama tahap). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lokasi_absen as l')->join('proyek_tahap as t', 't.id', '=', 'l.proyek_tahap_id')
            ->update(['l.nama' => DB::raw('LEFT(COALESCE(NULLIF(t.lokasi, ""), t.nama), 120)')]);
    }

    public function down(): void
    {
        //
    }
};
