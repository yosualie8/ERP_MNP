<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi Jago (owner 28 Okt 2026): PDF "Pockets Transactions History" kantong Kas MNP & Uang Jalan DT diunggah (owner / Eddy / Valen,
 * 2× sehari) lalu dicocokkan dengan Kas Harian & Kas UJ. Satu baris bank disimpan sekali (kantong + ID# bank); PDF asli disimpan.
 * Menu "Mutasi Jago" diberikan ke jabatan Admin Mobil (Valen) & Pengurus Mobil (Eddy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jago_unggah', function (Blueprint $table) {
            $table->id();
            $table->string('kantong', 10);
            $table->string('nomor_kantong', 20);
            $table->string('pemilik', 100)->nullable();
            $table->date('dari')->nullable();
            $table->date('sampai')->nullable();
            $table->string('nama_file', 255)->nullable();
            $table->string('sha256', 64)->index();
            $table->unsignedInteger('jumlah_baris')->default(0);
            $table->unsignedInteger('baris_baru')->default(0);
            $table->text('peringatan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('jago_mutasi', function (Blueprint $table) {
            $table->id();
            $table->string('kantong', 10);
            $table->string('id_bank', 60);
            $table->dateTime('waktu');
            $table->string('nama', 150)->nullable();
            $table->string('bank', 40)->nullable();
            $table->string('rekening', 30)->nullable();
            $table->string('jenis', 60)->nullable();
            $table->string('arah', 6);
            $table->boolean('biaya')->default(false);
            $table->string('catatan', 500)->nullable();
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo', 15, 2);
            $table->foreignId('jago_unggah_id')->nullable()->constrained('jago_unggah')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kantong', 'id_bank']);
            $table->index(['kantong', 'waktu']);
        });

        foreach (DB::table('jabatan_menu')->whereIn('jabatan', ['Admin Mobil', 'Pengurus Mobil'])->get() as $j) {
            $menu = json_decode($j->menu ?? '[]', true) ?: [];
            if (! in_array('mutasi-jago', $menu, true)) {
                $menu[] = 'mutasi-jago';
                DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode(array_values($menu)), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jago_mutasi');
        Schema::dropIfExists('jago_unggah');
        foreach (DB::table('jabatan_menu')->get() as $j) {
            $menu = json_decode($j->menu ?? '[]', true) ?: [];
            if (in_array('mutasi-jago', $menu, true)) {
                DB::table('jabatan_menu')->where('id', $j->id)->update(['menu' => json_encode(array_values(array_diff($menu, ['mutasi-jago'])))]);
            }
        }
    }
};
