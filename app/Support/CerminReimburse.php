<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasReimburseUj;
use App\Models\KasTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Lembar "Mutasi Reimburse" (spreadsheet "(0) Transaksi Belum Reimburse V3") = cermin Kas Harian: satu baris per transaksi
 * detail / baris biaya transfer / uang masuk, dicari lewat kolom B (ID Transaksi Kas, mis. 261005-Jago-31212).
 *
 * Kolom yang diisi aplikasi: A No (NO ID), B ID, D & E tanggal, F PIC, G Keterangan, M Kode GL,
 * N Bon (chip folder Drive bila berfoto), O Debit, P Kredit; Q Saldo & R Reimburse berupa rumus.
 * Kolom C, H–L (data uang jalan/ritasi) diisi admin dan tidak pernah disentuh; E juga tidak ditimpa saat edit.
 * Transaksi yang sudah direimburse (status milik aplikasi, StatusReimburse) tidak boleh diubah/dihapus.
 */
class CerminReimburse
{
    public const LEMBAR = 'Mutasi Reimburse';


    private string $id;

    public function __construct(private GoogleSheets $sheets, ?string $spreadsheetId = null)
    {
        $this->id = $spreadsheetId ?? config('mnp.sheet_reimburse');
    }

    public static function wajib(): self
    {
        return new self(GoogleSheets::wajib());
    }

    /**
     * Sesaat setelah halaman terkirim (data Kas Harian sudah diimpor ulang): tambahkan baris transfer ber-NO ID ini.
     *
     * @param  int[]  $noIds  NO ID baris-baris yang baru ditulis di Kas Harian
     */
    public static function tambahSegera(array $noIds): void
    {
        dispatch(fn () => rescue(fn () => self::wajib()->tambahYangBelum(KasTransfer::whereIn('no_id', $noIds)->get())))->afterResponse();
    }

    /** Sesaat setelah halaman terkirim: baris lama transaksi yang diedit diganti baris barunya. */
    public static function gantiSegera(array $idLama, array $noIdsBaru): void
    {
        dispatch(fn () => rescue(function () use ($idLama, $noIdsBaru) {
            $c = self::wajib();
            $baru = KasTransfer::whereIn('no_id', $noIdsBaru)->get();
            $c->ganti($idLama, $baru);
            $c->tambahYangBelum($baru);
        }))->afterResponse();
    }

    public static function hapusSegera(array $ids): void
    {
        dispatch(fn () => rescue(fn () => self::wajib()->hapus($ids)))->afterResponse();
    }

    /** ID Transaksi Kas setiap baris milik transfer ini (detail, atau baris transfer itu sendiri bila tanpa detail). */
    public static function idMilik(KasTransfer $t): array
    {
        $t->loadMissing('bon');
        if ($t->bon->isEmpty()) {
            return [self::idTransfer($t)];
        }

        return $t->bon->map(fn (KasBon $b) => trim((string) $b->id_transaksi) ?: self::idTransfer($t, $b->no_id))->all();
    }

    /**
     * Tambahkan baris transfer-transfer ini yang belum ada di Mutasi Reimburse, di bawah data terakhir (urut NO ID).
     *
     * @param  iterable<KasTransfer>  $transfer
     * @return int jumlah baris ditambahkan
     */
    public function tambahYangBelum(iterable $transfer): int
    {
        return Cache::lock('tulis-reimburse', 120)->block(60, function () use ($transfer) {
            $peta = $this->peta();
            $baru = collect($transfer)->flatMap(fn (KasTransfer $t) => self::baris($t))
                ->reject(fn ($r) => isset($peta['baris'][$r['id']]))->unique('id')
                ->sortBy(fn ($r) => [(int) $r['no'], $r['id']])->values();
            if ($baru->isEmpty()) {
                return 0;
            }
            $mulai = $peta['akhir'] + 1;
            $data = [];
            foreach ($baru as $i => $r) {
                $data += $this->dataBaris($mulai + $i, $r, true);
            }
            $this->siapkanBaris($mulai + $baru->count());
            // Format (tanggal d-mmm-yy, angka #,##0, dll.) mengikuti baris data terakhir.
            $sheetId = $this->sheetId();
            $this->sheets->permintaan($this->id, [['copyPaste' => [
                'source' => ['sheetId' => $sheetId, 'startRowIndex' => $peta['akhir'] - 1, 'endRowIndex' => $peta['akhir'], 'startColumnIndex' => 0, 'endColumnIndex' => 18],
                'destination' => ['sheetId' => $sheetId, 'startRowIndex' => $mulai - 1, 'endRowIndex' => $mulai - 1 + $baru->count(), 'startColumnIndex' => 0, 'endColumnIndex' => 18],
                'pasteType' => 'PASTE_FORMAT',
            ]]], 'menyalin format baris Mutasi Reimburse');
            $this->sheets->tulis($this->id, $data);
            $this->tulisChip($baru, $mulai);
            // Transaksi ber-NO ID lebih kecil dari data terakhir (diinput belakangan) → urutkan ulang supaya saldo berurutan.
            if ((int) $baru->first()['no'] < $peta['no_akhir']) {
                $this->urutkanTanpaKunci();
            }

            return $baru->count();
        });
    }

