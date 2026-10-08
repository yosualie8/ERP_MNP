<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Status reimburse transaksi Kas Harian, sama dengan rumus kolom R Mutasi Reimburse: "Sudah" bila ID transaksinya
 * (mis. 261006-Jago-31250) ada di lembar "Sudah Reimburse" (kolom B; tanggal reimburse di kolom D), selain itu "Belum".
 * Lembar itu disalin ke tabel kas_sudah_reimburse tiap 10 menit.
 */
class StatusReimburse
{
    public const LEMBAR = 'Sudah Reimburse';

    /** Salin ulang lembar "Sudah Reimburse" ke database. @return int jumlah ID */
    public static function sinkron(GoogleSheets $sheets): int
    {
        $range = "'".self::LEMBAR."'!B2:D";
        $peta = [];
        foreach ($sheets->nilaiMentah(config('mnp.sheet_reimburse'), [$range])[$range] as $r) {
            $id = trim((string) ($r[0] ?? ''));
            if ($id !== '') {
                $peta[$id] = KasSeabank::tanggal($r[2] ?? null)?->toDateString() ?? ($peta[$id] ?? null);
            }
        }
        DB::transaction(function () use ($peta) {
            DB::table('kas_sudah_reimburse')->delete();
            foreach (array_chunk($peta, 1000, true) as $potong) {
                DB::table('kas_sudah_reimburse')->insert(array_map(fn ($id, $tgl) => ['id_transaksi' => mb_substr($id, 0, 40), 'tanggal_reimburse' => $tgl], array_keys($potong), $potong));
            }
        });
        Cache::forever('status-reimburse-pada', now()->toIso8601String());

        return count($peta);
    }

    /** ID transaksi yang dipakai di Mutasi Reimburse untuk satu detail. */
    public static function idBon(KasBon $b, KasTransfer $t): string
    {
        return trim((string) $b->id_transaksi) ?: $t->tanggal->format('ymd').'-Jago-'.($b->no_id ?: $t->no_id);
    }

    /**
     * @param  string[]  $ids
     * @return array<string, ?Carbon> ID yang sudah direimburse => tanggal reimburse
     */
    public static function untuk(array $ids): array
    {
        return DB::table('kas_sudah_reimburse')->whereIn('id_transaksi', array_unique($ids))->pluck('tanggal_reimburse', 'id_transaksi')
            ->map(fn ($t) => $t ? Carbon::parse($t) : null)->all();
    }

    public static function diperbaruiPada(): ?Carbon
    {
        return ($t = Cache::get('status-reimburse-pada')) ? Carbon::parse($t) : null;
    }
}
