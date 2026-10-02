<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Tulis satu transfer (beserta rincian bon) ke lembar bulanan Kas Bank Jago, mengikuti cara admin mengisi:
 * - baris transfer: Tanggal, Nama/No Rek/Bank, Keterangan, Debet atau Kredit; bon pertama di baris yang sama
 * - bon berikutnya di baris di bawahnya (Tanggal + kolom K..Q)
 * - Saldo (I) dan ID TRANSAKSI (R) berupa rumus, NO ID (Q) melanjutkan nomor terakhir
 * - Kode Bon: TTTTBBHH-PIC-NN, ditambah -k bila satu transfer berisi beberapa bon PIC yang sama
 * Baris diisi di baris kosong pertama sebelum TOTAL; bila kurang, baris disisipkan di dalam jangkauan TOTAL.
 */
class TulisKasSheet
{
    public const BIAYA_TRANSFER = 2500;

    public function __construct(private GoogleSheets $sheets, private ?string $spreadsheetId = null)
    {
        $this->spreadsheetId ??= config('mnp.sheet_kas_harian');
    }

    /**
     * @param  array{tanggal: CarbonInterface, arah: string, nama_tujuan: ?string, no_rek: ?string, bank: ?string, keterangan: ?string,
     *               nominal_masuk?: int, bon?: array<int, array{nominal: int, pic: ?string, keterangan: ?string, kode_gl: ?string}>, biaya_transfer?: bool}  $input
     * @return array{lembar: string, baris_awal: int, baris_akhir: int, no_id: int[]}
     */
    public function tulis(array $input): array
    {
        return Cache::lock('tulis-kas-sheet', 60)->block(30, fn () => $this->tulisTerkunci($input));
    }

    private function tulisTerkunci(array $input): array
    {
        $tanggal = $input['tanggal'];
        $lembar = $tanggal->format('my');
        $info = collect($this->sheets->info($this->spreadsheetId)['sheets'])->firstWhere('properties.title', $lembar);
        if (! $info) {
            throw new RuntimeException("Lembar {$lembar} belum ada di sheet Kas Harian MNP. Buat dulu lembar bulan ini (salin dari bulan lalu), lalu simpan lagi.");
        }
        $sheetId = $info['properties']['sheetId'];

        $nilai = $this->sheets->nilai($this->spreadsheetId, [$lembar])[$lembar];
        [$judul, $total, $terakhir] = $this->posisi($nilai, $lembar);

        $baris = $this->susunBaris($input, $nilai, $judul, $total);
        $butuh = count($baris);
        $mulai = $terakhir + 1;

        // Sisakan minimal satu baris kosong di atas TOTAL supaya jangkauan SUM tetap ikut melebar.
        $kosong = $total - $mulai;
        if ($kosong < $butuh + 1) {
            if ($kosong < 1) {
                throw new RuntimeException("Tidak ada baris kosong di atas TOTAL pada lembar {$lembar}. Tambahkan beberapa baris kosong di atas baris TOTAL, lalu simpan lagi.");
            }
            $this->sheets->sisipBaris($this->spreadsheetId, $sheetId, $mulai, $butuh);
            $total += $butuh;
        }

        $noId = $this->noIdBerikut();
        $data = [];
        $daftarNoId = [];
        foreach ($baris as $i => $sel) {
            $n = $mulai + $i;
            $sel[8] = "=I".($n - 1)."+G{$n}-H{$n}";
            $sel[16] = $noId;
            $sel[17] = '=CONCATENATE(TEXT(B'.$n.';"yymmdd");"-Jago-";Q'.$n.')';
            $daftarNoId[] = $noId++;
            $data["{$lembar}!B{$n}:R{$n}"] = [array_values(array_slice(array_replace(array_fill(1, 17, ''), $sel), 0, 17))];
        }
        // Baris kosong pertama setelah blok: rumus saldonya harus menyambung ke baris terakhir yang ditulis.
        $setelah = $mulai + $butuh;
        if ($setelah < $total) {
            $data["{$lembar}!I{$setelah}"] = [["=I".($setelah - 1)."+G{$setelah}-H{$setelah}"]];
        }

        $this->sheets->tulis($this->spreadsheetId, $data);

        return ['lembar' => $lembar, 'baris_awal' => $mulai, 'baris_akhir' => $mulai + $butuh - 1, 'no_id' => $daftarNoId];
    }