    /**
     * Urutkan baris data menurut NO (kolom A) lalu ID (B), mulai dari baris pertama yang tidak urut (sesudah baris saldo awal),
     * lalu rantai saldo Q (=Q atas + O − P) & rumus status R ditulis ulang. Seluruh baris ikut pindah (kolom admin, chip Bon).
     *
     * @return array{dari: int, sampai: int}|null  null bila sudah urut
     */
    public function urutkan(): ?array
    {
        return Cache::lock('tulis-reimburse', 300)->block(120, fn () => $this->urutkanTanpaKunci());
    }

    private function urutkanTanpaKunci(): ?array
    {
        $l = self::LEMBAR;
        $ab = $this->sheets->nilaiMentah($this->id, ["{$l}!A:B"])["{$l}!A:B"];
        $q = $this->sheets->nilai($this->id, ["{$l}!Q:Q"], 'FORMULA')["{$l}!Q:Q"];
        // baris saldo awal = baris pertama yang kolom Q-nya angka (bukan rumus); sebelum itu riwayat lama tanpa saldo
        $awal = null;
        foreach ($q as $i => $r) {
            $v = $r[0] ?? '';
            if ($i > 0 && $v !== '' && is_numeric($v)) {
                $awal = $i + 1;
                break;
            }
        }
        $baris = [];
        foreach ($ab as $i => $r) {
            if ($i + 1 > ($awal ?? 1) && trim((string) ($r[1] ?? '')) !== '') {
                $baris[$i + 1] = [(int) ($r[0] ?? 0), (string) $r[1]];
            }
        }
        if (! $baris) {
            return null;
        }
        $urut = $baris;
        uasort($urut, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $dari = null;
        foreach (array_map(null, array_keys($baris), array_values($baris), array_values($urut)) as [$n, $x, $y]) {
            if ($x !== $y) {
                $dari = $n;
                break;
            }
        }
        if ($dari === null) {
            return null;
        }
        $sampai = max(array_keys($baris));
        $this->sheets->permintaan($this->id, [['sortRange' => [
            'range' => ['sheetId' => $this->sheetId(), 'startRowIndex' => $dari - 1, 'endRowIndex' => $sampai, 'startColumnIndex' => 0, 'endColumnIndex' => 18],
            'sortSpecs' => [['dimensionIndex' => 0, 'sortOrder' => 'ASCENDING'], ['dimensionIndex' => 1, 'sortOrder' => 'ASCENDING']],
        ]]], 'mengurutkan Mutasi Reimburse menurut NO ID');
        // R yang diketik manual (bukan rumus, mis. "Sudah") dipertahankan; rumus R disesuaikan ke barisnya yang baru.
        $r = $this->sheets->nilai($this->id, ["{$l}!R{$dari}:R{$sampai}"], 'FORMULA')["{$l}!R{$dari}:R{$sampai}"];
        $rumus = [];
        for ($n = $dari; $n <= $sampai; $n++) {
            $lama = (string) ($r[$n - $dari][0] ?? '');
            $rumus[] = ["=Q".($n - 1)."+O{$n}-P{$n}", $lama !== '' && ! str_starts_with($lama, '=') ? $lama
                : "=IF(P{$n}>0;IF(ISNA(XLOOKUP(B{$n};'Sudah Reimburse'!B:B;'Sudah Reimburse'!D:D));\"Belum\";\"Sudah\");\"Saldo Masuk\")"];
        }
        $this->sheets->tulis($this->id, ["{$l}!Q{$dari}:R{$sampai}" => $rumus]);

        return ['dari' => $dari, 'sampai' => $sampai];
    }

    /**
     * Transaksi diedit: baris lama (dicari lewat ID) diganti baris baru di tempat yang sama. Baris berpasangan ditimpa
     * (kecuali kolom admin C, E, H–L), kelebihan baris baru disisip setelah baris lama terakhir, kelebihan baris lama dihapus.
     *
     * @param  string[]  $idLama
     * @param  iterable<KasTransfer>  $transferBaru
     */
    public function ganti(array $idLama, iterable $transferBaru): void
    {
        Cache::lock('tulis-reimburse', 120)->block(60, function () use ($idLama, $transferBaru) {
            $peta = $this->peta();
            $pos = collect($idLama)->map(fn ($i) => $peta['baris'][$i] ?? null)->filter()->unique()->sort()->values();
            $baru = collect($transferBaru)->flatMap(fn (KasTransfer $t) => self::baris($t))->unique('id')->values();
            if ($pos->isEmpty()) {
                return; // belum pernah dicerminkan → ditambahkan oleh tambahYangBelum()
            }
            if ($sudah = array_keys(StatusReimburse::untuk($idLama))) {
                throw new RuntimeException('Mutasi Reimburse tidak diubah: baris '.implode(', ', $sudah).' sudah direimburse.');
            }
            $sheetId = $this->sheetId();
            $k = min($pos->count(), $baru->count());
            $akhirLama = $pos->last();
            $requests = [];
            if ($baru->count() > $pos->count()) {
                $requests[] = ['insertDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $akhirLama, 'endIndex' => $akhirLama + $baru->count() - $k], 'inheritFromBefore' => true]];
            }
            foreach ($pos->slice($k)->sortDesc() as $p) {
                $requests[] = ['deleteDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $p - 1, 'endIndex' => $p]]];
            }
            $this->sheets->permintaan($this->id, $requests, 'menyusun ulang baris Mutasi Reimburse');

            $data = [];
            $tulis = collect();
            foreach ($baru as $i => $r) {
                $n = $i < $k ? $pos[$i] : $akhirLama + 1 + ($i - $k);
                $data += $this->dataBaris($n, $r, $i >= $k);
                $tulis[$n] = $r;
            }
            // Rantai saldo (Q = Q baris atas + O − P) disambung ulang di seluruh rentang yang tergeser.
            $akhirBaru = $akhirLama + $baru->count() - $pos->count();
            for ($n = $pos->first(); $n <= $akhirBaru + 1; $n++) {
                $data[self::LEMBAR."!Q{$n}"] = [["=Q".($n - 1)."+O{$n}-P{$n}"]];
            }
            $this->sheets->tulis($this->id, $data);
            $this->tulisChip($tulis, null);
        });
    }

    /**
     * Transaksi dihapus: baris-barisnya ikut dihapus dan rantai saldo disambung.
     *
     * @param  string[]  $ids
     */
    public function hapus(array $ids): int
    {
        return Cache::lock('tulis-reimburse', 120)->block(60, function () use ($ids) {
            $peta = $this->peta();
            $pos = collect($ids)->map(fn ($i) => $peta['baris'][$i] ?? null)->filter()->unique()->sortDesc()->values();
            if ($pos->isEmpty()) {
                return 0;
            }
            if ($sudah = array_keys(StatusReimburse::untuk($ids))) {
                throw new RuntimeException('Mutasi Reimburse tidak diubah: baris '.implode(', ', $sudah).' sudah direimburse.');
            }
            $sheetId = $this->sheetId();
            $this->sheets->permintaan($this->id, $pos->map(fn ($p) => ['deleteDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $p - 1, 'endIndex' => $p]]])->all(), 'menghapus baris Mutasi Reimburse');
            $data = [];
            for ($n = $pos->min(); $n <= $pos->max() - $pos->count() + 1; $n++) {
                $data[self::LEMBAR."!Q{$n}"] = [["=Q".($n - 1)."+O{$n}-P{$n}"]];
            }
            $this->sheets->tulis($this->id, $data);

            return $pos->count();
        });
    }

    /**
     * Kolom N (Bon) baris-baris ini dijadikan chip folder Drive (dipanggil setelah Kas Harian diberi chip).
     *
     * @param  array<string, string>  $idKeLink
     */
    public function chip(array $idKeLink): int
    {
        return Cache::lock('tulis-reimburse', 120)->block(60, function () use ($idKeLink) {
            $peta = $this->peta();
            $sel = [];
            foreach ($idKeLink as $id => $link) {
                if ($n = $peta['baris'][$id] ?? null) {
                    $sel[$n] = $link;
                }
            }
            if ($sel) {
                $this->sheets->chipDrive($this->id, $this->sheetId(), 13, $sel);
            }

            return count($sel);
        });
    }

    /**
     * Baris Mutasi Reimburse untuk satu transfer Kas Harian.
     *
     * @return array<int, array{id: string, no: string, sel: array<int, mixed>, link: ?string}>
     */
    public static function baris(KasTransfer $t): array
    {
        $t->loadMissing('bon.kodeGl');
        $tgl = $t->tanggal->format('Y-m-d'); // tanggal asli; tampilan d-mmm-yy mengikuti format kolom
        $folder = $t->no_id ? TautanBon::pembuat()((int) $t->no_id) : null;
        $teks = fn ($v) => self::teks($v);

        if ($t->bon->isEmpty()) {
            return [[
                'id' => self::idTransfer($t), 'no' => (string) $t->no_id, 'link' => null,
                'sel' => [0 => self::angka($t->no_id), 1 => self::idTransfer($t), 3 => $tgl, 4 => $tgl, 6 => $teks($t->keterangan),
                    14 => $t->debet ?: '', 15 => $t->kredit ?: ''],
            ]];
        }

        // Detail yang berasal dari reimburse Kas UJ: kolom data uang jalan ikut diisi (C, E, H–L).
        $uj = KasReimburseUj::whereIn('no_id', $t->bon->pluck('no_id')->filter()->map(fn ($n) => (int) $n))->get()->keyBy('no_id');

        return $t->bon->map(function (KasBon $b) use ($t, $tgl, $folder, $teks, $uj) {
            $id = trim((string) $b->id_transaksi) ?: self::idTransfer($t, $b->no_id);
            $chip = TautanBon::menunjuk($b->kode_bon, $folder) && $folder;
            $u = $uj[(int) $b->no_id] ?? null;

            return [
                'id' => $id, 'no' => (string) ($b->no_id ?: $t->no_id), 'link' => $chip ? $folder['link'] : null,
                // array_replace (bukan spread "..."), karena spread menomori ulang kunci angka.
                'sel' => array_replace([0 => self::angka($b->no_id ?: $t->no_id), 1 => $id, 3 => $tgl, 4 => $tgl, 5 => $teks($b->pic),
                    6 => $teks($b->keterangan), 12 => $teks($b->kodeGl?->kode_asli), 13 => $chip ? $folder['link'] : $teks($b->kode_bon),
                    14 => '', 15 => $b->nominal,
                ], $u ? [2 => $teks($u->id_uj), 4 => $u->tanggal_uj?->format('Y-m-d') ?? $tgl, 7 => $teks($u->jenis_kendaraan),
                    // No DO bernol depan ("0039") tetap teks; lainnya angka seperti ketikan admin.
                    8 => preg_match('/^0\d/', (string) $u->no_do) ? "'".$u->no_do : self::angka($u->no_do),
                    9 => $teks($u->galian), 10 => $teks($u->kategori), 11 => $u->tanggal_reimburse->format('Y-m-d')] : []),
            ];
        })->all();
    }

    /** Range A1 → isi untuk satu baris. $lengkap: baris baru (semua kolom + rumus); selain itu kolom admin dibiarkan. */
    private function dataBaris(int $n, array $r, bool $lengkap): array
    {
        $s = $r['sel'];
        $l = self::LEMBAR;
        if ($lengkap) {
            $baris = [];
            for ($k = 0; $k <= 15; $k++) {
                $baris[] = $s[$k] ?? '';
            }
            $baris[] = "=Q".($n - 1)."+O{$n}-P{$n}";
            $baris[] = "=IF(P{$n}>0;IF(ISNA(XLOOKUP(B{$n};'Sudah Reimburse'!B:B;'Sudah Reimburse'!D:D));\"Belum\";\"Sudah\");\"Saldo Masuk\")";

            return ["{$l}!A{$n}:R{$n}" => [$baris]];
        }

        return [
            "{$l}!A{$n}:B{$n}" => [[$s[0] ?? '', $s[1] ?? '']],
            "{$l}!D{$n}" => [[$s[3] ?? '']],
            "{$l}!F{$n}:G{$n}" => [[$s[5] ?? '', $s[6] ?? '']],
            "{$l}!M{$n}:P{$n}" => [[$s[12] ?? '', $s[13] ?? '', $s[14] ?? '', $s[15] ?? '']],
        ];
    }

    /** @param Collection<int, array> $baris  nomor baris => baris, atau urutan mulai $mulai */
    private function tulisChip(Collection $baris, ?int $mulai): void
    {
        $sel = [];
        foreach ($baris as $i => $r) {
            if ($r['link']) {
                $sel[$mulai === null ? $i : $mulai + $i] = $r['link'];
            }
        }
        if ($sel) {
            $this->sheets->chipDrive($this->id, $this->sheetId(), 13, $sel);
        }
    }

    /**
     * Posisi setiap ID di kolom B, status kolom R, dan baris data terakhir.
     *
     * @return array{baris: array<string, int>, status: array<int, string>, akhir: int}
     */
    private function peta(): array
    {
        $l = self::LEMBAR;
        $isi = $this->sheets->nilai($this->id, ["{$l}!A:B", "{$l}!R:R"]);
        $baris = [];
        $akhir = 1;
        $noAkhir = 0;
        foreach ($isi["{$l}!A:B"] as $i => $r) {
            if (($b = trim((string) ($r[1] ?? ''))) !== '' && $i > 0) {
                $baris[$b] ??= $i + 1;
                $akhir = $i + 1;
                $noAkhir = max($noAkhir, (int) preg_replace('/\D/', '', (string) ($r[0] ?? '')));
            }
        }
        $status = [];
        foreach ($isi["{$l}!R:R"] as $i => $r) {
            $status[$i + 1] = trim((string) ($r[0] ?? ''));
        }

        return ['baris' => $baris, 'status' => $status, 'akhir' => $akhir, 'no_akhir' => $noAkhir];
    }

    /** Pastikan lembar punya cukup baris (grid) sampai $sampai. */
    private function siapkanBaris(int $sampai): void
    {
        $p = collect($this->sheets->info($this->id)['sheets'])->firstWhere('properties.title', self::LEMBAR)['properties'];
        $ada = $p['gridProperties']['rowCount'];
        if ($sampai > $ada) {
            $this->sheets->permintaan($this->id, [['appendDimension' => ['sheetId' => $p['sheetId'], 'dimension' => 'ROWS', 'length' => $sampai - $ada + 200]]], 'menambah baris Mutasi Reimburse');
        }
    }

    private function sheetId(): int
    {
        return Cache::rememberForever("sheet-id-reimburse-{$this->id}", fn () => collect($this->sheets->info($this->id)['sheets'])
            ->firstWhere('properties.title', self::LEMBAR)['properties']['sheetId']
            ?? throw new RuntimeException('Lembar "'.self::LEMBAR.'" tidak ditemukan.'));
    }

    private static function idTransfer(KasTransfer $t, mixed $noId = null): string
    {
        return $t->tanggal->format('ymd').'-Jago-'.($noId ?: $t->no_id);
    }

    private static function angka(mixed $v): mixed
    {
        return is_numeric($v) ? (int) $v : (string) $v;
    }

    /** Teks diawali =, +, - atau @ akan dibaca sheet sebagai rumus; awali tanda kutip supaya tetap teks. */
    private static function teks(?string $v): string
    {
        $v = trim((string) $v);

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
    }
}
