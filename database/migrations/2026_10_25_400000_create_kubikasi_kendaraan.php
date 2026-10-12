<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Kubikasi standar per jenis kendaraan (nilai awal kolom Kubikasi di Ritasi) + kolom kubikasi di ritasi (kolom X sheet Ritasi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kubikasi_kendaraan', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 40)->unique();
            $table->decimal('kubikasi', 6, 2);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        // Nilai standar dari owner (12 Okt 2026).
        $awal = ['Faw' => 26, 'Giga' => 22, 'Mercy' => 20, 'Engkel' => 7, 'Hino' => 22, 'Colt diesel' => 7, 'Tronton' => 22, 'Tata 01' => 0, 'Water tank' => 0, 'Genzo orange' => 22];
        DB::table('kubikasi_kendaraan')->insert(array_map(fn ($j, $k) => ['jenis' => $j, 'kubikasi' => $k, 'created_at' => now(), 'updated_at' => now()], array_keys($awal), $awal));

        Schema::table('ritasi', function (Blueprint $table) {
            $table->decimal('kubikasi', 6, 2)->nullable()->after('harga_jual');
        });
    }

    public function down(): void
    {
        Schema::table('ritasi', function (Blueprint $table) {
            $table->dropColumn('kubikasi');
        });
        Schema::dropIfExists('kubikasi_kendaraan');
    }
};
