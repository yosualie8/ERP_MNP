<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nomor Pengajuan Sparepart jadi yyMMdd + urut 3 digit per tanggal (owner 17 Nov 2026), mis. 261117001. Nomor lama PSP-xxxxx
 * diberi nomor baru menurut tanggal pengajuannya (urut id di tanggal yang sama).
 */
return new class extends Migration
{
    public function up(): void
    {
        $urut = [];
        foreach (DB::table('pengajuan_sparepart')->where('nomor', 'like', 'PSP-%')->orderBy('tanggal')->orderBy('id')->get(['id', 'tanggal']) as $p) {
            $awal = Carbon::parse($p->tanggal)->format('ymd');
            $urut[$awal] ??= (int) DB::table('pengajuan_sparepart')->where('nomor', 'like', $awal.'%')->pluck('nomor')
                ->map(fn ($n) => (int) substr($n, 6))->max();
            $urut[$awal]++;
            DB::table('pengajuan_sparepart')->where('id', $p->id)->update(['nomor' => $awal.str_pad((string) $urut[$awal], 3, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('pengajuan_sparepart')->where('nomor', 'not like', 'PSP-%')->orderBy('id')->pluck('id') as $id) {
            DB::table('pengajuan_sparepart')->where('id', $id)->update(['nomor' => 'PSP-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT)]);
        }
    }
};
