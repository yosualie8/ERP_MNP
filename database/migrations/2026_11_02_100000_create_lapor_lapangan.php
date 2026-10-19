<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lapor Lapangan (owner 19 Okt 2026): satu wadah seperti grup WhatsApp absensi — karyawan melempar kiriman berisi banyak foto
 * (kamera depan/belakang atau galeri) + keterangan; waktu dari server, GPS & proyek terdekat dicatat. Sistem yang memilah kiriman
 * menjadi absen masuk / pulang / lembur / laporan (progres) — hasilnya ditulis ke tabel absensi (otomatis = true).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lapor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $table->dateTime('waktu');
            $table->date('tanggal'); // tanggal kerja (kiriman lewat tengah malam bisa ikut hari sebelumnya)
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('akurasi_m')->nullable();
            $table->foreignId('lokasi_absen_id')->nullable()->constrained('lokasi_absen')->nullOnDelete();
            $table->foreignId('proyek_tahap_id')->nullable()->constrained('proyek_tahap')->nullOnDelete();
            $table->unsignedInteger('jarak_m')->nullable();
            $table->boolean('di_lokasi')->default(false);
            $table->text('keterangan')->nullable();
            $table->string('jenis', 16)->default('laporan'); // hasil pilah sistem: masuk | pulang | lembur | laporan
            $table->string('jenis_admin', 16)->nullable();   // koreksi admin: masuk | pulang | lembur | laporan | abaikan
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_item')->nullOnDelete();
            $table->string('catatan_admin', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('perangkat', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['karyawan_id', 'tanggal']);
            $table->index(['tanggal', 'proyek_tahap_id']);
        });
        Schema::create('lapor_foto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lapor_id')->constrained('lapor')->cascadeOnDelete();
            $table->unsignedSmallInteger('urutan')->default(1);
            $table->string('sumber', 10)->default('kamera'); // kamera | galeri
            $table->string('kamera', 10)->nullable();        // depan | belakang
            $table->string('path', 200)->nullable();
            $table->string('path_kecil', 200)->nullable();
            $table->unsignedInteger('ukuran')->nullable();
            $table->string('drive_file_id', 100)->nullable();
            $table->string('drive_link', 300)->nullable();
            $table->string('status_drive', 12)->default('menunggu')->index();
            $table->unsignedTinyInteger('percobaan')->default(0);
            $table->string('pesan_drive', 500)->nullable();
            $table->timestamps();
        });
        Schema::table('absensi', function (Blueprint $table) {
            $table->foreignId('lapor_id')->nullable()->after('foto')->constrained('lapor')->nullOnDelete();
            $table->boolean('otomatis')->default(false)->after('lapor_id'); // ditulis pemilah Lapor Lapangan
        });
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->time('jam_masuk')->nullable()->after('batas_bongkar');
            $table->time('jam_pulang')->nullable()->after('jam_masuk');
        });
    }

    public function down(): void
    {
        Schema::table('proyek_tahap', fn (Blueprint $table) => $table->dropColumn(['jam_masuk', 'jam_pulang']));
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lapor_id');
            $table->dropColumn('otomatis');
        });
        Schema::dropIfExists('lapor_foto');
        Schema::dropIfExists('lapor');
    }
};
