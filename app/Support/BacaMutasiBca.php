<?php

namespace App\Support;

use Carbon\Carbon;
use RuntimeException;

/**
 * Membaca file unduhan "Mutasi Rekening" KlikBCA Bisnis (CorpAcctTrxn*.csv, juga versi .xlsx-nya) — owner 10 Nov 2026.
 * Kepala: No. rekening, Nama, Periode dd/mm/yyyy - dd/mm/yyyy; baris: Tanggal (dd/mm atau "PEND" = belum dibukukan) |
 * Keterangan | Cabang | Jumlah "1,234.00 DB/CR" | Saldo; kaki: Saldo Awal, Mutasi Debet/Kredit (+ jumlah baris), Saldo Akhir.
 * Isi diperiksa: total & banyaknya debet/kredit harus sama dengan kaki file, saldo berjalan harus nyambung.
 */
class BacaMutasiBca
{
    /**
     * @return array{rekening: string, nama: string, dari: string, sampai: string, saldo_awal: float, saldo_akhir: float,
     *     baris: array<int, array{tanggal: string, keterangan: ?string, cabang: ?string, dbcr: string, jumlah: float, saldo: ?float, pending: bool}>}
     */
    public static function baca(string $path, string $namaAsli = ''): array
    {
        $ext = strtolower(pathinfo($namaAsli ?: $path, PATHINFO_EXTENSION));
        $rows = $ext === 'xlsx' ? self::barisXlsx($path) : self::barisCsv($path);

        $kepala = ['rekening' => null, 'nama' => null, 'dari' => null, 'sampai' => null];
        $kaki = ['saldo_awal' => null, 'saldo_akhir' => null, 'db' => null, 'cr' => null, 'n_db' => null, 'n_cr' => null];
        $mentah = [];
        $diData = false;
        foreach ($rows as $r) {
            $a = ($r[0] ?? null) instanceof \DateTimeInterface ? '' : trim((string) ($r[0] ?? ''));
            if (preg_match('/^No\.?\s*rekening\s*:\s*(\d+)/i', $a, $m)) {
                $kepala['rekening'] = $m[1];
            } elseif (preg_match('/^Nama\s*:\s*(.+)$/i', $a, $m)) {
                $kepala['nama'] = trim($m[1]);
            } elseif (preg_match('#^Periode\s*:\s*(\d{2})/(\d{2})/(\d{4})\s*-\s*(\d{2})/(\d{2})/(\d{4})#i', $a, $m)) {
                $kepala['dari'] = "{$m[3]}-{$m[2]}-{$m[1]}";
                $kepala['sampai'] = "{$m[6]}-{$m[5]}-{$m[4]}";
            } elseif (preg_match('/^Tanggal Transaksi$/i', $a)) {
                $diData = true;
            } elseif (preg_match('/^Saldo Awal\s*:\s*(.+)$/i', $a, $m)) {
                $kaki['saldo_awal'] = self::angka($m[1]);
                $diData = false;
            } elseif (preg_match('/^Saldo Akhir\s*:\s*(.+)$/i', $a, $m)) {
                $kaki['saldo_akhir'] = self::angka($m[1]);
            } elseif (preg_match('/^Mutasi (Debet|Kredit)\s*:\s*(.+)$/i', $a, $m)) {
                $k = strtolower($m[1]) === 'debet' ? 'db' : 'cr';
                $kaki[$k] = self::angka($m[2]);
                $kaki['n_'.$k] = isset($r[1]) && is_numeric(trim((string) $r[1])) ? (int) $r[1] : null;
            } elseif ($diData && ($a !== '' || $r[0] instanceof \DateTimeInterface)) {
                $mentah[] = $r;
            }
        }
        if (! $kepala['rekening'] || ! $kepala['dari']) {
            throw new RuntimeException('Bukan file Mutasi Rekening KlikBCA Bisnis (No. rekening / Periode tidak ditemukan).');
        }
        $baris = [];
        foreach ($mentah as $r) {
            [$tgl, $pending] = self::tanggal($r[0], $kepala['dari'], $kepala['sampai']);
            $c = trim((string) ($r[2] ?? ''));
            $j = $r[3] ?? null;
            if (is_numeric($j) && in_array(strtoupper($c), ['DB', 'CR'], true)) {   // versi xlsx: kolom Cabang berisi DB/CR
                [$jumlah, $dbcr, $cabang] = [(float) $j, strtoupper($c), null];
            } elseif (preg_match('/^([\d.,]+)\s*(DB|CR)$/i', trim((string) $j), $m)) {
                [$jumlah, $dbcr, $cabang] = [self::angka($m[1]), strtoupper($m[2]), $c !== '' ? $c : null];
            } else {
                throw new RuntimeException('Baris tidak terbaca: '.mb_strimwidth(implode(' | ', array_map(fn ($x) => is_scalar($x) ? (string) $x : '', $r)), 0, 120, '…'));
            }
            $saldo = $r[4] ?? null;
            $baris[] = ['tanggal' => $tgl, 'keterangan' => trim(preg_replace('/\s+/', ' ', (string) ($r[1] ?? ''))) ?: null, 'cabang' => $cabang,
                'dbcr' => $dbcr, 'jumlah' => $jumlah, 'saldo' => is_numeric($saldo) ? (float) $saldo : ($saldo !== null && trim((string) $saldo) !== '' ? self::angka((string) $saldo) : null),
                'pending' => $pending];
        }

        if (! $baris) {
            throw new RuntimeException('Tidak ada transaksi di file ini.');
        }
        // File yang sudah dibuka & disimpan ulang di Excel kadang kehilangan bagian akhir: saldo awal / akhir diambil dari baris
        // pertama / terakhir, tetap diperiksa saldo berjalannya.
        if ($kaki['saldo_awal'] === null || $kaki['saldo_akhir'] === null) {
            $p = $baris[0];
            $u = end($baris);
            if ($p['saldo'] === null || $u['saldo'] === null) {
                throw new RuntimeException('Bagian akhir file (Saldo Awal / Saldo Akhir) tidak ditemukan — file terpotong?');
            }
            $kaki['saldo_awal'] = round($p['saldo'] - ($p['dbcr'] === 'CR' ? $p['jumlah'] : -$p['jumlah']), 2);
            $kaki['saldo_akhir'] = $u['saldo'];
        }

        // Pemeriksaan isi terhadap kaki file.
        $db =array_filter($baris, fn ($b) => $b['dbcr'] === 'DB');
        $cr = array_filter($baris, fn ($b) => $b['dbcr'] === 'CR');
        $sum = fn ($xs) => round(array_sum(array_column($xs, 'jumlah')), 2);
        if ($kaki['db'] !== null && abs($sum($db) - $kaki['db']) > 0.01) {
            throw new RuntimeException('Total debet baris ('.number_format($sum($db), 2).') ≠ Mutasi Debet di file ('.number_format($kaki['db'], 2).').');
        }
        if ($kaki['cr'] !== null && abs($sum($cr) - $kaki['cr']) > 0.01) {
            throw new RuntimeException('Total kredit baris ('.number_format($sum($cr), 2).') ≠ Mutasi Kredit di file ('.number_format($kaki['cr'], 2).').');
        }
        foreach (['db' => $db, 'cr' => $cr] as $k => $xs) {
            if ($kaki['n_'.$k] !== null && $kaki['n_'.$k] !== count($xs)) {
                throw new RuntimeException('Banyaknya baris '.strtoupper($k).' ('.count($xs).') ≠ yang tertulis di file ('.$kaki['n_'.$k].').');
            }
        }
        if (abs($kaki['saldo_awal'] + $sum($cr) - $sum($db) - $kaki['saldo_akhir']) > 0.01) {
            throw new RuntimeException('Saldo Awal + kredit − debet ≠ Saldo Akhir di file.');
        }
        $berjalan = $kaki['saldo_awal'];
        foreach ($baris as $b) {
            $berjalan = round($berjalan + ($b['dbcr'] === 'CR' ? $b['jumlah'] : -$b['jumlah']), 2);
            if ($b['saldo'] !== null && abs($berjalan - $b['saldo']) > 0.01) {
                throw new RuntimeException('Saldo berjalan tidak nyambung di baris '.$b['tanggal'].' '.mb_strimwidth((string) $b['keterangan'], 0, 60, '…').'.');
            }
        }

        return $kepala + ['saldo_awal' => $kaki['saldo_awal'], 'saldo_akhir' => $kaki['saldo_akhir'], 'baris' => $baris];
    }

