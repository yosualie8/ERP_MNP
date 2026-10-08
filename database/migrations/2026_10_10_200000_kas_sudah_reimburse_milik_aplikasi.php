<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sejak 8 Okt 2026 aplikasi = sumber kebenaran status reimburse: data awal diambil sekali dari lembar "Sudah Reimburse",
// perubahan berikutnya dibuat di aplikasi lalu ditulis ke sheet (di_sheet = sudah tertulis).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kas_sudah_reimburse', function (Blueprint $table) {
            $table->boolean('di_sheet')->default(true)->after('tanggal_reimburse');
            $table->string('sumber', 20)->default('sheet')->after('di_sheet'); // sheet (data awal) | aplikasi
            $table->foreignId('user_id')->nullable()->after('sumber')->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index('di_sheet');
        });
    }

    public function down(): void
    {
        Schema::table('kas_sudah_reimburse', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['di_sheet']);
            $table->dropColumn(['di_sheet', 'sumber', 'created_at']);
        });
    }
};
