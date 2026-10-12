<?php

namespace App\Support;

use App\Http\Controllers\RitasiController;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use OpenSpout\Reader\CSV\Reader as ReaderCsv;
use OpenSpout\Reader\XLSX\Reader as ReaderXlsx;
use RuntimeException;

/**
 * Upload Excel surat jalan di Monitor Ritasi: tiap baris = satu surat jalan (No Mobil/DT, tanggal & jam bongkar, tujuan,
 * jenis tanah, No Surat Jalan). Surat jalan dipasangkan ke DO belum bongkar milik DT yang sama; jumlah surat jalan suatu DT
 * tidak boleh melebihi jumlah DO belum bongkarnya. Hasilnya hanya mengisi form — admin tetap meninjau lalu Simpan bongkar.
 */
class UnggahBongkar
{
    /** Judul kolom yang dikenali (huruf kecil, tanpa spasi berlebih). */
    public const KOLOM = [
        'dt' => ['no mobil', 'nomor mobil', 'no dt', 'dt', 'no lambung', 'mobil', 'nomor dt'],
        'tanggal' => ['tanggal bongkar', 'tgl bongkar', 'tanggal', 'tgl'],
        'jam' => ['jam bongkar', 'jam'],
        'tahap' => ['tujuan bongkar', 'tujuan', 'tahap', 'proyek'],
        'jenis_tanah' => ['jenis tanah / barang', 'jenis tanah/barang', 'jenis tanah', 'jenis barang', 'tanah'],
        'no_seri' => ['no surat jalan', 'nomor surat jalan', 'no sj', 'surat jalan', 'no seri'],
        'galian' => ['galian'],
        'keterangan' => ['keterangan', 'catatan'],
    ];

    /** DO yang UJ-nya lebih dari sekian hari sebelum bongkar dianggap tertinggal lama (dipakai terakhir, ditandai). */
    public const HARI_LAMA = 30;

    public const JUDUL_TEMPLAT = ['No Mobil', 'Tanggal Bongkar', 'Jam Bongkar', 'Tujuan Bongkar', 'Jenis Tanah', 'No Surat Jalan', 'Galian', 'Keterangan'];

