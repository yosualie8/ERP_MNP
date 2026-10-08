<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Validasi Excel daftar transaksi yang akan direimburse (sebelum dibayar): ambil ID transaksi kolom A di lembar tertentu,
 * cek terhadap status reimburse aplikasi supaya tidak ada double reimburse. Bila semua aman, rekap nominal per Kode GL.
 */
class ValidasiReimburse
{
    /** Huruf kolom → indeks 0 (A = 0, K = 10, N = 13, AA = 26). */
    public static function indeks(string $huruf): int
    {
        $n = 0;
        foreach (str_split(strtoupper(trim($huruf))) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }

        return $n - 1;
    }

    /** "1.200.000" / "Rp 1,200,000" / 1200000 → int; null bila bukan angka. */
    public static function angka(mixed $v): ?int
    {
        if (is_int($v) || is_float($v)) {
            return (int) round($v);
        }
        $s = preg_replace('/[^\d\-]/', '', preg_replace('/[.,]\d{1,2}$/', '', trim((string) $v)));

        return $s === '' || $s === '-' ? null : (int) $s;
    }

    /** Nama lembar di file (untuk pesan bila nama yang diisi tidak ada). @return string[] */
    public static function daftarLembar(string $path): array
    {
        $r = new Reader();
        $r->open($path);
        $nama = [];
        foreach ($r->getSheetIterator() as $s) {
            $nama[] = $s->getName();
        }
        $r->close();

        return $nama;
    }

    /**
     * @return array{lembar: string, baris: array<int, array>, sudah: array, ganda: array, tak_dikenal: array, beda: array,
     *               tak_terbaca: array, rekap: array, total: int, valid: bool}
     */
    public static function periksa(string $path, string $lembar, string $kolomGl = 'K', string $kolomNominal = 'N'): array
    {
        $iGl = self::indeks($kolomGl);
        $iNom = self::indeks($kolomNominal);
        $r = new Reader();
        $r->open($path);
        $sheet = null;
        foreach ($r->getSheetIterator() as $s) {
            if ($sheet === null && mb_strtolower(trim($s->getName())) === mb_strtolower(trim($lembar))) {
                $sheet = $s;
                break;
            }
        }
        if (! $sheet) {
            $r->close();
            throw new RuntimeException("Lembar \"{$lembar}\" tidak ada di file ini. Lembar yang ada: ".implode(', ', self::daftarLembar($path)).'.');
        }

        $baris = [];
        $takTerbaca = [];
        $no = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $no++;
            $v = $row->toArray();
            $id = trim((string) ($v[0] ?? ''));
            if ($id === '') {
                continue;
            }
            $nominal = self::angka($v[$iNom] ?? null);
            if ($nominal === null) {
                // Baris judul (mis. "ID Transaksi Kas") dilewati; baris lain yang nominalnya tidak terbaca dilaporkan.
                if (! preg_match('/^\d{6}-/', $id)) {
                    continue;
                }
                $takTerbaca[] = ['baris' => $no, 'id' => $id, 'isi' => (string) ($v[$iNom] ?? '')];

                continue;
            }
            $baris[] = ['baris' => $no, 'id' => $id, 'gl' => trim((string) ($v[$iGl] ?? '')), 'nominal' => $nominal,
                'ket' => trim((string) ($v[4] ?? ''))];
        }
        $r->close();

        if (! $baris && ! $takTerbaca) {
            throw new RuntimeException("Lembar \"{$sheet->getName()}\" tidak berisi ID transaksi di kolom A.");
        }

        $ids = array_values(array_unique(array_column($baris, 'id')));
        $statusSudah = [];
        $batch = [];
        foreach (array_chunk($ids, 1000) as $potong) {
            foreach (DB::table('kas_sudah_reimburse')->whereIn('id_transaksi', $potong)->get(['id_transaksi', 'tanggal_reimburse', 'sumber']) as $s) {
                $statusSudah[$s->id_transaksi] = $s;
            }
        }
        // Data aplikasi per ID (untuk ID yang tidak dikenal & nominal yang berbeda).
        $app = [];
        foreach (array_chunk($ids, 1000) as $potong) {
            foreach (DB::table('kas_bon')->whereIn('id_transaksi', $potong)->get(['id_transaksi', 'nominal']) as $b) {
                $app[$b->id_transaksi] = ['nominal' => (int) $b->nominal];
            }
        }

        $hitung = array_count_values(array_column($baris, 'id'));
        $sudah = $ganda = $takDikenal = $beda = [];
        foreach ($baris as &$b) {
            $s = $statusSudah[$b['id']] ?? null;
            $b['status'] = $s ? 'sudah' : 'belum';
            $b['tgl_reimburse'] = $s?->tanggal_reimburse;
            if ($s) {
                $sudah[] = $b;
            }
            if ($hitung[$b['id']] > 1) {
                $ganda[] = $b;
            }
            if (! isset($app[$b['id']])) {
                $takDikenal[] = $b;
            } elseif ($app[$b['id']]['nominal'] !== $b['nominal']) {
                $beda[] = [...$b, 'nominal_app' => $app[$b['id']]['nominal']];
            }
        }
        unset($b);

        $rekap = [];
        foreach ($baris as $b) {
            // Seperti pivot Excel: huruf besar/kecil & spasi ganda tidak membedakan Kode GL.
            $gl = $b['gl'] !== '' ? preg_replace('/\s+/', ' ', $b['gl']) : '(tanpa Kode GL)';
            $kunci = mb_strtolower($gl);
            $rekap[$kunci] ??= ['gl' => $gl, 'jumlah' => 0, 'nominal' => 0];
            $rekap[$kunci]['jumlah']++;
            $rekap[$kunci]['nominal'] += $b['nominal'];
        }
        ksort($rekap, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'lembar' => $sheet->getName(), 'baris' => $baris, 'sudah' => $sudah, 'ganda' => $ganda, 'tak_dikenal' => $takDikenal,
            'beda' => $beda, 'tak_terbaca' => $takTerbaca, 'rekap' => array_values($rekap), 'total' => array_sum(array_column($baris, 'nominal')),
            // Valid = tidak ada yang sudah pernah direimburse, tidak ada ID ganda, dan semua nominal terbaca.
            'valid' => ! $sudah && ! $ganda && ! $takTerbaca,
        ];
    }
}
