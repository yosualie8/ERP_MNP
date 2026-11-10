<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Judul bagian BOQ per tahap (owner 10 Nov 2026): {"A": "PEKERJAAN PERSIAPAN", "B": "PEKERJAAN JALAN DAN SALURAN", …} — dari baris judul lembar penawaran. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $t) {
            $t->json('boq_bagian')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $t) {
            $t->dropColumn('boq_bagian');
        });
    }
};
