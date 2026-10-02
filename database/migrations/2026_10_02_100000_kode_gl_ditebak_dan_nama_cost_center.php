<?php

use App\Support\UraiKodeGl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kode GL kosong di sheet yang ditebak aplikasi dari keterangan (talangan, salah transfer, biaya transfer).
        Schema::table('kas_bon', function (Blueprint $table) {
            $table->boolean('kode_gl_ditebak')->default(false)->after('kode_gl_id');
        });

        foreach (UraiKodeGl::NAMA_COST_CENTER as $kode => $nama) {
            DB::table('cost_center')->where('kode', $kode)->whereNull('nama')->update(['nama' => $nama]);
        }
    }

    public function down(): void
    {
        Schema::table('kas_bon', function (Blueprint $table) {
            $table->dropColumn('kode_gl_ditebak');
        });
    }
};
