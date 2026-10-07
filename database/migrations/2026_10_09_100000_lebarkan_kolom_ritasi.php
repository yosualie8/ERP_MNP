<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Sebagian sel lembar Ritasi berisi catatan panjang (mis. di kolom No Do): kolom teks dilebarkan.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ritasi
            MODIFY no_seri VARCHAR(100) NULL, MODIFY jam VARCHAR(20) NULL, MODIFY plat VARCHAR(100) NULL, MODIFY no_lambung VARCHAR(100) NULL,
            MODIFY no_polisi VARCHAR(150) NULL, MODIFY galian VARCHAR(150) NULL, MODIFY jenis_buangan VARCHAR(100) NULL, MODIFY jenis_tanah VARCHAR(150) NULL,
            MODIFY jenis_kendaraan VARCHAR(100) NULL, MODIFY pemilik VARCHAR(150) NULL, MODIFY status_bayar VARCHAR(100) NULL, MODIFY no_do VARCHAR(150) NULL,
            MODIFY tahap VARCHAR(200) NULL');
        DB::statement('ALTER TABLE ritasi_temuan MODIFY no_seri VARCHAR(100) NULL, MODIFY no_do VARCHAR(150) NULL, MODIFY tahap VARCHAR(200) NULL');
    }

    public function down(): void
    {
    }
};
