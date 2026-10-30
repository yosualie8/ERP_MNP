<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Performa DT / laba rugi per dump truck (owner 30 Okt 2026): pendapatan = jumlah kolom Harga Jual lembar Ritasi (per rit, No Lambung),
 * dikurangi biaya langsung truk dari Monitoring Biaya DT (Kas UJ + Kas Harian ber-No Mobil, lihat BiayaDt). Hanya truk di Data Aset.
 * Biaya bersama yang tidak ber-No Mobil (gaji, solar tangki, sparepart tanpa No Mobil, sewa, overhead) belum dibagi ke truk.
 * Rit tanpa Harga Jual tidak ditebak: dihitung terpisah, dengan perkiraan dari harga yang paling sering di tahap yang sama.
 */
class LabaRugiDt
{
    /**
     * Pendapatan rit per truk per bulan.
     *
     * @return array<string, array<string, array{rit: int, rp: int, kubik: float, tanpa_harga: int, perkiraan: int}>> [mobil][Y-m]
     */
    public static function pendapatan(Carbon $awal, Carbon $akhir): array
    {
        $aset = BiayaDt::aset();
        $rentang = [$awal->toDateString(), $akhir->toDateString()];
        $hasil = [];
        foreach (DB::table('ritasi')->whereBetween('tanggal', $rentang)->whereNotNull('no_lambung')
            ->selectRaw("no_lambung, DATE_FORMAT(tanggal, '%Y-%m') bln, COUNT(*) n, SUM(COALESCE(harga_jual, 0)) rp, SUM(COALESCE(kubikasi, 0)) kubik, SUM(COALESCE(harga_jual, 0) = 0) kosong")
            ->groupBy('no_lambung', 'bln')->get() as $r) {
            $m = NomorMobil::rapikan($r->no_lambung);
            if (! $m || ! isset($aset[$m])) {
                continue;
            }
            $x = $hasil[$m][$r->bln] ?? ['rit' => 0, 'rp' => 0, 'kubik' => 0.0, 'tanpa_harga' => 0, 'perkiraan' => 0];
            $hasil[$m][$r->bln] = ['rit' => $x['rit'] + (int) $r->n, 'rp' => $x['rp'] + (int) $r->rp, 'kubik' => $x['kubik'] + (float) $r->kubik,
                'tanpa_harga' => $x['tanpa_harga'] + (int) $r->kosong, 'perkiraan' => $x['perkiraan']];
        }

        // Perkiraan rit tanpa harga: harga terbanyak di tahap itu (rit truk MNP, 3 bulan sekitar periode).
        $kosong = DB::table('ritasi')->whereBetween('tanggal', $rentang)->whereNotNull('no_lambung')->where(fn ($q) => $q->whereNull('harga_jual')->orWhere('harga_jual', 0))
            ->selectRaw("no_lambung, tahap, DATE_FORMAT(tanggal, '%Y-%m') bln, COUNT(*) n")->groupBy('no_lambung', 'tahap', 'bln')->get();
        if ($kosong->isNotEmpty()) {
            $harga = DB::table('ritasi')->whereIn('tahap', $kosong->pluck('tahap')->unique()->all())->where('harga_jual', '>', 0)
                ->whereBetween('tanggal', [$awal->copy()->subMonths(3)->toDateString(), $akhir->copy()->addMonths(3)->toDateString()])
                ->selectRaw('tahap, harga_jual, COUNT(*) n')->groupBy('tahap', 'harga_jual')->get()
                ->groupBy('tahap')->map(fn ($g) => (int) $g->sortByDesc('n')->first()->harga_jual);
            foreach ($kosong as $r) {
                $m = NomorMobil::rapikan($r->no_lambung);
                if ($m && isset($hasil[$m][$r->bln])) {
                    $hasil[$m][$r->bln]['perkiraan'] += (int) $r->n * ($harga[$r->tahap] ?? 0);
                }
            }
        }

        return $hasil;
    }

    /**
     * Gabungan pendapatan & biaya per truk.
     *
     * @return Collection<string, array{mobil: string, aset: ?object, rit: int, kubik: float, pendapatan: int, tanpa_harga: int, perkiraan: int,
     *     biaya: int, kelompok: array<string, int>, laba: int, bulan: array<string, array{rit: int, pendapatan: int, biaya: int, laba: int}>}>
     */
    public static function perTruk(Carbon $awal, Carbon $akhir): Collection
    {
        $aset = BiayaDt::aset();
        $masuk = self::pendapatan($awal, $akhir);
        $biaya = BiayaDt::baris($awal, $akhir)->groupBy('mobil');
        $kosong = ['rit' => 0, 'pendapatan' => 0, 'biaya' => 0, 'laba' => 0];

        return collect(array_unique([...array_keys($masuk), ...$biaya->keys()->all()]))->mapWithKeys(function ($m) use ($aset, $masuk, $biaya, $kosong) {
            $b = $biaya[$m] ?? collect();
            $bulan = [];
            foreach ($masuk[$m] ?? [] as $bln => $x) {
                $bulan[$bln] = ['rit' => $x['rit'], 'pendapatan' => $x['rp']] + $kosong;
            }
            foreach ($b->groupBy('bulan') as $bln => $g) {
                $bulan[$bln] = ($bulan[$bln] ?? $kosong);
                $bulan[$bln]['biaya'] = (int) $g->sum('rp');
            }
            foreach ($bulan as $bln => $x) {
                $bulan[$bln]['laba'] = $x['pendapatan'] - $x['biaya'];
            }
            $p = collect($masuk[$m] ?? []);
            $pendapatan = (int) $p->sum('rp');
            $total = (int) $b->sum('rp');

            return [$m => [
                'mobil' => $m, 'aset' => $aset[$m] ?? null, 'rit' => (int) $p->sum('rit'), 'kubik' => (float) $p->sum('kubik'),
                'pendapatan' => $pendapatan, 'tanpa_harga' => (int) $p->sum('tanpa_harga'), 'perkiraan' => (int) $p->sum('perkiraan'),
                'biaya' => $total, 'kelompok' => $b->groupBy(fn ($r) => BiayaDt::kelompok($r['kategori']))->map(fn ($g) => (int) $g->sum('rp'))->all(),
                'kategori' => $b->groupBy('kategori')->map(fn ($g) => (int) $g->sum('rp'))->sortDesc()->all(),
                'laba' => $pendapatan - $total, 'bulan' => $bulan,
            ]];
        });
    }

    /** Bulan yang punya data rit atau biaya DT (Y-m). */
    public static function bulanAda(): Collection
    {
        $rit = DB::table('ritasi')->whereNotNull('tanggal')->whereNotNull('no_lambung')->selectRaw("DISTINCT DATE_FORMAT(tanggal, '%Y-%m') b")->pluck('b');

        return BiayaDt::bulanAda()->concat($rit)->unique()->sort()->values();
    }
}