    /** @return array<int, array> baris surat jalan (baris = nomor baris Excel) */
    public static function baca(UploadedFile $file): array
    {
        $reader = strtolower($file->getClientOriginalExtension()) === 'csv' ? new ReaderCsv : new ReaderXlsx;
        $reader->open($file->getRealPath());
        $semua = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $semua[] = array_map(fn ($c) => $c->getValue(), $row->getCells());
            }
            break; // lembar pertama saja
        }
        $reader->close();

        $judul = null;
        $posisi = [];
        foreach (array_slice($semua, 0, 10, true) as $i => $r) {
            $teks = array_map(fn ($v) => is_string($v) ? mb_strtolower(trim(preg_replace('/\s+/', ' ', $v))) : '', $r);
            $p = [];
            foreach (self::KOLOM as $kunci => $nama) {
                foreach ($nama as $n) {
                    if (($k = array_search($n, $teks, true)) !== false && ! in_array($k, $p, true)) {
                        $p[$kunci] = $k;
                        break;
                    }
                }
            }
            if (isset($p['dt'], $p['no_seri'])) {
                [$judul, $posisi] = [$i, $p];
                break;
            }
        }
        if ($judul === null) {
            throw new RuntimeException('Baris judul tidak ditemukan. Minimal harus ada kolom "No Mobil" dan "No Surat Jalan" (unduh templat Excel).');
        }
        $kurang = array_diff(['tanggal', 'tahap', 'jenis_tanah'], array_keys($posisi));
        if ($kurang) {
            throw new RuntimeException('Kolom belum ada: '.implode(', ', array_map(fn ($k) => self::JUDUL_TEMPLAT[array_search($k, array_keys(self::KOLOM))], $kurang)).'.');
        }

        $hasil = [];
        foreach (array_slice($semua, $judul + 1, null, true) as $i => $r) {
            $sel = fn (string $k) => isset($posisi[$k]) ? ($r[$posisi[$k]] ?? null) : null;
            $teks = fn (string $k) => ($v = trim(preg_replace('/\s+/', ' ', (string) (is_scalar($sel($k)) ? $sel($k) : '')))) === '' ? null : $v;
            if (! $teks('dt') && ! $teks('no_seri') && ! $sel('tanggal')) {
                continue;
            }
            $hasil[] = [
                'baris' => $i + 1,
                'dt' => NomorMobil::rapikan($teks('dt')),
                'tanggal' => self::tanggal($sel('tanggal')),
                'tanggal_asli' => is_scalar($sel('tanggal')) ? (string) $sel('tanggal') : ($sel('tanggal') instanceof \DateTimeInterface ? $sel('tanggal')->format('d/m/Y') : ''),
                'jam' => self::jam($sel('jam')),
                'tahap' => $teks('tahap'),
                'jenis_tanah' => $teks('jenis_tanah'),
                'no_seri' => is_float($sel('no_seri')) ? (string) (int) $sel('no_seri') : $teks('no_seri'),
                'galian' => $teks('galian'),
                'keterangan' => $teks('keterangan'),
            ];
        }

        return $hasil;
    }

    /**
     * Pasangkan surat jalan ke DO belum bongkar per DT. Per DT: bila surat jalan > DO belum bongkar → DT itu ditolak.
     * Bila DO lebih banyak, dipakai urut antrean: DO paling lama dulu (FIFO), hanya DO yang UJ-nya tidak sesudah bongkar terakhir
     * dan tidak lebih dari HARI_LAMA (30) hari sebelum bongkar pertama; DO yang lebih tua baru dipakai bila kurang (ditandai).
     * Lalu dipasangkan urut waktu: surat jalan paling awal ↔ DO paling awal.
     *
     * @param  Collection<int, array>  $belum  DO belum bongkar (MonitorRitasi::belumBongkar)
     * @return array{isian: array<string, array>, per_dt: array<int, array>, galat: string[]}
     */
    public static function cocokkan(array $sj, Collection $belum): array
    {
        $isian = [];
        $perDt = [];
        $galat = [];
        foreach ($sj as $s) {
            $salah = array_filter([
                $s['dt'] ? null : 'No Mobil kosong',
                $s['tanggal'] ? null : ($s['tanggal_asli'] !== '' ? "Tanggal Bongkar \"{$s['tanggal_asli']}\" tidak dikenali" : 'Tanggal Bongkar kosong'),
                $s['tanggal']?->isAfter(today()) ? 'Tanggal Bongkar sesudah hari ini' : null,
                $s['tahap'] ? null : 'Tujuan Bongkar kosong',
                $s['jenis_tanah'] ? null : 'Jenis Tanah kosong',
                $s['no_seri'] ? null : 'No Surat Jalan kosong',
            ]);
            if ($salah) {
                $galat[] = "Baris {$s['baris']}".($s['dt'] ? " ({$s['dt']})" : '').': '.implode(', ', $salah).'.';
            }
        }
        if ($galat) {
            return ['isian' => [], 'per_dt' => [], 'galat' => $galat];
        }
        $duplikat = collect($sj)->groupBy(fn ($s) => mb_strtolower($s['tahap']).'|'.LembarRitasi::kunciAngka($s['no_seri']))->filter(fn ($g) => $g->count() > 1);
        foreach ($duplikat as $g) {
            $galat[] = 'No Surat Jalan '.$g->first()['no_seri'].' ('.$g->first()['tahap'].') tertulis '.$g->count().'× (baris '.$g->pluck('baris')->implode(', ').').';
        }
        if ($galat) {
            return ['isian' => [], 'per_dt' => [], 'galat' => $galat];
        }

        // Per DT + galian: surat jalan hanya dipasangkan ke DO DT itu yang galiannya sama (galian DO = tempat di keterangan UJ-nya).
        // Surat jalan tanpa galian di Excel dipasangkan ke semua DO DT itu (diproses terakhir, DO yang sudah terpakai tidak diulang).
        $terpakai = [];
        $kelompok = collect($sj)->groupBy(fn ($s) => $s['dt'].'|'.self::kunciGalian($s['galian']))
            ->sortBy(fn ($g, $k) => str_ends_with($k, '|') ? 1 : 0);
        foreach ($kelompok as $daftarSj) {
            $dt = $daftarSj->first()['dt'];
            $galianSj = $daftarSj->first()['galian'];
            $daftarSj = $daftarSj->sortBy(fn ($s) => $s['tanggal']->format('Ymd').($s['jam'] ? str_pad($s['jam'], 5, '0', STR_PAD_LEFT) : '99:99').str_pad((string) LembarRitasi::kunciAngka($s['no_seri']), 10, '0', STR_PAD_LEFT))->values();
            $semuaDt = $belum->filter(fn ($d) => in_array($dt, $d['mobil'], true) && ! isset($terpakai[$d['do']]));
            $doDt = $semuaDt->filter(fn ($d) => ! $galianSj || self::galianSama($galianSj, $d))
                ->sortBy(fn ($d) => ($d['pertama']?->format('Ymd') ?? '0').str_pad($d['do'], 10, '0', STR_PAD_LEFT))->values();
            $info = ['dt' => $dt, 'galian' => $galianSj, 'sj' => $daftarSj->count(), 'do_belum' => $doDt->count(), 'pasangan' => [], 'galat' => null, 'catatan' => []];
            $lain = $semuaDt->reject(fn ($d) => $doDt->contains('do', $d['do']));
            $sebutLain = fn () => $lain->map(fn ($d) => $d['do'].' ('.(self::galianDo($d) ?? 'galian tidak diketahui').')')->implode(', ');
            if (! $galianSj) {
                $info['catatan'][] = 'Galian kosong di Excel — dicocokkan ke semua DO belum bongkar DT ini.';
            }
            if ($daftarSj->count() > $doDt->count()) {
                $info['galat'] = "{$daftarSj->count()} surat jalan, padahal DO belum bongkar {$dt}".($galianSj ? " galian {$galianSj}" : '')." hanya {$doDt->count()}"
                    .($doDt->isNotEmpty() ? ' (DO '.$doDt->pluck('do')->implode(', ').')' : '').' — periksa lagi No Mobil / galian / surat jalannya. Tidak ada yang diisi untuk kelompok ini.'
                    .($lain->isNotEmpty() ? ' DO '.$dt.' lain yang belum bongkar: '.$sebutLain().'.' : '');
                $perDt[] = $info;

                continue;
            }
            // Urut antrean (FIFO): DO paling lama dibongkar duluan. DO yang UJ-nya sesudah bongkar terakhir tidak mungkin dipakai;
            // DO yang UJ-nya jauh sebelum bongkar pertama (tertinggal lama) baru dipakai bila DO yang wajar tidak cukup.
            $akhir = $daftarSj->last()['tanggal']->format('Ymd');
            $batasLama = $daftarSj->first()['tanggal']->copy()->subDays(self::HARI_LAMA)->format('Ymd');
            $mungkin = $doDt->filter(fn ($d) => ! $d['pertama'] || $d['pertama']->format('Ymd') <= $akhir)->values();
            if ($mungkin->count() < $daftarSj->count()) {
                $mungkin = $doDt; // tetap dipasangkan; ketidakcocokan tanggal ditandai di bawah
            }
            $wajar = $mungkin->filter(fn ($d) => ! $d['pertama'] || $d['pertama']->format('Ymd') >= $batasLama)->values();
            $pakai = $wajar->count() >= $daftarSj->count()
                ? $wajar->take($daftarSj->count())
                : $mungkin->reject(fn ($d) => $wajar->contains('do', $d['do']))->take(-($daftarSj->count() - $wajar->count()))->concat($wajar);
            $pakai = $pakai->sortBy(fn ($d) => ($d['pertama']?->format('Ymd') ?? '0').str_pad($d['do'], 10, '0', STR_PAD_LEFT))->values();
            foreach ($daftarSj as $i => $s) {
                $d = $pakai[$i];
                $terpakai[$d['do']] = true;
                $isian[$d['do']] = [
                    'tanggal' => $s['tanggal']->toDateString(), 'jam' => $s['jam'], 'tahap' => $s['tahap'], 'galian' => $s['galian'],
                    'jenis_tanah' => $s['jenis_tanah'], 'no_seri' => $s['no_seri'], 'keterangan' => $s['keterangan'],
                ];
                // Galian wajib saat simpan: dari Excel, atau tebakan dari keterangan UJ DO itu.
                $galian = $s['galian'] ?? self::galianDo($d);
                $catatan = implode(' · ', array_filter([
                    $d['pertama'] && $d['pertama']->gt($s['tanggal']) ? 'UJ '.$d['pertama']->translatedFormat('j M').' sesudah tanggal bongkar' : null,
                    $d['pertama'] && $d['pertama']->format('Ymd') < $batasLama ? 'UJ '.$d['pertama']->translatedFormat('j M Y').' — sudah '.(int) $d['pertama']->diffInDays($s['tanggal']).' hari sebelum bongkar' : null,
                    $galian ? null : 'Galian belum diketahui — isi di form',
                ])) ?: null;
                $info['pasangan'][] = ['baris' => $s['baris'], 'no_seri' => $s['no_seri'], 'tanggal' => $s['tanggal'], 'jam' => $s['jam'], 'do' => $d['do'], 'uj' => $d['pertama'],
                    'galian_do' => self::galianDo($d), 'catatan' => $catatan,
                    // ID transaksi uang jalan DO itu di Kas UJ.
                    'id_uj' => collect($d['detail'])->pluck('id_uj')->filter()->unique()->implode(', ')];
            }
            $sisa = $doDt->pluck('do')->diff($pakai->pluck('do'));
            if ($sisa->isNotEmpty()) {
                $info['catatan'][] = 'DO galian ini yang tidak terpakai: '.$sisa->implode(', ');
            }
            $perDt[] = $info;
        }

        return ['isian' => $isian, 'per_dt' => $perDt, 'galat' => []];
    }

    /** Galian DO dari keterangan UJ-nya (nama galian baku bila dikenal), null bila tidak diketahui. */
    private static function galianDo(array $d): ?string
    {
        return $d['tujuan'] ? (TebakGalian::galian($d['tujuan'], null) ?? ucwords($d['tujuan'])) : null;
    }

    private static function kunciGalian(?string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $v));
    }

    /**
     * Galian surat jalan sama dengan galian DO: sama dengan galian tebakan DO (persis, atau salah satu memuat yang lain:
     * "Sajira" ~ "Sajira 2"), atau nama galiannya tertulis di keterangan UJ DO itu ("Uang Jalan Sidomuncul KIK").
     */
    private static function galianSama(string $galian, array $d): bool
    {
        $a = self::kunciGalian($galian);
        if (strlen($a) < 3) {
            return false;
        }
        foreach (array_filter([self::galianDo($d), $d['tujuan']]) as $calon) {
            $b = self::kunciGalian($calon);
            if ($a === $b || (strlen($a) >= 4 && strlen($b) >= 4 && (str_contains($a, $b) || str_contains($b, $a)))) {
                return true;
            }
        }
        foreach ($d['detail'] ?? [] as $x) {
            if (str_contains(self::kunciGalian($x->keterangan ?? ''), $a)) {
                return true;
            }
        }

        return false;
    }

    private static function tanggal(mixed $v): ?Carbon
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v)->startOfDay();
        }
        if (is_int($v) || is_float($v)) {
            return $v > 20000 && $v < 80000 ? Carbon::create(1899, 12, 30)->addDays((int) $v) : null;
        }

        return RitasiController::tanggal(trim((string) $v));
    }

    private static function jam(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('H:i');
        }
        if ($v instanceof \DateInterval) {
            return sprintf('%02d:%02d', $v->h, $v->i);
        }
        if (is_float($v) || is_int($v)) {
            $menit = (int) round(fmod((float) $v, 1) * 1440);

            return $v >= 0 && $v < 1 ? sprintf('%02d:%02d', intdiv($menit, 60), $menit % 60) : null;
        }

        return LembarRitasi::jam(trim((string) $v) ?: null);
    }
}
