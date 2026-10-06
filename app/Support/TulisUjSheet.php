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
        return Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($input) {
            $l = KasSeabank::LEMBAR;
            $isi = $this->sheets->nilaiMentah($this->id, ["{$l}!A1:L"])["{$l}!A1:L"];
            [$akhir, $max, $acuan] = $this->posisi($isi);

            $ids = range($max + 1, $max + count($input['detail']));
            $mulai = $akhir + 1;
            $baris = $this->susun($input, $ids, $mulai);
            $sampai = $mulai + count($baris) - 1;

            $p = $this->properti();
            $requests = [];
            if ($sampai > $p['gridProperties']['rowCount']) {
                $requests[] = ['appendDimension' => ['sheetId' => $p['sheetId'], 'dimension' => 'ROWS', 'length' => $sampai - $p['gridProperties']['rowCount'] + 200]];
            }
            // Format (tanggal, Rp, #,##0) mengikuti baris master terakhir.
            $requests[] = ['copyPaste' => [
                'source' => ['sheetId' => $p['sheetId'], 'startRowIndex' => $acuan - 1, 'endRowIndex' => $acuan, 'startColumnIndex' => 0, 'endColumnIndex' => 14],
                'destination' => ['sheetId' => $p['sheetId'], 'startRowIndex' => $mulai - 1, 'endRowIndex' => $sampai, 'startColumnIndex' => 0, 'endColumnIndex' => 14],
                'pasteType' => 'PASTE_FORMAT',
            ]];
            $this->sheets->permintaan($this->id, $requests, 'menyiapkan baris Kas Seabank');
            $this->sheets->tulis($this->id, ["{$l}!A{$mulai}:M{$sampai}" => $baris]);

            return ['baris_awal' => $mulai, 'baris_akhir' => $sampai, 'no_uj' => $ids[0], 'ids' => $ids];
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
                [, $max] = $this->posisi($this->sheets->nilaiMentah($this->id, ["{$l}!A1:L"])["{$l}!A1:L"]);
                while (count($ids) < count($input['detail'])) {
                    $ids[] = ++$max;
                }
            }
            $baris = $this->susun($input, $ids, $dari);
            $n = count($baris);
            $m = $sampai - $dari + 1;
            $sheetId = $this->properti()['sheetId'];
            if ($n > $m) {
                $this->sheets->permintaan($this->id, [['insertDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $sampai, 'endIndex' => $sampai + $n - $m], 'inheritFromBefore' => true]]], 'menyisipkan baris Kas Seabank');
            } elseif ($n < $m) {
                $this->sheets->hapusBaris($this->id, $sheetId, $dari + $n, $sampai);
            }
            $akhir = $dari + $n - 1;
            // Kolom Bon dikosongkan; chip folder ditulis ulang setelah simpan bila transaksinya berfoto.
            $this->sheets->tulis($this->id, ["{$l}!A{$dari}:M{$akhir}" => $baris, "{$l}!Q{$dari}:Q{$akhir}" => array_fill(0, $n, [''])]);

            return ['baris_awal' => $dari, 'baris_akhir' => $akhir, 'no_uj' => $ids[0], 'ids' => $ids, 'geser' => $n - $m, 'sampai_lama' => $sampai, 'sebelum' => $sebelum];
        });
    }

    /** @return array{baris_awal: int, baris_akhir: int, sebelum: array} */
    public function hapus(UjTransaksi $t): array
    {
        return Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($t) {
            [$dari, $sampai, $sebelum] = $this->periksaBlok($t);
            $this->sheets->hapusBaris($this->id, $this->properti()['sheetId'], $dari, $sampai);

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
        $adaIsi = array_filter(array_slice($sesudah, 0, 12), fn ($v) => $v !== null && $v !== '');
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
     * Baris data terakhir, nomor ID UJ terbesar, dan baris master terakhir (acuan format).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function posisi(array $isi): array
    {
        [$akhir, $max, $acuan] = [1, 0, 2];
        foreach ($isi as $i => $r) {
            if ($i === 0) {
                continue;
            }
            if (array_filter(array_slice($r, 0, 12), fn ($v) => $v !== null && $v !== '')) {
                $akhir = $i + 1;
            }
            $max = max($max, KasSeabank::noUj((string) ($r[0] ?? '')) ?? 0);
            if (trim((string) ($r[2] ?? '')) !== '' || trim((string) ($r[3] ?? '')) !== '') {
                $acuan = $i + 1;
            }
        }

        return [$akhir, $max, $acuan];
    }

    private function properti(): array
    {
        return collect($this->sheets->info($this->id)['sheets'])->firstWhere('properties.title', KasSeabank::LEMBAR)['properties']
            ?? throw new RuntimeException('Lembar "'.KasSeabank::LEMBAR.'" tidak ditemukan.');
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