    /** @return array{0: int, 1: int, 2: int} nomor baris judul, baris TOTAL, baris terakhir yang terisi */
    private function posisi(array $nilai, string $lembar): array
    {
        $judul = $total = null;
        foreach ($nilai as $i => $r) {
            $b = strtoupper(trim((string) ($r[1] ?? '')));
            if ($judul === null && $b === 'TANGGAL') {
                $judul = $i + 1;
            } elseif ($judul !== null && $b === 'TOTAL') {
                $total = $i + 1;
                break;
            }
        }
        if (! $judul || ! $total) {
            throw new RuntimeException("Baris judul atau TOTAL tidak ditemukan di lembar {$lembar}.");
        }

        $terakhir = $judul;
        for ($i = $judul; $i < $total - 1; $i++) {
            $r = $nilai[$i] ?? [];
            foreach ([1, 2, 3, 4, 5, 6, 7, 10, 11, 12, 13, 14, 15, 16] as $k) {
                if (trim((string) ($r[$k] ?? '')) !== '') {
                    $terakhir = $i + 1;
                    break;
                }
            }
        }

        return [$judul, $total, $terakhir];
    }

    /**
     * NO ID berlanjut lintas bulan (Sep berakhir 30666, Okt mulai 30667), jadi ambil yang terbesar dari semua
     * lembar bulanan — supaya input bertanggal bulan lalu tidak memakai nomor yang sudah dipakai bulan ini.
     */
    private function noIdBerikut(): int
    {
        $lembar = collect($this->sheets->info($this->spreadsheetId)['sheets'])->pluck('properties.title')
            ->filter(fn ($t) => preg_match('/^\d{4}$/', $t))->map(fn ($t) => "{$t}!Q:Q")->values()->all();
        $maks = collect($this->sheets->nilai($this->spreadsheetId, $lembar))->flatten()
            ->map(fn ($v) => (int) preg_replace('/\D/', '', (string) $v))->max();
        if (! $maks) {
            throw new RuntimeException('NO ID terakhir tidak ditemukan di lembar bulanan mana pun.');
        }

        return $maks + 1;
    }

    /** @return array<int, array<int, mixed>> per baris: indeks kolom (B=1 … R=17) => isi */
    private function susunBaris(array $input, array $nilai, int $judul, int $total): array
    {
        $tgl = $input['tanggal']->format('Y-m-d');
        $teks = fn (?string $v) => self::teks($v);
        $kepala = [1 => $tgl, 2 => $teks($input['nama_tujuan']), 3 => $input['no_rek'] ? "'".$input['no_rek'] : '', 4 => $teks($input['bank']), 5 => $teks($input['keterangan'])];

        if ($input['arah'] === 'masuk') {
            return [$kepala + [6 => (int) $input['nominal_masuk']]];
        }

        $bon = array_values($input['bon']);
        $kodeBon = $this->kodeBon($input['tanggal'], $bon, $nilai, $judul, $total);
        $baris = [];
        foreach ($bon as $i => $b) {
            $sel = $i === 0 ? $kepala + [7 => array_sum(array_column($bon, 'nominal'))] : [1 => $tgl];
            $baris[] = $sel + [10 => (int) $b['nominal'], 11 => $teks($b['pic']), 12 => $teks($b['keterangan']), 14 => $teks($b['kode_gl']), 15 => $kodeBon[$i]];
        }

        if (! empty($input['biaya_transfer'])) {
            $baris[] = [1 => $tgl, 5 => 'Biaya Transfer Keluar', 7 => self::BIAYA_TRANSFER, 10 => self::BIAYA_TRANSFER,
                12 => 'Biaya Transfer Keluar', 13 => 'Adm', 14 => 'Biaya Transfer Antar Bank'];
        }

        return $baris;
    }

    /** Kode Bon per bon: nomor urut per tanggal & PIC melanjutkan yang sudah ada di lembar. */
    private function kodeBon(CarbonInterface $tanggal, array $bon, array $nilai, int $judul, int $total): array
    {
        $awalan = $tanggal->format('Ymd');
        $terpakai = [];
        foreach (array_slice($nilai, $judul, $total - $judul - 1) as $r) {
            if (preg_match('/^'.$awalan.'-(.+)-(\d+)(?:-\d+)?$/', trim((string) ($r[15] ?? '')), $m)) {
                $pic = strtolower($m[1]);
                $terpakai[$pic] = max($terpakai[$pic] ?? 0, (int) $m[2]);
            }
        }

        $perPic = [];
        foreach ($bon as $i => $b) {
            if ($pic = trim((string) $b['pic'])) {
                $perPic[strtolower($pic)][] = $i;
            }
        }
        $kode = array_fill(0, count($bon), '');
        foreach ($perPic as $kunci => $indeks) {
            $nn = str_pad((string) (($terpakai[$kunci] ?? 0) + 1), 2, '0', STR_PAD_LEFT);
            $namaPic = trim($bon[$indeks[0]]['pic']);
            foreach ($indeks as $ke => $i) {
                $kode[$i] = "{$awalan}-{$namaPic}-{$nn}".(count($indeks) > 1 ? '-'.($ke + 1) : '');
            }
        }

        return $kode;
    }

    /** Teks diawali =, +, - atau @ akan dibaca sheet sebagai rumus; awali tanda kutip supaya tetap teks. */
    private static function teks(?string $v): string
    {
        $v = trim((string) $v);

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
    }
}
