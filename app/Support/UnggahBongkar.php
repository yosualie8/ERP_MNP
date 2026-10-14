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
        'kubikasi' => ['kubikasi', 'kubikasi (m3)', 'kubikasi (m³)', 'kubikasi m3', 'kubik', 'volume', 'm3', 'm³'],
        'keterangan' => ['keterangan', 'catatan'],
    ];

    /** DO yang UJ-nya lebih dari sekian hari sebelum bongkar dianggap tertinggal lama (dipakai terakhir, ditandai). */
    public const HARI_LAMA = 30;

    public const JUDUL_TEMPLAT = ['No Mobil', 'Tanggal Bongkar', 'Jam Bongkar', 'Tujuan Bongkar', 'Jenis Tanah', 'No Surat Jalan', 'Galian', 'Kubikasi', 'Keterangan'];

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
                // Beberapa kolom kandidat No Mobil (mis. plat & No DT): pakai yang paling banyak berisi nomor DT.
                $calon = array_keys(array_filter($teks, fn ($t) => in_array($t, self::KOLOM['dt'], true)));
                if (count($calon) > 1) {
                    $skor = fn ($k) => collect(array_slice($semua, $i + 1, 200))->filter(fn ($x) => preg_match('/^\s*DT\s*\d/i', (string) ($x[$k] ?? '')))->count();
                    $p['dt'] = collect($calon)->sortByDesc($skor)->first();
                }
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

        // No Mobil berupa plat (mis. "B 9064 UIR") → nomor DT dari kolom Plat di Data Aset (plat di data Ritasi berpindah-pindah antar
        // DT, tidak dipakai). Plat ganda di Data Aset dipilih saat dicocokkan: DT yang punya DO belum bongkar.
        $kunciPlat = fn ($p) => strtoupper(preg_replace('/\s+/', '', (string) $p));
        $plat = [];
        foreach (\App\Models\AsetTruk::whereNotNull('plat')->get(['no_lambung', 'plat']) as $a) {
            $plat[$kunciPlat($a->plat)][$a->no_lambung] = true;
        }
        $plat = collect($plat)->map(fn ($dt) => array_keys($dt));
        $hasil = [];
        foreach (array_slice($semua, $judul + 1, null, true) as $i => $r) {
            $sel = fn (string $k) => isset($posisi[$k]) ? ($r[$posisi[$k]] ?? null) : null;
            $teks = fn (string $k) => ($v = trim(preg_replace('/\s+/', ' ', (string) (is_scalar($sel($k)) ? $sel($k) : '')))) === '' ? null : $v;
            if (! $teks('dt') && ! $teks('no_seri') && ! $sel('tanggal')) {
                continue;
            }
            $hasil[] = [
                'baris' => $i + 1,
                ...self::dtDariIsian(NomorMobil::rapikan($teks('dt')), $plat),
                'tanggal' => self::tanggal($sel('tanggal')),
                'tanggal_asli' => is_scalar($sel('tanggal')) ? (string) $sel('tanggal') : ($sel('tanggal') instanceof \DateTimeInterface ? $sel('tanggal')->format('d/m/Y') : ''),
                'jam' => self::jam($sel('jam')),
                'tahap' => $teks('tahap'),
                'jenis_tanah' => $teks('jenis_tanah'),
                'no_seri' => is_float($sel('no_seri')) ? (string) (int) $sel('no_seri') : $teks('no_seri'),
                'galian' => $teks('galian'),
                'keterangan' => $teks('keterangan'),
                // Kubikasi (m³) dari Excel; kosong = standar jenis kendaraan saat disimpan.
                'kubikasi' => self::kubik($sel('kubikasi')), 'kubikasi_asli' => $teks('kubikasi'),
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
        // Plat dengan beberapa calon DT: pakai calon yang punya DO belum bongkar (hanya bila tepat satu).
        foreach ($sj as $i => $s) {
            if (! empty($s['dt_calon'])) {
                $punya = array_values(array_filter($s['dt_calon'], fn ($dt) => $belum->contains(fn ($d) => in_array($dt, $d['mobil'], true)
                    && (! $s['galian'] || self::galianSama($s['galian'], $d)))));
                if (count($punya) === 1) {
                    $sj[$i] = [...$s, 'dt' => $punya[0], 'dt_galat' => null];
                } elseif (count($punya) > 1) {
                    $sj[$i]['dt_galat'] = "No Mobil \"{$s['plat_asli']}\": plat ini dipakai beberapa DT yang sama-sama punya DO belum bongkar (".implode(', ', $punya).') — tulis nomor DT-nya';
                }
            }
        }
        foreach ($sj as $s) {
            $salah = array_filter([
                $s['dt'] ? null : 'No Mobil kosong',
                $s['tanggal'] ? null : ($s['tanggal_asli'] !== '' ? "Tanggal Bongkar \"{$s['tanggal_asli']}\" tidak dikenali" : 'Tanggal Bongkar kosong'),
                $s['tanggal']?->isAfter(today()) ? 'Tanggal Bongkar sesudah hari ini' : null,
                $s['tahap'] ? null : 'Tujuan Bongkar kosong',
                $s['jenis_tanah'] ? null : 'Jenis Tanah kosong',
                $s['no_seri'] ? null : 'No Surat Jalan kosong',
                $s['kubikasi_asli'] !== null && $s['kubikasi'] === null ? "Kubikasi \"{$s['kubikasi_asli']}\" bukan angka" : null,
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
            // Plat yang tidak bisa diterjemahkan ke satu DT: hanya kelompok ini yang tidak diisi, kelompok lain tetap jalan.
            if ($galatPlat = $daftarSj->first()['dt_galat'] ?? null) {
                $info['do_belum'] = 0;
                $info['galat'] = $galatPlat.'. Tidak ada yang diisi untuk kelompok ini.';
                $perDt[] = $info;
                continue;
            }
            if ($plat = $daftarSj->pluck('plat_asli')->filter()->unique()->implode(', ')) {
                $info['catatan'][] = "No Mobil di Excel berupa plat {$plat} → {$dt} (dari Data Aset).";
            }
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
                    'kubikasi' => $s['kubikasi'] === null ? null : rtrim(rtrim(number_format($s['kubikasi'], 2, ',', ''), '0'), ','),
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

    /**
     * No Mobil dari Excel → ['dt' => nomor DT, 'plat_asli' => plat bila diterjemahkan, 'dt_galat' => pesan bila plat tidak dikenal].
     *
     * @param  \Illuminate\Support\Collection<string, array<int, string>>  $plat  plat tanpa spasi → nomor DT di Data Aset
     */
    private static function dtDariIsian(?string $v, $plat): array
    {
        if ($v === null || preg_match('/^DT \d{3}$/', $v)) {
            return ['dt' => $v, 'plat_asli' => null, 'dt_galat' => null];
        }
        $dt = $plat[strtoupper(preg_replace('/\s+/', '', $v))] ?? [];
        if (count($dt) === 1) {
            return ['dt' => $dt[0], 'plat_asli' => $v, 'dt_galat' => null];
        }

        return ['dt' => $v, 'plat_asli' => $v, 'dt_calon' => $dt, 'dt_galat' => $dt
            ? "No Mobil \"{$v}\": plat ini tercatat di beberapa DT (".implode(', ', $dt).') — tulis nomor DT-nya'
            : "No Mobil \"{$v}\" bukan nomor DT dan platnya tidak ada di Data Aset — tulis nomor DT (mis. DT 027) atau lengkapi plat di Data Aset"];
    }

    /** Angka kubikasi dari sel Excel (22 / 22,5 / "22.5" / "22 m3"); null bila kosong / bukan angka / di luar 0–100. */
    public static function kubik(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            $k = (float) $v;
        } else {
            $t = str_replace(',', '.', preg_replace('/\s*(m3|m³)$/i', '', trim((string) $v)));
            if ($t === '' || ! is_numeric($t)) {
                return null;
            }
            $k = (float) $t;
        }

        return $k >= 0 && $k <= 100 ? round($k, 2) : null;
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
