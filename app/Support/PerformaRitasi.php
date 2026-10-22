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
     * @param  int[]|null  $tahapIds  hanya rit yang kolom Tahap-nya di Data Ritasi = salah satu tahap Daftar Proyek ini (owner 22 Okt 2026)
     * @param  bool  $termasukMitra  truk selain MNP ikut dihitung (owner 22 Okt 2026: untuk proyek ASG) — dikelompokkan per PEMILIK
     *                               (kunci "~RUDI"), karena hampir semuanya tanpa nomor DT (hanya plat, ±120 plat)
     * @param  string[]|null  $pemilik  hanya pemilik ini ("MNP" = semua DT MNP; selain itu label pemilik non-MNP, mis. "RUDI"); null = semua
     * @return array{truk: string[], mitra: string[], label: array<string, string>, pemilik_ada: array<string, int>, pemilik_bulan: array<int, array<string, int>>, bulan: array<int, array>, total: array<string, int>, jumlah: int, terakhir: ?Carbon}
     */
    public static function data(int $tahun, ?array $hanyaTruk = null, ?array $tahapIds = null, bool $termasukMitra = false, ?array $pemilik = null): array
    {
        $aset = AsetTruk::truk()->pluck('no_lambung')->flip();
        $pilih = $tahapIds !== null ? array_flip($tahapIds) : null;
        $pilihPemilik = $pemilik !== null ? array_flip($pemilik) : null;
        $pemilikAda = []; // pemilik → jumlah rit (sebelum disaring pemilik), untuk pilihan centang
        $pemilikBulan = []; // bulan → pemilik → jumlah rit (pilihan centang mengikuti bulan yang dibuka)
        $hitung = []; // [Y-m-d][DT] => n
        $terakhir = null;
        $q = Ritasi::whereYear('tanggal', $tahun)->when(! $termasukMitra, fn ($q) => $q->whereNotNull('no_lambung'));
        foreach ($q->get(['tanggal', 'no_lambung', 'tahap', 'pemilik']) as $r) {
            if ($pilih !== null && ! isset($pilih[DaftarProyek::tahapId('ritasi', $r->tahap) ?? 0])) {
                continue;
            }
            $dt = NomorMobil::rapikan($r->no_lambung);
            if (! $dt || ! isset($aset[$dt])) {
                if (! $termasukMitra) {
                    continue;
                }
                // Truk selain MNP (atau truk MNP tanpa nomor DT terdaftar): satu kolom per pemilik.
                $p = trim(preg_replace('/\s+/', ' ', (string) $r->pemilik));
                $dt = '~'.(($p === '' ? '(tanpa pemilik)' : (strcasecmp($p, 'MNP') === 0 ? 'MNP tanpa DT' : $p)));
            } elseif ($hanyaTruk !== null && ! in_array($dt, $hanyaTruk, true)) {
                continue;
            }
            $grupPemilik = str_starts_with($dt, '~') ? substr($dt, 1) : 'MNP';
            $pemilikAda[$grupPemilik] = ($pemilikAda[$grupPemilik] ?? 0) + 1;
            $bln = (int) $r->tanggal->month;
            $pemilikBulan[$bln][$grupPemilik] = ($pemilikBulan[$bln][$grupPemilik] ?? 0) + 1;
            if ($pilihPemilik !== null && ! isset($pilihPemilik[$grupPemilik])) {
                continue;
            }
            $tgl = $r->tanggal->toDateString();
            $hitung[$tgl][$dt] = ($hitung[$tgl][$dt] ?? 0) + 1;
            $terakhir = ! $terakhir || $r->tanggal->gt($terakhir) ? $r->tanggal->copy() : $terakhir;
        }
        $semua = collect($hitung)->flatMap(fn ($x) => array_keys($x))->unique();
        // Kolom: DT MNP urut nomor, lalu kelompok pemilik mitra urut jumlah rit terbanyak.
        $ritKunci = collect($hitung)->flatMap(fn ($x) => collect($x)->map(fn ($n, $k) => [$k, $n])->values())->groupBy(0)->map(fn ($g) => $g->sum(1));
        $mitra = $semua->filter(fn ($k) => str_starts_with($k, '~'))->sortByDesc(fn ($k) => $ritKunci[$k] ?? 0)->values()->all();
        $truk = [...$semua->reject(fn ($k) => str_starts_with($k, '~'))->sort(SORT_NATURAL)->values()->all(), ...$mitra];
        $label = collect($truk)->mapWithKeys(fn ($k) => [$k => str_starts_with($k, '~') ? substr($k, 1) : str_replace('DT ', '', $k)])->all();

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

        // MNP dulu, lalu pemilik non-MNP urut jumlah rit terbanyak.
        $urutPemilik = function (array &$x) { uksort($x, fn ($a, $b) => [$a !== 'MNP', -$x[$a], $a] <=> [$b !== 'MNP', -$x[$b], $b]); };
        $urutPemilik($pemilikAda);
        foreach ($pemilikBulan as &$pb) {
            $urutPemilik($pb);
        }
        unset($pb);

        return ['truk' => $truk, 'mitra' => $mitra, 'label' => $label, 'pemilik_ada' => $pemilikAda, 'pemilik_bulan' => $pemilikBulan, 'bulan' => $bulan, 'total' => $total, 'jumlah' => array_sum($total), 'terakhir' => $terakhir];
    }
}
