<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Volume BOQ 4 desimal (BOQ Marina: cor beton 2,7692 m³ × Rp 6 jt — 3 desimal menggeser nilai Rp 1.200). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE boq_item MODIFY volume DECIMAL(14,4) NULL');
        DB::statement('ALTER TABLE boq_opname MODIFY volume DECIMAL(14,4) NOT NULL');
        DB::statement('ALTER TABLE tagihan_item MODIFY volume DECIMAL(14,4) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE boq_item MODIFY volume DECIMAL(14,3) NULL');
        DB::statement('ALTER TABLE boq_opname MODIFY volume DECIMAL(14,3) NOT NULL');
        DB::statement('ALTER TABLE tagihan_item MODIFY volume DECIMAL(14,3) NOT NULL');
    }
};
