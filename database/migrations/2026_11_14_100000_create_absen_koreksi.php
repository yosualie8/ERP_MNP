<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi jam absen oleh admin (owner 9 Nov 2026, untuk Valen): absen asli (tabel absensi, ditulis ulang pemilah Lapor Lapangan)
 * TIDAK diubah — koreksi disimpan terpisah per karyawan + tanggal sesi + jenis lalu ditimpakan saat dibaca, sehingga selalu jelas
 * jam mana yang asli dan mana yang diedit (dan oleh siapa). Hak 'absen-ubah-jam' diberikan ke jabatan Admin Mobil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absen_koreksi', function (Blueprint $t) {
            $t->id();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->date('tanggal');                 // tanggal sesi kerja (sama dengan absensi.tanggal)
            $t->string('jenis', 16);             // masuk | pulang | lembur_mulai | lembur_selesai
            $t->dateTime('waktu');               // jam hasil koreksi
            $t->string('catatan', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['karyawan_id', 'tanggal', 'jenis']);
            $t->index('tanggal');
        });

        $j = DB::table('jabatan_menu')->where('jabatan', 'Admin Mobil')->first();
        if ($j) {
            $menu = json_decode($j->menu ?? '[]', true) ?: [];
            if (! in_array('absen-ubah-jam', $menu, true)) {
                $menu[] = 'absen-ubah-jam';
                DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode($menu)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('absen_koreksi');
        $j = DB::table('jabatan_menu')->where('jabatan', 'Admin Mobil')->first();
        if ($j) {
            $menu = array_values(array_diff(json_decode($j->menu ?? '[]', true) ?: [], ['absen-ubah-jam']));
            DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode($menu)]);
        }
    }
};