    /** "190,000,000.00" → 190000000.0 */
    private static function angka(string $s): float
    {
        return (float) str_replace(',', '', trim(preg_replace('/[^\d.,-]/', '', $s)));
    }

    /** [Y-m-d, pending]. "dd/mm" ditaruh di tahun yang masuk periode; "PEND" = tanggal akhir periode. */
    private static function tanggal(mixed $v, string $dari, string $sampai): array
    {
        if (is_string($v) && strtoupper(trim($v)) === 'PEND') {
            return [$sampai, true];
        }
        if ($v instanceof \DateTimeInterface) {
            // Excel membaca "04/10" sebagai 10 April: hari & bulan tertukar untuk tanggal ≤ 12.
            [$h, $b] = [(int) $v->format('n'), (int) $v->format('j')];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})#', trim((string) $v), $m)) {
            [$h, $b] = [(int) $m[1], (int) $m[2]];
        } else {
            throw new RuntimeException('Tanggal transaksi tidak dikenal: '.mb_strimwidth((string) (is_scalar($v) ? $v : ''), 0, 30));
        }
        foreach ([(int) substr($sampai, 0, 4), (int) substr($dari, 0, 4)] as $th) {
            if (checkdate($b, $h, $th) && ($t = Carbon::create($th, $b, $h)->toDateString()) >= $dari && $t <= $sampai) {
                return [$t, false];
            }
        }
        throw new RuntimeException("Tanggal {$h}/{$b} di luar periode file ({$dari} s/d {$sampai}).");
    }

    /** @return array<int, array<int, mixed>> */
    private static function barisCsv(string $path): array
    {
        $isi = file_get_contents($path);
        if ($isi === false || $isi === '') {
            throw new RuntimeException('File kosong.');
        }
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);
        $hasil = [];
        foreach (preg_split('/\r\n|\n|\r/', $isi) as $l) {
            if (trim($l) !== '') {
                $hasil[] = str_getcsv($l);
            }
        }

        return $hasil;
    }

    /** @return array<int, array<int, mixed>> */
    private static function barisXlsx(string $path): array
    {
        $r = new \OpenSpout\Reader\XLSX\Reader();
        $r->open($path);
        $hasil = [];
        try {
            foreach ($r->getSheetIterator() as $s) {
                foreach ($s->getRowIterator() as $row) {
                    $v = $row->toArray();
                    if (array_filter($v, fn ($x) => $x !== null && $x !== '')) {
                        $hasil[] = $v;
                    }
                }
                break;
            }
        } finally {
            $r->close();
        }

        return $hasil;
    }
}
