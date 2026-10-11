<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Menu Transfer Massal (Transfer Massal BCA & Input Transfer BCA) dihapus: buang juga datanya, catatan log-nya,
// dan pengaturan yang hanya dipakai menu itu (rekening debet BCA, token skrip). Database dicadangkan saat deploy.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('kas_riwayat')->whereIn('aksi', ['transfer-bca', 'bca-input', 'bca-skrip'])->delete();
        DB::table('pengaturan_app')->whereIn('kunci', ['bca_rekening_debet', 'bca_skrip_token_hash'])->delete();
        Schema::dropIfExists('input_bca');
        Schema::dropIfExists('transfer_bca');
    }

    public function down(): void
    {
        // Tidak dipulihkan (data dihapus atas permintaan pemilik).
    }
};
