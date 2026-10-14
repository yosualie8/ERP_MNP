<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Data Karyawan (dasar modul absensi) — data induk di aplikasi; impor awal dari sheet "Master" file Format Gaji MNP. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karyawan', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('no_master')->nullable()->unique();   // kolom "No" di sheet Master (jejak impor)
            $table->string('nama', 120);
            $table->string('nik', 20)->nullable()->index();
            $table->string('npwp', 30)->nullable();
            $table->string('status', 12)->default('aktif');             // aktif | nonaktif
            $table->string('site', 40)->nullable();
            $table->string('jabatan', 80)->nullable();
            $table->string('bank', 40)->nullable();
            $table->string('no_rekening', 40)->nullable();
            $table->string('nama_rekening', 120)->nullable();
            $table->string('pic_gaji', 120)->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karyawan');
    }
};
