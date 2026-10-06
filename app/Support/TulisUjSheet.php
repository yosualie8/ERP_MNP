<?php

namespace App\Support;

use App\Models\UjDetail;
use App\Models\UjTransaksi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Tulis transaksi uang jalan ke lembar "Kas Seabank", mengikuti cara admin mengisi:
 * - baris master: ID UJ, Tanggal, Bank, Rekening, Nominal Master, Nama + detail pertama di baris yang sama
 * - detail berikutnya di bawahnya (ID UJ, Tanggal, Nama, Keterangan, Nominal, Kategori, Jenis Kendaraan, No Mobil, No DO)
 * - baris "Biaya Transfer" (tanpa ID UJ) setelah detail; kolom M = rumus status reimburse
 * - ID UJ melanjutkan nomor terbesar di kolom A (satu per detail)
 * Baris baru ditambahkan di bawah data terakhir. Transaksi yang sudah direimburse tidak boleh diubah/dihapus.
 */
class TulisUjSheet
{
    public const BIAYA_TRANSFER = 2500;

    private string $id;

    public function __construct(private GoogleSheets $sheets, ?string $spreadsheetId = null)
    {
        $this->id = $spreadsheetId ?? KasSeabank::id();
    }

    /**
     * @param  array{tanggal: CarbonInterface, nama: string, bank: ?string, rekening: ?string, nominal: int,
     *               detail: array<int, array{nama: ?string, keterangan: string, nominal: int, kategori: ?string, jenis_kendaraan: ?string, no_mobil: ?string, no_do: ?string}>,
     *               biaya_transfer: bool, nominal_biaya: int}  $input
     * @return array{baris_awal: int, baris_akhir: int, no_uj: int, ids: int[]}
     */
    public function tulis(array $input): array
    {
        // Dua panggilan Google saja: baca A..H (cari ID UJ terbesar & baris terakhir), lalu tulis nilai + format sekaligus.
        return Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($input) {
            [$akhir, $max] = $this->ujung();
            $ids = range($max + 1, $max + count($input['detail']));
            $mulai = $akhir + 1;
            $baris = $this->susun($input, $ids, $mulai);
            $sampai = $mulai + count($baris) - 1;

            $g = $this->grid();
            $requests = [];
            if ($sampai > $g['rowCount']) {
                $requests[] = ['appendDimension' => ['sheetId' => $g['sheetId'], 'dimension' => 'ROWS', 'length' => $sampai - $g['rowCount'] + 200]];
                Cache::forget("uj-grid-{$this->id}");
            }
            $this->sheets->permintaan($this->id, [...$requests, ...$this->isiSel($g['sheetId'], $mulai, $baris, false)], 'menulis Kas Seabank');

            return ['baris_awal' => $mulai, 'baris_akhir' => $sampai, 'no_uj' => $ids[0], 'ids' => $ids, 'mentah' => self::keMentah($baris)];
        });
    }

    /**
     * Ubah transaksi di tempat yang sama: ID UJ lama dipakai lagi, detail tambahan mendapat ID lanjutan,
     * baris disisip/dihapus di ujung blok sesuai jumlah detail baru.
     *
     * @return array{baris_awal: int, baris_akhir: int, no_uj: int, ids: int[], geser: int, sampai_lama: int, sebelum: array}
     */
    public function ubah(UjTransaksi $t, array $input): array
    {
        return Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($t, $input) {
            $l = KasSeabank::LEMBAR;
            [$dari, $sampai, $sebelum] = $this->periksaBlok($t);

            $idLama = $t->detail->reject->biaya_transfer->map(fn (UjDetail $d) => KasSeabank::noUj($d->id_uj))->filter()->values()->all();
            $ids = array_slice($idLama, 0, count($input['detail']));
            if (count($ids) < count($input['detail'])) {
                [, $max] = $this->ujung();
                while (count($ids) < count($input['detail'])) {
                    $ids[] = ++$max;
                }
            }
            $baris = $this->susun($input, $ids, $dari);
            $n = count($baris);
            $m = $sampai - $dari + 1;
            $sheetId = $this->grid()['sheetId'];
            $requests = [];
            if ($n > $m) {
                $requests[] = ['insertDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $sampai, 'endIndex' => $sampai + $n - $m], 'inheritFromBefore' => true]];
            } elseif ($n < $m) {
                $requests[] = ['deleteDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $dari + $n - 1, 'endIndex' => $sampai]]];
            }
            // Satu panggilan: susun ulang baris + isi + format; kolom Bon dikosongkan (chip ditulis ulang bila berfoto).
            $this->sheets->permintaan($this->id, [...$requests, ...$this->isiSel($sheetId, $dari, $baris, true)], 'mengubah Kas Seabank');
            Cache::forget("uj-grid-{$this->id}");

            return ['baris_awal' => $dari, 'baris_akhir' => $dari + $n - 1, 'no_uj' => $ids[0], 'ids' => $ids, 'geser' => $n - $m,
                'sampai_lama' => $sampai, 'sebelum' => $sebelum, 'mentah' => self::keMentah($baris)];
        });
    }

    /** @return array{baris_awal: int, baris_akhir: int, sebelum: array} */
    public function hapus(UjTransaksi $t): array
    {
        return Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($t) {
            [$dari, $sampai, $sebelum] = $this->periksaBlok($t);
            $this->sheets->hapusBaris($this->id, $this->grid()['sheetId'], $dari, $sampai);
            Cache::forget("uj-grid-{$this->id}");

            return ['baris_awal' => $dari, 'baris_akhir' => $sampai, 'sebelum' => $sebelum];
        });
    }

    /**
     * Pastikan blok di sheet masih sama dengan data aplikasi dan belum direimburse.
     *
     * @return array{0: int, 1: int, 2: array}
     */
    private function periksaBlok(UjTransaksi $t): array
    {
        $t->loadMissing('detail');
        $l = KasSeabank::LEMBAR;
        [$dari, $sampai] = [$t->baris, $t->baris_akhir];
        $isi = $this->sheets->nilaiMentah($this->id, ["{$l}!A{$dari}:N".($sampai + 1)])["{$l}!A{$dari}:N".($sampai + 1)];
        $berubah = fn (string $alasan) => new RuntimeException("Isi sheet Kas Seabank sudah berubah sejak sinkron terakhir ({$alasan}). Klik \"Sinkron dari sheet\", periksa lagi, lalu coba ulang.");
        foreach ($t->detail as $d) {
            $r = $isi[$d->baris - $dari] ?? [];
            if (KasSeabank::angka($r[7] ?? null) !== $d->nominal || trim((string) ($r[0] ?? '')) !== (string) $d->id_uj) {
                throw $berubah("baris {$d->baris} tidak sama");
            }
            if (trim((string) ($r[13] ?? '')) !== '') {
                throw new RuntimeException("Ditolak: baris {$d->baris} ({$d->id_uj}) sudah direimburse. Ubah lewat sheet setelah dicek bagian reimburse.");
            }
        }
        // Baris sesudah blok tidak boleh berupa detail lanjutan (bukan master) — artinya blok di sheet lebih panjang.
        $sesudah = $isi[$sampai + 1 - $dari] ?? [];
        $adaIsi = KasSeabank::adaData($sesudah);
        $masterBerikut = trim((string) ($sesudah[2] ?? '')) !== '' || trim((string) ($sesudah[3] ?? '')) !== '';
        if ($adaIsi && ! $masterBerikut) {
            throw $berubah('baris '.($sampai + 1).' masih berisi detail milik transaksi ini');
        }

        return [$dari, $sampai, array_slice($isi, 0, $sampai - $dari + 1)];
    }

    /**
     * Baris A..M untuk satu transaksi mulai baris $mulai.
     *
     * @param  int[]  $ids  nomor ID UJ per detail
     */
    private function susun(array $input, array $ids, int $mulai): array
    {
        $tgl = $input['tanggal']->format('Y-m-d');
        $teks = fn (?string $v) => self::teks($v);
        $status = fn (int $n) => "=IF(N{$n}=\"\";\"Belum Reimburse\";\"Sudah Reimburse\")";
        $jumlah = array_sum(array_column($input['detail'], 'nominal'));
        if (! $input['detail'] || $jumlah !== (int) $input['nominal']) {
            throw new RuntimeException('Jumlah detail '.rp($jumlah).' tidak sama dengan nominal master '.rp((int) $input['nominal']).'; tidak ditulis.');
        }

        $baris = [];
        foreach (array_values($input['detail']) as $i => $d) {
            $n = $mulai + $i;
            $baris[] = [
                'UJ-'.$ids[$i], $tgl,
                $i === 0 ? $teks($input['bank']) : '', $i === 0 ? self::angkaTeks($input['rekening']) : '', $i === 0 ? (int) $input['nominal'] : '',
                $teks($i === 0 ? ($d['nama'] ?: $input['nama']) : $d['nama']), $teks($d['keterangan']), (int) $d['nominal'], $teks($d['kategori']),
                $teks($d['jenis_kendaraan']), $teks($d['no_mobil']), self::angkaTeks($d['no_do']), $status($n),
            ];
        }
        if ($input['biaya_transfer']) {
            $n = $mulai + count($baris);
            $baris[] = ['', $tgl, '', '', '', '', 'Biaya Transfer', (int) $input['nominal_biaya'], 'Biaya Transfer', '', '', '', $status($n)];
        }

        return $baris;
    }

    /**
     * Satu kali baca kolom A..H: baris data terakhir dan nomor ID UJ terbesar.
     *
     * @return array{0: int, 1: int}
     */
    private function ujung(): array
    {
        $l = KasSeabank::LEMBAR;
        [$akhir, $max] = [1, 0];
        foreach ($this->sheets->nilaiMentah($this->id, ["{$l}!A2:H"])["{$l}!A2:H"] as $i => $r) {
            if (KasSeabank::adaData($r)) {
                $akhir = $i + 2;
            }
            $max = max($max, KasSeabank::noUj((string) ($r[0] ?? '')) ?? 0);
        }

        return [$akhir, $max];
    }

    /** sheetId & jumlah baris grid lembar Kas Seabank (disimpan, supaya tidak membaca info spreadsheet tiap simpan). */
    private function grid(): array
    {
        return Cache::rememberForever("uj-grid-{$this->id}", function () {
            $p = collect($this->sheets->info($this->id)['sheets'])->firstWhere('properties.title', KasSeabank::LEMBAR)['properties']
                ?? throw new RuntimeException('Lembar "'.KasSeabank::LEMBAR.'" tidak ditemukan.');

            return ['sheetId' => $p['sheetId'], 'rowCount' => $p['gridProperties']['rowCount']];
        });
    }

    /**
     * Permintaan updateCells: nilai A..M, format angka B (tanggal), E (Rp), H (#,##0) seperti baris buatan admin,
     * dan (saat edit) kolom Q Bon dikosongkan.
     */
    private function isiSel(int $sheetId, int $mulai, array $baris, bool $kosongkanBon): array
    {
        $range = fn (int $k1, int $k2) => ['sheetId' => $sheetId, 'startRowIndex' => $mulai - 1, 'endRowIndex' => $mulai - 1 + count($baris), 'startColumnIndex' => $k1, 'endColumnIndex' => $k2];
        $format = [1 => ['type' => 'DATE', 'pattern' => '[$-409]d\-mmm\-yy'], 4 => ['type' => 'NUMBER', 'pattern' => '_-"Rp"* #,##0_-;\-"Rp"* #,##0_-;_-"Rp"* "-"??_-;_-@'], 7 => ['type' => 'NUMBER', 'pattern' => '#,##0']];
        $requests = [
            ['updateCells' => ['range' => $range(0, 13), 'fields' => 'userEnteredValue',
                'rows' => array_map(fn ($r) => ['values' => array_map(fn ($k) => self::sel($r[$k] ?? '', $k), range(0, 12))], $baris)]],
            ['updateCells' => ['range' => $range(1, 8), 'fields' => 'userEnteredFormat.numberFormat',
                'rows' => array_fill(0, count($baris), ['values' => array_map(fn ($k) => isset($format[$k]) ? ['userEnteredFormat' => ['numberFormat' => $format[$k]]] : (object) [], range(1, 7))])]],
        ];
        if ($kosongkanBon) {
            $requests[] = ['updateCells' => ['range' => $range(KasSeabank::KOLOM_BON, KasSeabank::KOLOM_BON + 1), 'fields' => 'userEnteredValue,chipRuns',
                'rows' => array_fill(0, count($baris), ['values' => [(object) []]])]];
        }

        return $requests;
    }

    /** Satu sel seperti diketik admin: rumus, angka (tanggal = nomor seri), atau teks apa adanya. */
    private static function sel(mixed $v, int $kolom): object|array
    {
        if ($v === '' || $v === null) {
            return (object) [];
        }
        if ($kolom === 1) {
            return ['userEnteredValue' => ['numberValue' => (int) \Carbon\Carbon::create(1899, 12, 30)->diffInDays(\Carbon\Carbon::parse($v))]];
        }
        if (is_int($v) || is_float($v)) {
            return ['userEnteredValue' => ['numberValue' => $v]];
        }
        if (str_starts_with($v, '=')) {
            return ['userEnteredValue' => ['formulaValue' => $v]];
        }
        if (str_starts_with($v, "'")) {
            return ['userEnteredValue' => ['stringValue' => substr($v, 1)]];
        }
        if (ctype_digit($v) && strlen($v) <= 15) {
            return ['userEnteredValue' => ['numberValue' => (int) $v]];
        }

        return ['userEnteredValue' => ['stringValue' => $v]];
    }

    /** Baris yang baru ditulis dalam bentuk seperti dibaca dari sheet, untuk memperbarui data aplikasi tanpa membaca ulang. */
    private static function keMentah(array $baris): array
    {
        return array_map(fn ($r) => array_map(fn ($v) => is_string($v) ? (str_starts_with($v, '=') ? 'Belum Reimburse' : ltrim($v, "'")) : $v, $r), $baris);
    }

    /** Nomor rekening / No DO: angka biasa tetap angka (seperti diketik admin), nol di depan dipertahankan sebagai teks. */
    private static function angkaTeks(?string $v): string
    {
        $v = trim((string) $v);

        return $v !== '' && ($v[0] === '0' || ! ctype_digit($v)) ? "'".$v : $v;
    }

    /** Teks diawali =, +, - atau @ akan dibaca sheet sebagai rumus; awali tanda kutip supaya tetap teks. */
    private static function teks(?string $v): string
    {
        $v = trim((string) $v);

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
    }
}
