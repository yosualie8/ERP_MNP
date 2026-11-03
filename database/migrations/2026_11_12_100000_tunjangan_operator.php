<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gaji operator alat berat (owner 3 Nov 2026): selain UM harian ada basic 250rb, transport 125rb, uang kesehatan 75rb per minggu
 * + HM × tarif per jam kerja alat (file UM admin: 5.000 / HM). Komponen mingguan & tarif HM per jabatan (Master Jabatan);
 * HM per hari diisi admin di Rekap Absen & Gaji Mingguan (rekap_absen.hm). Hanya Operator Excavator & Operator Selfloader.
 * Admin boleh mengubah tunjangan mingguan satu orang untuk satu minggu (tunjangan_mingguan) — kebijakan minggu tidak penuh menyusul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jabatan_menu', function (Blueprint $table) {
            $table->unsignedInteger('basic_mingguan')->nullable()->after('um_harian');
            $table->unsignedInteger('transport_mingguan')->nullable()->after('basic_mingguan');
            $table->unsignedInteger('kesehatan_mingguan')->nullable()->after('transport_mingguan');
            $table->unsignedInteger('tarif_hm')->nullable()->after('kesehatan_mingguan');
        });
        Schema::table('rekap_absen', function (Blueprint $table) {
            $table->decimal('hm', 5, 1)->nullable()->after('lembur');
        });
        Schema::create('tunjangan_mingguan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $table->date('senin');
            // null = ikut tarif jabatan
            $table->unsignedInteger('basic_mingguan')->nullable();
            $table->unsignedInteger('transport_mingguan')->nullable();
            $table->unsignedInteger('kesehatan_mingguan')->nullable();
            $table->string('catatan', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['karyawan_id', 'senin']);
        });
        DB::table('jabatan_menu')->whereIn('jabatan', ['Operator Excavator', 'Operator Selfloader'])
            ->update(['basic_mingguan' => 250000, 'transport_mingguan' => 125000, 'kesehatan_mingguan' => 75000, 'tarif_hm' => 5000]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tunjangan_mingguan');
        Schema::table('jabatan_menu', function (Blueprint $table) {
            $table->dropColumn(['basic_mingguan', 'transport_mingguan', 'kesehatan_mingguan', 'tarif_hm']);
        });
        Schema::table('rekap_absen', function (Blueprint $table) {
            $table->dropColumn('hm');
        });
    }
};
