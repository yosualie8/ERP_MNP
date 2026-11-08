<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catatan mutasi Jago bisa diubah (owner 9 Nov 2026, untuk Eddy): catatan asli dari PDF bank disimpan di catatan_bank saat pertama diubah. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jago_mutasi', function (Blueprint $t) {
            $t->string('catatan_bank', 500)->nullable()->after('catatan');
            $t->unsignedBigInteger('catatan_diubah_oleh')->nullable()->after('catatan_bank');
            $t->timestamp('catatan_diubah_pada')->nullable()->after('catatan_diubah_oleh');
        });
    }

    public function down(): void
    {
        Schema::table('jago_mutasi', fn (Blueprint $t) => $t->dropColumn(['catatan_bank', 'catatan_diubah_oleh', 'catatan_diubah_pada']));
    }
};
