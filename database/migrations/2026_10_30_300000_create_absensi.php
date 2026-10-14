<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul absensi: karyawan absen sendiri dari HP (login Google) — selfie + GPS, waktu dari server.
 * lokasi_absen = titik GPS per proyek (radius, mis. 100 m); absensi = satu catatan masuk/pulang/lembur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawan', function (Blueprint $table) {
            $table->string('email', 191)->nullable()->unique()->after('nama');   // akun Google untuk absen
        });

        Schema::create('lokasi_absen', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 120);
            $table->foreignId('proyek_id')->nullable()->constrained('proyek')->nullOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('radius_m')->default(100);
            $table->boolean('aktif')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('absensi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $table->date('tanggal')->index();                 // tanggal sesi kerja (tanggal absen Masuk-nya)
            $table->string('jenis', 16);                      // masuk | pulang | lembur_mulai | lembur_selesai
            $table->dateTime('waktu');                        // waktu SERVER saat absen diterima
            $table->foreignId('lokasi_absen_id')->nullable()->constrained('lokasi_absen')->nullOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('akurasi_m')->nullable();
            $table->unsignedInteger('jarak_m')->nullable();
            $table->string('foto', 200);                      // path di storage/app
            $table->string('ip', 45)->nullable();
            $table->string('perangkat', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['karyawan_id', 'waktu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi');
        Schema::dropIfExists('lokasi_absen');
        Schema::table('karyawan', fn (Blueprint $table) => $table->dropColumn('email'));
    }
};
