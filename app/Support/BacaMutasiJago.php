<?php

namespace App\Support;

use Carbon\Carbon;
use RuntimeException;

/**
 * Pembaca PDF "Pockets Transactions History" Bank Jago (owner 28 Okt 2026) — bahasa Indonesia (HP Eddy) maupun Inggris (HP owner).
 * Memakai `pdftotext -bbox` (poppler-utils): kolom ditentukan dari posisi judul kolom tiap halaman, satu transaksi dimulai dari baris
 * bertanggal di kolom Tanggal. Hasil: nama & nomor kantong, periode, dan baris (waktu, sumber/tujuan, bank + rekening, jenis, ID# bank,
 * catatan, jumlah, saldo). Saldo per baris diperiksa bersambung (file yang difilter / terpotong ditolak oleh pemanggil).
 */
class BacaMutasiJago
{
    private const BULAN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12];

    /** Judul kolom (Indonesia / Inggris) → kunci kolom. */
    private const JUDUL = ['Tanggal' => 'tanggal', 'Date' => 'tanggal', 'Sumber/Tujuan' => 'sumber', 'Source/Destination' => 'sumber', 'Rincian' => 'rincian',
        'Transaction' => 'rincian', 'Catatan' => 'catatan', 'Notes' => 'catatan', 'Jumlah' => 'jumlah', 'Amount' => 'jumlah', 'Saldo' => 'saldo', 'Balance' => 'saldo'];

    /**
     * @return array{pemilik: string, kantong: string, nomor: string, dari: ?string, sampai: ?string,
     *               baris: array<int, array{waktu: Carbon, nama: string, bank: ?string, rekening: ?string, jenis: string, arah: string, id_bank: ?string, catatan: string, jumlah: float, saldo: float}>}
     */
    public static function baca(string $pdf): array
    {
        $xml = self::bbox($pdf);
        $halaman = self::kataPerHalaman($xml);
        if (! $halaman) {
            throw new RuntimeException('PDF tidak berisi teks — pastikan ini file "Pockets Transactions History" dari aplikasi Jago (bukan foto / hasil scan).');
        }
        $kepala = self::kepala($halaman[0]);
        $baris = [];
        foreach ($halaman as $kata) {
            array_push($baris, ...self::barisHalaman($kata));
        }
        if (! $baris) {
            throw new RuntimeException('Tidak ada transaksi yang terbaca di PDF ini.');
        }

        return [...$kepala, 'baris' => $baris];
    }

    /**
     * Saldo per baris bersambung (saldo = saldo sebelumnya + jumlah). Selisih sen (< Rp1) diabaikan: Jago kadang menampilkan sen bunga hanya di
     * satu baris (mis. 66.954.120,56 lalu 66.610.120,00). @return string[] daftar masalah
     */
    public static function periksaSaldo(array $baris): array
    {
        $masalah = [];
        for ($i = 1; $i < count($baris); $i++) {
            $harus = round($baris[$i - 1]['saldo'] + $baris[$i]['jumlah'], 2);
            if (abs($harus - $baris[$i]['saldo']) >= 1) {
                $masalah[] = 'Saldo tidak bersambung sesudah '.$baris[$i - 1]['waktu']->format('d-m-Y H:i').' '.$baris[$i - 1]['nama'].': seharusnya '.number_format($harus, 2, ',', '.')
                    .', tertulis '.number_format($baris[$i]['saldo'], 2, ',', '.').' ('.$baris[$i]['waktu']->format('d-m-Y H:i').' '.$baris[$i]['nama'].').';
            }
        }

        return $masalah;
    }

    private static function bbox(string $pdf): string
    {
        $keluar = [];
        $kode = 0;
        exec('pdftotext -bbox '.escapeshellarg($pdf).' - 2>&1', $keluar, $kode);
        if ($kode !== 0) {
            throw new RuntimeException('PDF tidak bisa dibaca (pdftotext): '.mb_strimwidth(implode(' ', $keluar), 0, 200, '…'));
        }

        return implode("\n", $keluar);
    }

    /** @return array<int, array{tinggi: float, kata: array<int, array{x: float, x2: float, y: float, t: string}>}> */
    private static function kataPerHalaman(string $xml): array
    {
        $hasil = [];
        foreach (preg_split('/<page\b/', $xml) as $i => $bagian) {
            if ($i === 0) {
                continue;
            }
            preg_match('/height="([\d.]+)"/', $bagian, $h);
            preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="[\d.]+">(.*?)<\/word>/s', $bagian, $m, PREG_SET_ORDER);
            $kata = array_map(fn ($w) => ['x' => (float) $w[1], 'y' => (float) $w[2], 'x2' => (float) $w[3], 't' => html_entity_decode($w[4], ENT_QUOTES | ENT_XML1, 'UTF-8')], $m);
            if ($kata) {
                $hasil[] = ['tinggi' => (float) ($h[1] ?? 2610), 'kata' => $kata];
            }
        }

        return $hasil;
    }

    /** Nama pemilik, nama + nomor kantong, periode dari halaman pertama. */
    private static function kepala(array $hal): array
    {
        $baris = self::kelompokBaris(array_filter($hal['kata'], fn ($w) => $w['x'] < 900));
        $teks = array_map(fn ($b) => implode(' ', array_column($b, 't')), $baris);
        $pemilik = $kantong = $nomor = '';
        $dari = $sampai = null;
        foreach ($teks as $i => $t) {
            if (preg_match('/^(.*\S)\s+(\d{8,16})$/', $t, $m) && $kantong === '') {
                [$kantong, $nomor] = [$m[1], $m[2]];
                $pemilik = $teks[$i - 1] ?? '';
            }
            if (preg_match('/^(\d{1,2} \S+ \d{4}) - (\d{1,2} \S+ \d{4})$/', $t, $m)) {
                $dari = self::tanggal($m[1])?->toDateString();
                $sampai = self::tanggal($m[2])?->toDateString();
            }
        }
        if ($nomor === '') {
            throw new RuntimeException('Nama / nomor kantong tidak ditemukan di halaman pertama — ini bukan file riwayat transaksi kantong Jago.');
        }
        // Kanan atas: "Saldo terbaru 28 Oct 2026 / IDR 46.109.405,50" (Inggris: "Latest Balance per 31 May 2026") = saldo kantong saat PDF diunduh.
        $kanan = array_map(fn ($b) => implode(' ', array_column($b, 't')), self::kelompokBaris(array_filter($hal['kata'], fn ($w) => $w['x'] >= 900 && $w['y'] < 450)));
        $tglTerbaru = $saldoTerbaru = null;
        foreach ($kanan as $t) {
            if (preg_match('/(?:terbaru|per)\s+(\d{1,2} \S+ \d{4})$/i', $t, $m)) {
                $tglTerbaru = self::tanggal($m[1])?->toDateString();
            }
            if (preg_match('/^IDR\s+([\d.]+(?:,\d+)?)$/', $t, $m)) {
                $saldoTerbaru = self::angka($m[1]);
            }
        }

        return ['pemilik' => $pemilik, 'kantong' => $kantong, 'nomor' => $nomor, 'dari' => $dari, 'sampai' => $sampai, 'tgl_terbaru' => $tglTerbaru, 'saldo_terbaru' => $saldoTerbaru];
    }

    /** Transaksi di satu halaman. */
    private static function barisHalaman(array $hal): array
    {
        $kata = $hal['kata'];
        // Judul kolom (baris berisi "Sumber/Tujuan" / "Source/Destination").
        $kolom = [];
        foreach ($kata as $w) {
            if (isset(self::JUDUL[$w['t']]) && ! isset($kolom[self::JUDUL[$w['t']]])) {
                $kolom[self::JUDUL[$w['t']]] = $w;
            }
        }
        if (! isset($kolom['sumber'], $kolom['rincian'], $kolom['catatan'], $kolom['jumlah'], $kolom['saldo'])) {
            return [];
        }
        $yJudul = $kolom['sumber']['y'];
        $batasBawah = $hal['tinggi'] - 230; // kaki halaman (LPS / OJK)
        foreach ($kata as $w) {
            // "Info Penting" / "Disclaimer" di halaman terakhir menutup tabel.
            if ($w['x'] < $kolom['sumber']['x'] && $w['y'] > $yJudul && in_array($w['t'], ['Info', 'Disclaimer', 'Important'], true)) {
                $batasBawah = min($batasBawah, $w['y'] - 5);
            }
        }
        $isi = array_values(array_filter($kata, fn ($w) => $w['y'] > $yJudul + 10 && $w['y'] < $batasBawah));
        $kol = function (array $w) use ($kolom): string {
            if ($w['x'] < $kolom['sumber']['x'] - 5) {
                return 'tanggal';
            }
            if ($w['x'] < $kolom['rincian']['x'] - 5) {
                return 'sumber';
            }
            if ($w['x'] < $kolom['catatan']['x'] - 5) {
                return 'rincian';
            }
            if (preg_match('/^[+-][\d.]+(,\d+)?$/', $w['t'])) {
                return 'jumlah';
            }
            if ($w['x2'] > $kolom['saldo']['x'] - 40 && preg_match('/^[\d.]+(,\d+)?$/', $w['t'])) {
                return 'saldo';
            }

            return 'catatan';
        };
        // Awal transaksi = baris kolom Tanggal berbentuk "27 Okt 2026" / "29 May 2026" (judul bulan "Oktober 2026" dilewati).
        $tgl = self::kelompokBaris(array_filter($isi, fn ($w) => $kol($w) === 'tanggal'));
        $mulai = [];
        foreach ($tgl as $b) {
            $t = implode(' ', array_column($b, 't'));
            if (preg_match('/^\d{1,2} \S{3,} \d{4}$/', $t) && self::tanggal($t)) {
                $mulai[] = $b[0]['y'];
            }
        }
        $hasil = [];
        foreach ($mulai as $i => $y0) {
            $y1 = $mulai[$i + 1] ?? $batasBawah;
            $blok = array_filter($isi, fn ($w) => $w['y'] >= $y0 - 4 && $w['y'] < $y1 - 4);
            $per = ['tanggal' => [], 'sumber' => [], 'rincian' => [], 'catatan' => [], 'jumlah' => [], 'saldo' => []];
            foreach ($blok as $w) {
                $per[$kol($w)][] = $w;
            }
            $teksBaris = fn (array $ws) => array_map(fn ($b) => implode(' ', array_column($b, 't')), self::kelompokBaris($ws));
            $tglBaris = $teksBaris($per['tanggal']);
            $waktu = self::tanggal($tglBaris[0] ?? '', $tglBaris[1] ?? null);
            $sumber = $teksBaris($per['sumber']);
            $bank = $rek = null;
            if (count($sumber) > 1 && preg_match('/^(.+?)\s+([\d][\d\-]{4,})$/', end($sumber), $m)) {
                [$bank, $rek] = [$m[1], $m[2]];
                array_pop($sumber);
            }
            $rincian = implode(' ', $teksBaris($per['rincian']));
            $idBank = preg_match('/ID#\s*(\S+)/', $rincian, $m) ? $m[1] : null;
            $jenis = trim(preg_replace('/ID#\s*\S+/', '', $rincian));
            $jumlah = self::angka($per['jumlah'][0]['t'] ?? null);
            $saldo = self::angka($per['saldo'][0]['t'] ?? null);
            if (! $waktu || $jumlah === null || $saldo === null) {
                throw new RuntimeException('Satu transaksi tidak terbaca lengkap (sekitar "'.implode(' ', $tglBaris).' '.implode(' ', $sumber).'") — format PDF mungkin berubah.');
            }
            $hasil[] = ['waktu' => $waktu, 'nama' => trim(implode(' ', $sumber)), 'bank' => $bank, 'rekening' => $rek, 'jenis' => $jenis,
                'arah' => $jumlah < 0 ? 'keluar' : 'masuk', 'id_bank' => $idBank, 'catatan' => trim(implode(' ', $teksBaris($per['catatan']))), 'jumlah' => $jumlah, 'saldo' => $saldo];
        }

        return $hasil;
    }

    /** Kata dikelompokkan per baris (y berdekatan), tiap baris urut x. @return array<int, array<int, array>> */
    private static function kelompokBaris(array $kata): array
    {
        usort($kata, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
        $baris = [];
        foreach ($kata as $w) {
            $n = count($baris);
            if ($n && abs($baris[$n - 1][0]['y'] - $w['y']) < 8) {
                $baris[$n - 1][] = $w;
            } else {
                $baris[] = [$w];
            }
        }

        return array_map(function ($b) {
            usort($b, fn ($p, $q) => $p['x'] <=> $q['x']);

            return $b;
        }, $baris);
    }

    private static function tanggal(string $tgl, ?string $jam = null): ?Carbon
    {
        if (! preg_match('/^(\d{1,2}) (\S{3,}) (\d{4})$/', trim($tgl), $m) || ! ($bln = self::BULAN[mb_strtolower(mb_substr($m[2], 0, 3))] ?? null)) {
            return null;
        }
        [$h, $i] = preg_match('/^(\d{1,2})[.:](\d{2})$/', trim((string) $jam), $j) ? [(int) $j[1], (int) $j[2]] : [0, 0];

        return checkdate($bln, (int) $m[1], (int) $m[3]) ? Carbon::create((int) $m[3], $bln, (int) $m[1], $h, $i) : null;
    }

    /** "-1.300.000" / "36.460.204,71" / "+45.117.500" → float. */
    private static function angka(?string $v): ?float
    {
        if ($v === null || ! preg_match('/^([+-]?)([\d.]+)(?:,(\d+))?$/', $v, $m)) {
            return null;
        }

        return (float) ($m[1].str_replace('.', '', $m[2]).(isset($m[3]) ? '.'.$m[3] : ''));
    }
}
