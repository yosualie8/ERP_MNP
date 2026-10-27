<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Tandai daftar ID (hasil validasi yang sudah valid) Sudah Reimburse. Diperiksa ulang di sini: tidak boleh ada ID ganda
     * atau yang sudah direimburse (mis. baru ditandai admin lain). ID yang tidak ada di Kas Harian aplikasi tidak ditandai.
     *
     * @param  string[]  $ids
     * @return array{ditandai: string[], tak_dikenal: string[], total: int}
     */
    public static function tandai(array $ids, Carbon $tanggal, ?int $userId): array
    {
        $ids = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $ids)));
        if (! $ids) {
            throw new RuntimeException('Tidak ada ID transaksi untuk ditandai.');
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Ada ID transaksi ganda di daftar. Validasi ulang file-nya.');
        }

        return Cache::lock('tandai-reimburse-kas', 60)->block(30, function () use ($ids, $tanggal, $userId) {
            $sudah = array_keys(StatusReimburse::untuk($ids));
            if ($sudah) {
                throw new RuntimeException('Ditolak: '.count($sudah).' transaksi sudah berstatus Sudah Reimburse ('.implode(', ', array_slice($sudah, 0, 5))
                    .(count($sudah) > 5 ? ', …' : '').'). Validasi ulang file-nya.');
            }
            $nominal = [];
            foreach (array_chunk($ids, 1000) as $potong) {
                $nominal += DB::table('kas_bon')->whereIn('id_transaksi', $potong)->pluck('nominal', 'id_transaksi')->all();
            }
            $dikenal = array_values(array_filter($ids, fn ($id) => array_key_exists($id, $nominal)));
            if ($dikenal) {
                StatusReimburse::tandai($dikenal, $tanggal, $userId);
            }

            return ['ditandai' => $dikenal, 'tak_dikenal' => array_values(array_diff($ids, $dikenal)),
                'total' => (int) array_sum(array_map(fn ($id) => (int) $nominal[$id], $dikenal))];
        });
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

        // Opsi bersih: tanpa transaksi yang sudah direimburse dan tanpa kemunculan kedua dst. ID dobel (kemunculan pertama dipakai).
        $dipakai = [];
        $bersih = [];
        foreach ($baris as $b) {
            if ($b['status'] === 'sudah' || isset($dipakai[$b['id']])) {
                continue;
            }
            $dipakai[$b['id']] = true;
            $bersih[] = $b;
        }
        $valid = ! $sudah && ! $ganda && ! $takTerbaca;

        return [
            'lembar' => $sheet->getName(), 'baris' => $baris, 'sudah' => $sudah, 'ganda' => $ganda, 'tak_dikenal' => $takDikenal,
            'beda' => $beda, 'tak_terbaca' => $takTerbaca, 'rekap' => self::rekap($baris), 'total' => array_sum(array_column($baris, 'nominal')),
            // Valid = tidak ada yang sudah pernah direimburse, tidak ada ID ganda, dan semua nominal terbaca.
            'valid' => $valid,
            'bersih' => ['baris' => $bersih, 'rekap' => self::rekap($bersih), 'total' => array_sum(array_column($bersih, 'nominal')),
                'dibuang' => count($baris) - count($bersih)],
            // Nominal yang tidak terbaca tetap harus dibereskan dulu (nilainya tidak diketahui).
            'bisa_bersih' => ! $valid && ! $takTerbaca && $bersih && count($baris) > count($bersih),
        ];
    }

    /** Rekap per Kode GL seperti pivot Excel: huruf besar/kecil & spasi ganda tidak membedakan Kode GL. */
    private static function rekap(array $baris): array
    {
        $rekap = [];
        foreach ($baris as $b) {
            $gl = $b['gl'] !== '' ? preg_replace('/\s+/', ' ', $b['gl']) : '(tanpa Kode GL)';
            $kunci = mb_strtolower($gl);
            $rekap[$kunci] ??= ['gl' => $gl, 'jumlah' => 0, 'nominal' => 0];
            $rekap[$kunci]['jumlah']++;
            $rekap[$kunci]['nominal'] += $b['nominal'];
        }
        ksort($rekap, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($rekap);
    }

    /** Folder file unggahan validasi (disimpan sementara supaya bisa dibuat Excel revisinya). */
    public const FOLDER = 'validasi-reimburse';

    /** Simpan file unggahan milik pengguna ini; file lebih dari 3 hari dibersihkan. @return string token */
    public static function simpanUnggahan(string $pathSementara, int $userId): string
    {
        $folder = storage_path('app/'.self::FOLDER);
        if (! is_dir($folder)) {
            mkdir($folder, 0775, true);
        }
        foreach (glob($folder.'/*.xlsx') ?: [] as $f) {
            if (filemtime($f) < time() - 3 * 86400) {
                @unlink($f);
            }
        }
        $token = \Illuminate\Support\Str::random(40);
        copy($pathSementara, self::path($token, $userId));

        return $token;
    }

    /** Path file unggahan ($revisi = false) atau Excel revisinya, milik pengguna itu saja. */
    public static function path(string $token, int $userId, bool $revisi = false): string
    {
        return storage_path('app/'.self::FOLDER."/{$userId}-{$token}".($revisi ? '-revisi' : '').'.xlsx');
    }

    /**
     * Excel revisi: file asli (format, rumus & lembar lain tetap) dengan baris yang tidak valid dihapus dari lembar itu —
     * ID yang sudah direimburse dan kemunculan kedua dst. ID dobel. Rumus (mis. SUM total) ikut menyesuaikan.
     *
     * @param  string[]  $idSudah
     * @param  string[]  $idDaftar  ID transaksi yang terbaca saat validasi (baris lain, mis. judul/total, tidak disentuh)
     * @return array{dihapus: int, peringatan: string[]}
     */
    public static function revisi(string $path, string $lembar, array $idSudah, array $idDaftar, string $tujuan): array
    {
        $peringatan = [];
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_starts_with((string) $zip->getNameIndex($i), 'xl/pivotTables/')) {
                    $peringatan[] = 'File asli berisi pivot table — pivot tidak ikut tersimpan di Excel revisi, buat ulang bila perlu.';
                    break;
                }
            }
            $zip->close();
        }
        $wb = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')->load($path);
        $ws = null;
        foreach ($wb->getWorksheetIterator() as $s) {
            if (mb_strtolower(trim($s->getTitle())) === mb_strtolower(trim($lembar))) {
                $ws = $s;
                break;
            }
        }
        if (! $ws) {
            throw new RuntimeException("Lembar \"{$lembar}\" tidak ada di file ini.");
        }
        // Rumus di lembar lain yang merujuk ke lembar ini tidak ikut digeser oleh PhpSpreadsheet → diberi tahu.
        $judul = preg_quote($ws->getTitle(), '/');
        $rujukan = 0;
        foreach ($wb->getWorksheetIterator() as $s) {
            if ($s === $ws) {
                continue;
            }
            foreach ($s->getCoordinates() as $k) {
                $c = $s->getCell($k);
                if ($c->isFormula() && preg_match("/(^|[^A-Za-z0-9_])'?{$judul}'?!/iu", (string) $c->getValue())) {
                    $rujukan++;
                }
            }
        }
        if ($rujukan) {
            $peringatan[] = "{$rujukan} rumus di lembar lain merujuk ke lembar \"{$ws->getTitle()}\" dan tidak ikut disesuaikan — cek rumus itu di Excel revisi.";
        }
        $sudah = array_flip($idSudah);
        $daftar = array_flip($idDaftar);
        $lihat = [];
        $hapus = [];
        for ($r = 1, $akhir = $ws->getHighestDataRow('A'); $r <= $akhir; $r++) {
            $c = $ws->getCell("A{$r}");
            $id = trim((string) ($c->isFormula() ? $c->getOldCalculatedValue() : $c->getValue()));
            if ($id === '' || ! isset($daftar[$id])) {
                continue;
            }
            if (isset($sudah[$id]) || isset($lihat[$id])) {
                $hapus[] = $r;
            }
            $lihat[$id] = true;
        }
        // Dari bawah, per kelompok baris berurutan, supaya nomor baris di atasnya tidak bergeser.
        $kelompok = [];
        foreach (array_reverse($hapus) as $r) {
            $n = count($kelompok);
            if ($n && $kelompok[$n - 1][0] === $r + 1) {
                $kelompok[$n - 1] = [$r, $kelompok[$n - 1][1] + 1];
            } else {
                $kelompok[] = [$r, 1];
            }
        }
        foreach ($kelompok as [$mulai, $jumlah]) {
            $ws->removeRow($mulai, $jumlah);
        }
        $w = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($wb, 'Xlsx');
        $w->setPreCalculateFormulas(false); // dihitung ulang oleh Excel saat dibuka
        $w->save($tujuan);
        $wb->disconnectWorksheets();

        return ['dihapus' => count($hapus), 'peringatan' => $peringatan];
    }
}
