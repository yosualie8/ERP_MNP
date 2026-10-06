<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Kode Bon kini bisa berisi link folder Google Drive foto bon.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kas_bon MODIFY kode_bon VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE kas_bon MODIFY kode_bon VARCHAR(100) NULL');
    }
};
