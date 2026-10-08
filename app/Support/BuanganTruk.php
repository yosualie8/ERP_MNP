<?php

namespace App\Support;

use App\Models\AsetTruk;
use App\Models\Ritasi;
use App\Models\TrukBuangan;
use App\Models\UjDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tujuan buangan truk (= tahap/proyek tempat bongkar di data Ritasi). Bawaannya tahap rit terakhir truk itu; admin pengurus
 * truk bisa menimpanya (tabel truk_buangan) dan pengaturan itu berlaku sampai diubah lagi.
 */
class BuanganTruk
{
    public const HARI = 30;

    /** Tahap rit terakhir per truk (seluruh histori). @return array<string, array{tahap: string, tanggal: ?Carbon, galian: ?string}> */
    public static function ritTerakhir(): array
    {
        $hasil = [];
        foreach (Ritasi::whereNotNull('no_lambung')->where('no_lambung', '!=', '')->whereNotNull('tahap')->where('tahap', '!=', '')
            ->orderBy('tanggal')->orderBy('baris')->get(['no_lambung', 'tahap', 'tanggal', 'galian']) as $r) {
            if ($dt = NomorMobil::rapikan($r->no_lambung)) {
                $hasil[$dt] = ['tahap' => trim($r->tahap), 'tanggal' => $r->tanggal, 'galian' => $r->galian];
            }
        }

        return $hasil;
    }

    /** Tujuan buangan berlaku per truk. @return array<string, array{tujuan: string, sumber: string}> sumber: admin | ritasi */
    public static function tujuan(): array
    {
        $hasil = array_map(fn ($r) => ['tujuan' => $r['tahap'], 'sumber' => 'ritasi'], self::ritTerakhir());
        foreach (TrukBuangan::pluck('tujuan', 'no_lambung') as $dt => $t) {
            $hasil[$dt] = ['tujuan' => $t, 'sumber' => 'admin'];
        }

        return $hasil;
    }

    /** Truk DT yang punya rit atau transaksi Kas UJ dalam 30 hari terakhir, beserta tujuan buangannya. */
    public static function daftar(): Collection
    {
        $sejak = Carbon::today()->subDays(self::HARI);
        $rit = Ritasi::where('tanggal', '>=', $sejak)->whereNotNull('no_lambung')->orderBy('tanggal')->orderBy('baris')
            ->get(['no_lambung', 'no_polisi', 'tanggal', 'tahap', 'galian']);
        $uj = UjDetail::where('tanggal', '>=', $sejak)->where('biaya_transfer', false)->whereNotNull('no_mobil')->orderBy('tanggal')->orderBy('baris')
            ->get(['no_mobil', 'nama', 'tanggal']);
        $truk = [];
        $truk2 = function (?string $no) use (&$truk) {
            $dt = NomorMobil::rapikan($no);
            if (! $dt || ! preg_match('/^DT \d{3}$/', $dt)) {
                return null;
            }
            $truk[$dt] ??= ['no_lambung' => $dt, 'rit' => 0, 'uj' => 0, 'rit_terakhir' => null, 'uj_terakhir' => null, 'driver' => null];

            return $dt;
        };
        foreach ($rit as $r) {
            if ($dt = $truk2($r->no_lambung)) {
                $truk[$dt]['rit']++;
                $truk[$dt]['rit_terakhir'] = $r->tanggal;
                $truk[$dt]['driver'] = $r->driver ?? $truk[$dt]['driver'];
            }
        }
        foreach ($uj as $u) {
            if ($dt = $truk2($u->no_mobil)) {
                $truk[$dt]['uj']++;
                $truk[$dt]['uj_terakhir'] = $u->tanggal;
                $truk[$dt]['driver'] = $u->nama ?: $truk[$dt]['driver'];
            }
        }
        $terakhir = self::ritTerakhir();
        $atur = TrukBuangan::with('user')->get()->keyBy('no_lambung');
        $aset = AsetTruk::get(['no_lambung', 'jenis', 'status'])->keyBy('no_lambung');

        return collect($truk)->map(function ($t, $dt) use ($terakhir, $atur, $aset) {
            $a = $atur[$dt] ?? null;
            $r = $terakhir[$dt] ?? null;

            return [...$t,
                'jenis' => $aset[$dt]->jenis ?? null, 'di_aset' => isset($aset[$dt]), 'status_aset' => $aset[$dt]->status ?? null,
                'tahap_rit' => $r['tahap'] ?? null, 'tanggal_tahap' => $r['tanggal'] ?? null, 'galian' => $r['galian'] ?? null,
                'tujuan' => $a?->tujuan ?? $r['tahap'] ?? null, 'sumber' => $a ? 'admin' : ($r ? 'ritasi' : null),
                'diatur' => $a ? ($a->user?->name ?? $a->user?->email).' · '.$a->updated_at->translatedFormat('j M H:i') : null,
            ];
        })->sortBy('no_lambung')->values();
    }

    /** Daftar nama tujuan yang dikenal (untuk saran isian): tahap 90 hari terakhir + yang pernah diatur admin. */
    public static function saran(): array
    {
        return Ritasi::where('tanggal', '>=', Carbon::today()->subDays(90))->whereNotNull('tahap')->where('tahap', '!=', '')
            ->distinct()->pluck('tahap')->map(fn ($t) => trim($t))
            ->merge(TrukBuangan::distinct()->pluck('tujuan'))->unique()->sort()->values()->all();
    }
}
