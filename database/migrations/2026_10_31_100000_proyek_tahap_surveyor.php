<?php

use App\Models\ProyekTahap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Daftar Proyek: kolom "Surveyor" lama berisi nama surveyor + lokasi → dipisah ke surveyor & lokasi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->string('surveyor', 100)->nullable()->after('status');
        });
        foreach (DB::table('proyek_tahap')->whereNotNull('lokasi')->get(['id', 'lokasi']) as $t) {
            [$surveyor, $lokasi] = ProyekTahap::pisahSurveyor($t->lokasi);
            DB::table('proyek_tahap')->where('id', $t->id)->update(['surveyor' => $surveyor, 'lokasi' => $lokasi]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('proyek_tahap')->whereNotNull('surveyor')->get(['id', 'surveyor', 'lokasi']) as $t) {
            DB::table('proyek_tahap')->where('id', $t->id)->update(['lokasi' => trim($t->surveyor.' '.$t->lokasi)]);
        }
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->dropColumn('surveyor');
        });
    }
};
