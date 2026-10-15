<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anggaran (rencana) HPP & omset per tahap, tersusun dari baris komponen (keputusan owner 15 Okt 2026: per tahap,
 * progres fisik dari kubikasi Ritasi, HPP termasuk overhead). Master komponen punya "sumber realisasi" untuk dicocokkan
 * dengan data aktual & "tipe bawaan" sebagai template per tipe tahap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('komponen_anggaran', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 40)->nullable()->unique(); // komponen sistem (dipakai template), mis. komisi_lapangan
            $table->string('jenis', 10);                       // hpp | omset
            $table->string('nama', 100);
            $table->string('satuan', 20)->nullable();
            $table->string('sumber', 30)->default('manual');  // sumber realisasi (App\Support\Anggaran::SUMBER)
            $table->string('cara', 20)->default('volume');    // volume (vol × harga) | persen (% dari total omset)
            $table->decimal('nilai_bawaan', 16, 3)->nullable(); // harga satuan / persen bawaan
            $table->json('tipe_bawaan')->nullable();          // tipe tahap yang otomatis memakai komponen ini (template)
            $table->unsignedInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['jenis', 'nama']);
        });
        Schema::create('anggaran_tahap', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyek_tahap_id')->constrained('proyek_tahap')->cascadeOnDelete();
            $table->foreignId('komponen_id')->constrained('komponen_anggaran')->restrictOnDelete();
            $table->string('uraian', 150)->nullable();
            $table->decimal('volume', 16, 3)->nullable();
            $table->decimal('harga', 16, 2)->nullable();
            $table->decimal('persen', 7, 3)->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->json('anggaran_baseline')->nullable();
            $table->timestamp('anggaran_dikunci_at')->nullable();
            $table->foreignId('anggaran_dikunci_oleh')->nullable()->constrained('users')->nullOnDelete();
        });

        $t = now();
        $isi = [
            // [kode, jenis, nama, satuan, sumber, cara, nilai bawaan, tipe bawaan]
            ['urugan_m3', 'omset', 'Urugan / timbunan', 'm³', 'kubikasi', 'volume', 139000, ['luas']],
            ['jual_rit', 'omset', 'Penjualan tanah per rit', 'rit', 'rit', 'volume', null, ['ritasi']],
            [null, 'omset', 'Pekerjaan lain (lumpsum)', 'ls', 'manual', 'volume', null, []],
            ['tanah_rit', 'hpp', 'Tanah urug & angkutan (ritasi)', 'rit', 'rit', 'volume', null, ['luas', 'ritasi']],
            ['ngepok', 'hpp', 'Ngepok', 'rit', 'ngepok', 'volume', null, ['luas']],
            ['solar', 'hpp', 'Solar alat', 'liter', 'solar', 'volume', 12000, ['luas', 'ritasi']],
            ['rental', 'hpp', 'Rental alat berat', 'jam', 'rental', 'volume', 160000, ['luas']],
            ['gaji', 'hpp', 'Gaji & UM tim lapangan', 'bulan', 'kas_gaji', 'volume', null, ['luas']],
            ['operasional', 'hpp', 'Operasional lapangan', 'ls', 'kas_operasional', 'volume', null, ['luas', 'ritasi']],
            ['komisi_lapangan', 'hpp', 'Komisi lapangan', '%', 'komisi', 'persen', 3.5, ['luas']],
            ['komisi_yosua', 'hpp', 'Komisi Yosua', '%', 'komisi', 'persen', 2, ['luas']],
            ['komisi_verlix', 'hpp', 'Komisi Verlix', '%', 'komisi', 'persen', 2, ['luas']],
            ['overhead', 'hpp', 'Overhead (biaya umum kantor & pool)', '%', 'overhead', 'persen', null, ['luas', 'ritasi']],
            [null, 'hpp', 'Material / pekerjaan lain', 'ls', 'manual', 'volume', null, []],
        ];
        foreach ($isi as $i => [$kode, $jenis, $nama, $satuan, $sumber, $cara, $nilai, $tipe]) {
            DB::table('komponen_anggaran')->insert(['kode' => $kode, 'jenis' => $jenis, 'nama' => $nama, 'satuan' => $satuan, 'sumber' => $sumber, 'cara' => $cara,
                'nilai_bawaan' => $nilai, 'tipe_bawaan' => json_encode($tipe), 'urutan' => ($i + 1) * 10, 'aktif' => true, 'created_at' => $t, 'updated_at' => $t]);
        }
    }

    public function down(): void
    {
        Schema::table('proyek_tahap', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anggaran_dikunci_oleh');
            $table->dropColumn(['anggaran_baseline', 'anggaran_dikunci_at']);
        });
        Schema::dropIfExists('anggaran_tahap');
        Schema::dropIfExists('komponen_anggaran');
    }
};
