<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // super_admin: semua menu + Pengguna + hubungkan Google Sheets; admin: input/hapus kas & laporan.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('admin')->after('email');
        });
        foreach (array_filter(array_map('trim', explode(',', strtolower((string) env('SUPER_ADMIN_EMAILS', ''))))) as $email) {
            DB::table('users')->where('email', $email)->update(['role' => 'super_admin']);
        }

        // Pengaturan aplikasi yang diisi dari layar (mis. API key Claude), nilai terenkripsi.
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->id();
            $table->string('kunci')->unique();
            $table->text('nilai')->nullable();
            $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
