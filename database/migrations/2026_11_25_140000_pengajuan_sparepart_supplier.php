<?php

use App\Models\SparepartBeli;
use App\Support\DaftarSparepart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Sparepart: supplier per baris (owner 17 Nov 2026) = supplier pembelian yang menjadi dasar harga usulan (terakhir / termurah /
 * tipe unit; rata-rata = supplier terakhir). Kosong bila sparepart tidak ada di Database Sparepart. Baris lama diisi dari dasar harganya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_sparepart_detail', function (Blueprint $table) {
            $table->string('supplier', 150)->nullable()->after('merk');
        });

        foreach (DB::table('pengajuan_sparepart_detail')->whereNotNull('harga_saran')->whereNotNull('dasar_harga')->get() as $d) {
            $bs = DB::table('sparepart_beli')->where('nama_item', $d->nama_item)->whereNotIn('kategori', SparepartBeli::BUKAN_ITEM)
                ->orderBy('tanggal')->orderBy('id')->get()
                ->filter(fn ($b) => ($b->satuan ?? '') === ($d->satuan ?? '') && ($d->jenis === 'Umum' || DaftarSparepart::jenisGolongan($b->golongan) === $d->jenis))
                ->values();
            if ($bs->isEmpty()) {
                continue;
            }
            $cara = trim(explode('·', $d->dasar_harga, 2)[1] ?? '');
            $b = match (true) {
                $cara === 'termurah' => $bs->sortBy('harga_satuan')->first(),
                in_array($cara, ['', 'terakhir', 'rata-rata', 'semua pembelian'], true) => $bs->last(),
                default => $bs->where('tipe_unit', $cara)->last() ?? $bs->last(),
            };
            if ($b->supplier) {
                DB::table('pengajuan_sparepart_detail')->where('id', $d->id)->update(['supplier' => $b->supplier]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pengajuan_sparepart_detail', function (Blueprint $table) {
            $table->dropColumn('supplier');
        });
    }
};
