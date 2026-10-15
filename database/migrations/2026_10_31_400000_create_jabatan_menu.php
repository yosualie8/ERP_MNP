<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses menu per JABATAN karyawan (keputusan owner 15 Okt 2026), menggantikan menu per akun (kecuali Super Admin).
 * Isi awal disalin dari menu akun yang ada: Admin Kantor = Mustika, Admin Mobil = Dearma, Pengurus Mobil = Eddy,
 * Direktur = Hans, Admin Plant = Nanda; semua jabatan lain hanya Absen. Absen ditambahkan ke semua jabatan.
 * Akun admin dihubungkan ke Data Karyawan lewat email (Nanda = Risky Yonanda Hafiz).
 */
return new class extends Migration
{
    private const SALIN = [
        'Admin Kantor' => 'mustikaanhar@gmail.com',
        'Admin Mobil' => 'dearmavalentino271@gmail.com',
        'Pengurus Mobil' => 'eddy71017@gmail.com',
        'Direktur' => 'hansanderson168@gmail.com',
        'Admin Plant' => 'yonanda65@gmail.com',
    ];

    private const HUBUNGKAN = [
        'Mustika Anhar' => 'mustikaanhar@gmail.com',
        'Hans Anderson' => 'hansanderson168@gmail.com',
        'Eddy Irawan' => 'eddy71017@gmail.com',
        'Risky Yonanda Hafiz' => 'yonanda65@gmail.com',
    ];

    public function up(): void
    {
        Schema::create('jabatan_menu', function (Blueprint $table) {
            $table->id();
            $table->string('jabatan', 100)->unique();
            $table->json('menu');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        $sekarang = now();
        $isi = [];
        foreach (self::SALIN as $jabatan => $email) {
            $menu = json_decode((string) DB::table('users')->where('email', $email)->value('menu'), true) ?: [];
            $isi[$jabatan] = array_values(array_unique([...$menu, 'absen']));
        }
        foreach (DB::table('karyawan')->whereNotNull('jabatan')->distinct()->pluck('jabatan') as $jabatan) {
            $isi[$jabatan] ??= ['absen'];
        }
        foreach ($isi as $jabatan => $menu) {
            DB::table('jabatan_menu')->insert(['jabatan' => $jabatan, 'menu' => json_encode($menu), 'created_at' => $sekarang, 'updated_at' => $sekarang]);
        }

        // Hubungkan akun admin ke karyawannya (hanya bila tepat satu karyawan aktif bernama itu & emailnya belum dipakai).
        foreach (self::HUBUNGKAN as $nama => $email) {
            $k = DB::table('karyawan')->where('nama', $nama)->where('status', 'aktif')->get();
            if ($k->count() === 1 && ! $k[0]->email && ! DB::table('karyawan')->where('email', $email)->exists()) {
                DB::table('karyawan')->where('id', $k[0]->id)->update(['email' => $email, 'updated_at' => $sekarang]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jabatan_menu');
    }
};
