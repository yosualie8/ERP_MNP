<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dua koneksi Google: "sheets" (akun pemilik sheet kas) dan "foto" (akun perusahaan, Drive foto bon).
        Schema::table('google_integrasi', function (Blueprint $table) {
            $table->string('keperluan', 20)->default('sheets')->after('id')->index();
        });

        // Foto penuh di Google Drive perusahaan; server hanya menyimpan pratinjau kecil + link.
        // path = file penuh sementara di server sampai berhasil diunggah ke Drive.
        Schema::table('kas_foto', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
            $table->string('path_kecil')->nullable()->after('path');
            $table->string('drive_file_id')->nullable()->after('path_kecil');
            $table->string('drive_link')->nullable()->after('drive_file_id');
            $table->string('status_drive', 20)->default('menunggu')->after('drive_link')->index();
            $table->unsignedTinyInteger('percobaan')->default(0)->after('status_drive');
            $table->string('pesan_drive', 500)->nullable()->after('percobaan');
        });
    }

    public function down(): void
    {
        Schema::table('kas_foto', function (Blueprint $table) {
            $table->dropColumn(['path_kecil', 'drive_file_id', 'drive_link', 'status_drive', 'percobaan', 'pesan_drive']);
        });
        Schema::table('google_integrasi', function (Blueprint $table) {
            $table->dropColumn('keperluan');
        });
    }
};
