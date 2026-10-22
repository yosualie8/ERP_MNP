<?php

namespace App\Support;

use App\Models\AsetTruk;
use App\Models\Ritasi;
use Illuminate\Support\Carbon;

/**
 * Rekap ritasi per truk (DT) per bulan & per tanggal untuk satu tahun — seperti pivot "Rekapan Ritasi per DT" (Count of No SJ).
 * Satu baris lembar Ritasi = satu rit. Hanya truk yang terdaftar di Data Aset (truk MNP). Saringan tujuan buangan memakai
 * catatan menu Buangan Truck (hanya dibaca).
 */
class PerformaRitasi
{
    /** @return int[] tahun yang punya data ritasi (terbaru dulu) */
    public static function daftarTahun(): array
    {
        return Ritasi::whereNotNull('tanggal')->selectRaw('YEAR(tanggal) t')->distinct()->orderByDesc('t')->pluck('t')->map(fn ($t) => (int) $t)->all();
    }

    /**
     * Tahap Daftar Proyek yang punya rit truk MNP di tahun itu (dari kolom Tahap Data Ritasi), terbaru dibongkar dulu.
     *
     * @return array<int, array{id: int, nama: string, status: ?string, rit: int, terakhir: string}>
     */
    public static function daftarTahap(int $tahun): array
    {
        $aset = AsetTruk::truk()->pluck('no_lambung')->flip();
        $per = [];
        foreach (Ritasi::whereYear('tanggal', $tahun)->whereNotNull('no_lambung')->selectRaw('tahap, no_lambung, count(*) n, max(tanggal) z')->groupBy('tahap', 'no_lambung')->get() as $r) {
            $dt = NomorMobil::rapikan($r->no_lambung);
            if (! $dt || ! isset($aset[$dt]) || ! ($id = DaftarProyek::tahapId('ritasi', $r->tahap))) {
                continue;
            }
            $per[$id]['rit'] = ($per[$id]['rit'] ?? 0) + (int) $r->n;
            $per[$id]['terakhir'] = max($per[$id]['terakhir'] ?? '', substr((string) $r->z, 0, 10));
        }
        $tahap = \App\Models\ProyekTahap::whereIn('id', array_keys($per))->get()->keyBy('id');

        return collect($per)->map(fn ($x, $id) => ['id' => (int) $id, 'nama' => $tahap[$id]->nama ?? "#{$id}", 'status' => $tahap[$id]->status ?? null, ...$x])
            ->sortByDesc('terakhir')->values()->all();
    }

    /**
     * @param  string[]|null  $hanyaTruk  batasi ke truk-truk ini (saringan tujuan buangan); null = semua truk MNP
     * @param  int|null  $tahapId  hanya rit yang kolom Tahap-nya di Data Ritasi = tahap Daftar Proyek ini (owner 22 Okt 2026)
     * @return array{truk: string[], bulan: array<int, array>, total: array<string, int>, jumlah: int, terakhir: ?Carbon}
     */
    public static function data(int $tahun, ?array $hanyaTruk = null, ?int $tahapId = null): array
    {
        $aset = AsetTruk::truk()->pluck('no_lambung')->flip();
        $hitung = []; // [Y-m-d][DT] => n
        $terakhir = null;
        foreach (Ritasi::whereYear('tanggal', $tahun)->whereNotNull('no_lambung')->get(['tanggal', 'no_lambung', 'tahap']) as $r) {
            $dt = NomorMobil::rapikan($r->no_lambung);
            if (! $dt || ! isset($aset[$dt]) || ($hanyaTruk !== null && ! in_array($dt, $hanyaTruk, true))
                || ($tahapId !== null && DaftarProyek::tahapId('ritasi', $r->tahap) !== $tahapId)) {
                continue;
            }
            $tgl = $r->tanggal->toDateString();
            $hitung[$tgl][$dt] = ($hitung[$tgl][$dt] ?? 0) + 1;
            $terakhir = ! $terakhir || $r->tanggal->gt($terakhir) ? $r->tanggal->copy() : $terakhir;
        }
        $truk = collect($hitung)->flatMap(fn ($x) => array_keys($x))->unique()->sort(SORT_NATURAL)->values()->all();

        $bulan = [];
        $total = array_fill_keys($truk, 0);
        foreach (range(1, 12) as $m) {
            $awal = Carbon::create($tahun, $m, 1);
            $akhirData = collect(array_keys($hitung))->filter(fn ($t) => str_starts_with($t, $awal->format('Y-m')))->max();
            if (! $akhirData) {
                continue;
            }
            // Bulan lalu: semua tanggal; bulan berjalan: sampai tanggal data terakhir.
            $sampai = $awal->isSameMonth(Carbon::today()) || $awal->copy()->endOfMonth()->isFuture() ? Carbon::parse($akhirData) : $awal->copy()->endOfMonth();
            $hari = [];
            $perTruk = array_fill_keys($truk, 0);
            for ($d = $awal->copy(); $d->lte($sampai); $d->addDay()) {
                $isi = $hitung[$d->toDateString()] ?? [];
                $hari[] = ['tanggal' => $d->copy(), 'truk' => $isi, 'total' => array_sum($isi)];
                foreach ($isi as $dt => $n) {
                    $perTruk[$dt] += $n;
                    $total[$dt] += $n;
                }
            }
            $bulan[$m] = ['awal' => $awal, 'truk' => $perTruk, 'total' => array_sum($perTruk), 'hari' => $hari];
        }

        return ['truk' => $truk, 'bulan' => $bulan, 'total' => $total, 'jumlah' => array_sum($total), 'terakhir' => $terakhir];
    }
}
