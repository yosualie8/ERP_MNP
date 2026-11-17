<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Sparepart (owner 17 Nov 2026): menandai status barang & foto progres pekerjaan hanya lewat hak baru
 * 'pengajuan-sparepart-tandai' — sementara hanya jabatan Admin Plant (Nanda menandai sendiri, statusnya seperti catatan).
 * Tabel foto progres per pengajuan: foto penuh (sisi terpanjang 2000 px) + pratinjau kecil di server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_sparepart_foto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengajuan_sparepart_id')->constrained('pengajuan_sparepart')->cascadeOnDelete();
            $table->string('keterangan', 300)->nullable();
            $table->string('path', 255);
            $table->string('path_kecil', 255)->nullable();
            $table->unsignedInteger('ukuran')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $j = DB::table('jabatan_menu')->where('jabatan', 'Admin Plant')->first();
        if ($j) {
            $menu = json_decode($j->menu ?? '[]', true) ?: [];
            if (! in_array('pengajuan-sparepart-tandai', $menu, true)) {
                $menu[] = 'pengajuan-sparepart-tandai';
                DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode(array_values($menu)), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_sparepart_foto');
        $j = DB::table('jabatan_menu')->where('jabatan', 'Admin Plant')->first();
        if ($j) {
            $menu = array_values(array_diff(json_decode($j->menu ?? '[]', true) ?: [], ['pengajuan-sparepart-tandai']));
            DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode($menu)]);
        }
    }
};
