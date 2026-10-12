<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cost center per tahap (kosong = ikut proyek induk), untuk proyek induk yang tahapnya beda lokasi (mis. Proyek Lain-lain). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->string('cost_center', 20)->nullable()->after('lokasi');
        });
    }

    public function down(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->dropColumn('cost_center');
        });
    }
};
