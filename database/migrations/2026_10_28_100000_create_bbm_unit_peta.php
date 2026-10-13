<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan tulisan kolom UNIT di lembar Penggunaan BBM → arti barisnya: pemakaian oleh aset (aset_id), solar masuk (sumber),
 * solar pindah lokasi, solar rusak/susut, atau belum jelas (cek). Disusun dari "Draf Data Aset" (dijawab owner 13 Okt 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bbm_unit_peta', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 120);                  // tulisan asli di sheet
            $table->string('kunci', 120)->unique();       // huruf kecil, spasi dirapikan
            $table->string('jenis', 12);                  // pakai | masuk | pindah | susut | cek
            $table->foreignId('aset_id')->nullable()->constrained('aset_truk')->nullOnDelete();
            $table->string('sumber', 120)->nullable();    // solar masuk: "Solar dari DT 043", "Solar dari luar", …
            $table->string('catatan', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bbm_unit_peta');
    }
};
