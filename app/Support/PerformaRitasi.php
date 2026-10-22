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
     * @param  string[]|null  $hanyaTruk  batasi ke truk-truk ini (saringan tujuan buangan); null = semua truk MNP
     * @return array{truk: string[], bulan: array<int, array>, total: array<string, int>, jumlah: int, terakhir: ?Carbon}
     */
    public static function data(int $tahun, ?array $hanyaTruk = null): array
    {
        $aset = AsetTruk::truk()->pluck('no_lambung')->flip();
        $hitung = []; // [Y-m-d][DT] => n
        $terakhir = null;
        foreach (Ritasi::whereYear('tanggal', $tahun)->whereNotNull('no_lambung')->get(['tanggal', 'no_lambung']) as $r) {
            $dt = NomorMobil::rapikan($r->no_lambung);
            if (! $dt || ! isset($aset[$dt]) || ($hanyaTruk !== null && ! in_array($dt, $hanyaTruk, true))) {
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

    /**
     * Bongkaran per tahap Daftar Proyek (owner 22 Okt 2026): rit & m³ (kubikasi) truk MNP (Data Aset) per bulan dalam setahun,
     * atau per tanggal bila $bulan diisi. Ngepok bukan bongkaran (tidak dihitung). Tahap dicocokkan seperti Daftar Proyek
     * (DaftarProyek::tahapId sumber ritasi, termasuk nama lain); nama tahap yang belum terpetakan tampil apa adanya.
     *
     * @param  bool  $hanyaProgress  hanya tahap berstatus progress
     * @return array{kolom: array<string, string>, tahap: array<int, array>, total_kolom: array<string, array{rit: int, m3: float}>, jumlah: array{rit: int, m3: float}, bulan_ada: int[], terakhir: ?Carbon}
     */
    public static function perTahap(int $tahun, ?int $bulan = null, bool $hanyaProgress = false): array
    {
        $aset = AsetTruk::truk()->pluck('no_lambung')->flip();
        $tahapDb = \App\Models\ProyekTahap::with('proyek')->get()->keyBy('id');
        $baris = [];
        $bulanAda = [];
        $terakhir = null;
        $kunci = fn (Carbon $t) => $bulan ? $t->format('Y-m-d') : $t->format('Y-m');
        foreach (Ritasi::whereYear('tanggal', $tahun)->whereNotNull('no_lambung')->get(['tanggal', 'no_lambung', 'tahap', 'kubikasi', 'jenis_buangan']) as $r) {
            $dt = NomorMobil::rapikan($r->no_lambung);
            if (! $dt || ! isset($aset[$dt]) || strcasecmp((string) $r->jenis_buangan, 'Ngepok') === 0) {
                continue;
            }
            $id = DaftarProyek::tahapId('ritasi', $r->tahap);
            $t = $id ? ($tahapDb[$id] ?? null) : null;
            if ($hanyaProgress && $t?->status !== 'progress') {
                continue;
            }
            $bulanAda[(int) $r->tanggal->month] = true;
            if ($bulan && (int) $r->tanggal->month !== $bulan) {
                continue;
            }
            $k = $id ? "t{$id}" : 'x'.mb_strtolower(trim((string) $r->tahap));
            $b = &$baris[$k];
            $b ??= ['id' => $id, 'nama' => $t?->nama ?? (trim((string) $r->tahap) ?: '(tahap kosong)'), 'status' => $t?->status, 'proyek' => $t?->proyek?->nama,
                'urut' => [$t?->proyek?->urutan ?? 9999, (int) preg_replace('/\D/', '', (string) ($t?->nama ?? '')) ?: 99999, $t?->nama ?? (string) $r->tahap],
                'sel' => [], 'rit' => 0, 'm3' => 0.0, 'mulai' => null, 'selesai' => null];
            $s = &$b['sel'][$kunci($r->tanggal)];
            $s ??= ['rit' => 0, 'm3' => 0.0];
            $s['rit']++;
            $s['m3'] += (float) $r->kubikasi;
            $b['rit']++;
            $b['m3'] += (float) $r->kubikasi;
            $tgl = $r->tanggal->toDateString();
            $b['mulai'] = min($b['mulai'] ?? '9999', $tgl);
            $b['selesai'] = max($b['selesai'] ?? '0000', $tgl);
            unset($b, $s);
            $terakhir = ! $terakhir || $r->tanggal->gt($terakhir) ? $r->tanggal->copy() : $terakhir;
        }

        // Kolom: bulan yang ada datanya, atau tanggal 1..akhir bulan (bulan berjalan: sampai data terakhir).
        $kolom = [];
        if ($bulan) {
            $awal = Carbon::create($tahun, $bulan, 1);
            $sampai = $awal->isSameMonth(Carbon::today()) && $terakhir ? $terakhir->copy() : $awal->copy()->endOfMonth();
            for ($d = $awal->copy(); $d->lte($sampai); $d->addDay()) {
                $kolom[$d->format('Y-m-d')] = $d->format('j');
            }
        } else {
            foreach (array_keys($bulanAda) as $m) {
                $kolom[sprintf('%04d-%02d', $tahun, $m)] = Carbon::create($tahun, $m, 1)->translatedFormat('M');
            }
            ksort($kolom);
        }
        $tahap = collect($baris)->sortBy(fn ($b) => $b['urut'])->values()->all();
        $totalKolom = [];
        foreach (array_keys($kolom) as $k) {
            $totalKolom[$k] = ['rit' => (int) collect($tahap)->sum(fn ($b) => $b['sel'][$k]['rit'] ?? 0), 'm3' => (float) collect($tahap)->sum(fn ($b) => $b['sel'][$k]['m3'] ?? 0)];
        }
        $bulanAda = array_keys($bulanAda);
        sort($bulanAda);

        return ['kolom' => $kolom, 'tahap' => $tahap, 'total_kolom' => $totalKolom,
            'jumlah' => ['rit' => (int) collect($tahap)->sum('rit'), 'm3' => (float) collect($tahap)->sum('m3')], 'bulan_ada' => $bulanAda, 'terakhir' => $terakhir];
    }
}
