<?php

namespace App\Support;

use App\Models\JagoMutasi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Tulis satu transfer (beserta rincian bon) ke lembar bulanan Kas Bank Jago, mengikuti cara admin mengisi:
 * - baris transfer: Tanggal, Nama/No Rek/Bank, Keterangan, Debet atau Kredit; bon pertama di baris yang sama
 * - bon berikutnya di baris di bawahnya (Tanggal + kolom K..Q)
 * - Saldo (I) dan ID TRANSAKSI (R) berupa rumus, NO ID (Q) melanjutkan nomor terakhir
 * - Kode Bon: link folder foto bon di Drive bila transaksinya berfoto; selain itu TTTTBBHH-PIC-NN (+ -k bila satu transfer berisi beberapa bon PIC yang sama)
 * Baris diisi di baris kosong pertama sebelum TOTAL; bila kurang, baris disisipkan di dalam jangkauan TOTAL.
 * Transaksi dari mutasi Jago (owner 1 Nov 2026) disisipkan di posisi sesuai urutan mutasinya — sebelum transaksi pertama yang
 * tanggalnya lebih baru atau (tanggal sama) mutasinya lebih akhir — supaya urutan Kas Harian = urutan mutasi bank.
 * Lembar bulan yang belum ada dibuat otomatis dari lembar bulan sebelumnya (owner 1 Nov 2026), lihat buatLembar().
 */
class TulisKasSheet
{
    public const BIAYA_TRANSFER = 2500;

    /** Batas atas nominal baris "Biaya Transfer Keluar" (default 2.500, bisa diganti admin). */
    public const BIAYA_TRANSFER_MAKS = 100000;

    /** Baris kosong siap isi di lembar bulan baru (Okt 2026 memakai ±4.000 baris; bila kurang, baris disisipkan otomatis saat input). */
    public const BARIS_LEMBAR_BARU = 2000;

    public function __construct(private GoogleSheets $sheets, private ?string $spreadsheetId = null)
    {
        $this->spreadsheetId ??= config('mnp.sheet_kas_harian');
    }

    /**
     * @param  array{tanggal: CarbonInterface, arah: string, nama_tujuan: ?string, no_rek: ?string, bank: ?string, keterangan: ?string,
     *               nominal_masuk?: int, bon?: array<int, array{nominal: int, pic: ?string, keterangan: ?string, kode_gl: ?string}>, biaya_transfer?: bool, nominal_biaya?: int}  $input
     * @param  (callable(int): ?array)|null  $linkBon  NO ID transfer → folder foto bon di Drive yang sudah dikenal; bila ada, link-nya ditulis di kolom Kode Bon
     * @param  JagoMutasi|null  $mutasi  transfer bank asal transaksi: posisi barisnya mengikuti urutan mutasi
     * @return array{lembar: string, baris_awal: int, baris_akhir: int, no_id: int[]}
     */
    public function tulis(array $input, ?callable $linkBon = null, ?JagoMutasi $mutasi = null): array
    {
        return Cache::lock('tulis-kas-sheet', 60)->block(30, fn () => $this->tulisTerkunci($input, $linkBon, $mutasi));
    }

    private function tulisTerkunci(array $input, ?callable $linkBon, ?JagoMutasi $mutasi = null): array
    {
        $tanggal = $input['tanggal'];
        $lembar = $tanggal->format('my');
        $info = collect($this->sheets->info($this->spreadsheetId)['sheets'])->firstWhere('properties.title', $lembar);
        $sheetId = $info ? $info['properties']['sheetId'] : $this->buatLembar($tanggal, $lembar);

        $nilai = $this->sheets->nilai($this->spreadsheetId, [$lembar])[$lembar];
        [$judul, $total, $terakhir] = $this->posisi($nilai, $lembar);

        $noId = $this->noIdBerikut();
        $input['link_bon'] = $linkBon && $input['arah'] !== 'masuk' ? ($linkBon($noId)['link'] ?? null) : null;
        $baris = $this->susunBaris($input, $nilai, $judul, $total);
        $butuh = count($baris);
        $mulai = $terakhir + 1;
        $sisip = $mutasi ? self::barisMenurutMutasi($nilai, $judul, $terakhir, $mutasi) : null;

        if ($sisip !== null) {
            // Di tengah data: sisipkan baris baru; rumus saldo transaksi sesudahnya disambungkan ulang oleh dataBlok.
            $mulai = $sisip;
            $this->sheets->sisipBaris($this->spreadsheetId, $sheetId, $mulai, $butuh);
            $total += $butuh;
        } elseif (($kosong = $total - $mulai) < $butuh + 1) {
            // Sisakan minimal satu baris kosong di atas TOTAL supaya jangkauan SUM tetap ikut melebar.
            if ($kosong < 1) {
                throw new RuntimeException("Tidak ada baris kosong di atas TOTAL pada lembar {$lembar}. Tambahkan beberapa baris kosong di atas baris TOTAL, lalu simpan lagi.");
            }
            $this->sheets->sisipBaris($this->spreadsheetId, $sheetId, $mulai, $butuh);
            $total += $butuh;
        }

        $daftarNoId = range($noId, $noId + $butuh - 1);
        $this->sheets->tulis($this->spreadsheetId, $this->dataBlok($lembar, $baris, $mulai, $daftarNoId, $total, self::barisSaldoSebelum($nilai, $mulai, $judul)) + $this->kepalaNoMobil($lembar, $nilai, $judul));

        return ['lembar' => $lembar, 'baris_awal' => $mulai, 'baris_akhir' => $mulai + $butuh - 1, 'no_id' => $daftarNoId];
    }

    /**
     * Buat lembar bulan baru (owner 1 Nov 2026: admin tidak perlu menyalin lembar tiap awal bulan): gandakan lembar bulan sebelumnya
     * (format, lebar kolom, judul, baris beku ikut), buang baris transaksinya, sisakan BARIS_LEMBAR_BARU baris kosong berumus saldo,
     * lalu isi judul "Per [akhir bulan]", baris Saldo Awal bertanggal 1 = rumus ke Saldo baris TOTAL bulan sebelumnya, dan rumus TOTAL.
     *
     * @return int sheetId lembar baru
     */
    private function buatLembar(CarbonInterface $tanggal, string $lembar): int
    {
        $awal = \Carbon\Carbon::parse($tanggal->format('Y-m-01'));
        $sebelum = $awal->copy()->subMonth()->format('my');
        $asal = collect($this->sheets->info($this->spreadsheetId)['sheets'])->firstWhere('properties.title', $sebelum)
            ?? throw new RuntimeException("Lembar {$lembar} belum ada, dan lembar bulan sebelumnya ({$sebelum}) juga tidak ditemukan untuk disalin. Buat lembar {$lembar} manual, lalu simpan lagi.");
        $nilai = $this->sheets->nilai($this->spreadsheetId, [$sebelum])[$sebelum];
        [$judul, $total] = $this->posisi($nilai, $sebelum);
        $saldoAwal = $judul + 1;
        if (strcasecmp(trim((string) ($nilai[$saldoAwal - 1][5] ?? '')), 'Saldo Awal') !== 0) {
            throw new RuntimeException("Baris \"Saldo Awal\" tidak ada tepat di bawah judul kolom lembar {$sebelum}; lembar {$lembar} tidak bisa dibuat otomatis. Buat manual, lalu simpan lagi.");
        }

        $sheetId = $this->sheets->duplikatLembar($this->spreadsheetId, $asal['properties']['sheetId'], $lembar, $asal['properties']['index'] + 1);
        // Baris transaksi pertama dipertahankan sebagai contoh format baris kosong; sisanya dibuang, lalu baris kosong disisipkan di bawahnya.
        $contoh = $saldoAwal + 1;
        $n = self::BARIS_LEMBAR_BARU;
        $permintaan = [];
        if ($total - 1 > $contoh) {
            $permintaan[] = ['deleteDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $contoh, 'endIndex' => $total - 1]]];
        }
        $permintaan[] = ['insertDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $contoh, 'endIndex' => $contoh + $n - 1], 'inheritFromBefore' => true]];
        $this->sheets->permintaan($this->spreadsheetId, $permintaan, 'menyiapkan baris lembar baru');

        $totalBaru = $contoh + $n;
        $kosong = [];
        for ($r = $contoh; $r < $totalBaru; $r++) {
            $kosong[] = array_replace(array_fill(0, 18, ''), [7 => "=I".($r - 1)."+G{$r}-H{$r}"]);
        }
        $data = [
            "{$lembar}!B4" => [['Per '.$awal->copy()->endOfMonth()->translatedFormat('j F Y')]],
            "{$lembar}!B{$saldoAwal}:I{$saldoAwal}" => [[$awal->toDateString(), '', '', '', 'Saldo Awal', "='{$sebelum}'!I{$total}", '-', "=G{$saldoAwal}"]],
            "{$lembar}!J{$saldoAwal}:S{$saldoAwal}" => [array_fill(0, 10, '')],
            "{$lembar}!B{$contoh}:S".($totalBaru - 1) => $kosong,
            "{$lembar}!G{$totalBaru}:I{$totalBaru}" => [["=SUM(G{$saldoAwal}:INDEX(G:G;ROW()-1))", "=SUM(H{$saldoAwal}:INDEX(H:H;ROW()-1))", "=G{$totalBaru}-H{$totalBaru}"]],
        ];
        // Sel pemeriksa admin di bawah TOTAL (mis. =SUM(K7:K2283)): jangkauannya disamakan dengan baris data lembar baru.
        $bawah = $this->sheets->nilai($this->spreadsheetId, ["{$lembar}!A{$totalBaru}:S".($totalBaru + 15)], 'FORMULA')["{$lembar}!A{$totalBaru}:S".($totalBaru + 15)] ?? [];
        foreach ($bawah as $i => $r) {
            foreach ($r as $k => $v) {
                if ($i > 0 && is_string($v) && preg_match('/SUM\(([A-Z]+)\d+:\1\d+\)/', $v)) {
                    $data["{$lembar}!".chr(65 + $k).($totalBaru + $i)] = [[preg_replace('/SUM\(([A-Z]+)\d+:\1\d+\)/', 'SUM(${1}'.$saldoAwal.':${1}'.($totalBaru - 1).')', $v)]];
                }
            }
        }
        $this->sheets->tulis($this->spreadsheetId, $data);

        return $sheetId;
    }

    /**
     * Ubah transaksi yang sudah ada (beserta baris "Biaya Transfer Keluar" miliknya) di tempat yang sama.
     * NO ID & nomor Kode Bon lama dipakai lagi; baris tambahan mendapat NO ID lanjutan sheet.
     * Bila tanggal pindah bulan: dihapus dari lembar lama lalu ditulis di lembar bulan baru.
     *
     * @param  (callable(int): ?array)|null  $linkBon  NO ID transfer → folder foto bon di Drive; bila ada, link-nya tetap di kolom Kode Bon
     * @return array{lembar: string, lembar_lama: string, baris_awal: int, baris_akhir: int, no_id: int[], sebelum: array}
     */
    public function ubah(\App\Models\KasTransfer $t, array $input, ?callable $linkBon = null): array
    {
        $t->loadMissing('bon', 'kasBulan');
        $lembarLama = $t->kasBulan->lembar;
        $biaya = HapusKasSheet::biayaTransferMilik($t);

        if ($input['tanggal']->format('my') !== $lembarLama) {
            $hapus = (new HapusKasSheet($this->sheets, $this->spreadsheetId))->hapus($t, (bool) $biaya);
            $tulis = $this->tulis($input, $linkBon);

            return [...$tulis, 'lembar_lama' => $lembarLama, 'sebelum' => $hapus['isi']];
        }

        return Cache::lock('tulis-kas-sheet', 60)->block(30, function () use ($t, $input, $lembarLama, $biaya, $linkBon) {
            $lembar = $lembarLama;
            $sheetId = collect($this->sheets->info($this->spreadsheetId)['sheets'])->firstWhere('properties.title', $lembar)['properties']['sheetId']
                ?? throw new RuntimeException("Lembar {$lembar} tidak ditemukan di sheet.");
            $nilai = $this->sheets->nilai($this->spreadsheetId, [$lembar])[$lembar];
            [$judul, $total] = $this->posisi($nilai, $lembar);

            $blok = array_filter([$t, $biaya]);
            $dari = $t->baris;
            $sampai = max(array_map(fn ($x) => max($x->baris, (int) $x->bon->max('baris')), $blok));
            if ($sampai >= $total) {
                throw new RuntimeException("Transaksi ini tidak berada di atas baris TOTAL lembar {$lembar}.");
            }
            $sebelum = array_slice($nilai, $dari - 1, $sampai - $dari + 2);
            HapusKasSheet::cocokkan($blok, $sebelum, $dari, $sampai);

            // Nomor Kode Bon lama per PIC (tanggal sama) dipakai lagi supaya kodenya tidak berubah.
            $nnTetap = [];
            foreach (array_slice($sebelum, 0, $sampai - $dari + 1) as $r) {
                if (preg_match('/^'.$input['tanggal']->format('Ymd').'-(.+)-(\d+)(?:-\d+)?$/', trim((string) ($r[15] ?? '')), $m)) {
                    $nnTetap[strtolower($m[1])] ??= (int) $m[2];
                }
            }
            $folder = $linkBon && $input['arah'] !== 'masuk' ? $linkBon($t->no_id) : null;
            $input['link_bon'] = $folder['link'] ?? null;
            // Link hanya di detail pertama (detail berikutnya tetap kode biasa) → pertahankan cara itu.
            $input['link_hanya_pertama'] = TautanBon::menunjuk($sebelum[0][15] ?? '', $folder)
                && trim((string) ($sebelum[1][15] ?? '')) !== '' && ! TautanBon::menunjuk($sebelum[1][15] ?? '', $folder);
            $baris = $this->susunBaris($input, $nilai, $judul, $total, range($dari, $sampai), $nnTetap);
            $n = count($baris);
            $m = $sampai - $dari + 1;

            $idLama = array_values(array_filter(array_map(fn ($r) => self::angkaNoId($r[16] ?? ''), array_slice($sebelum, 0, $m))));
            $ids = array_slice($idLama, 0, $n);
            if (count($ids) < $n) {
                $berikut = $this->noIdBerikut();
                while (count($ids) < $n) {
                    $ids[] = $berikut++;
                }
            }

            // Sisip di dalam blok (sebelum baris terakhirnya) supaya jangkauan SUM baris TOTAL ikut melebar.
            if ($n > $m) {
                $this->sheets->sisipBaris($this->spreadsheetId, $sheetId, $sampai, $n - $m);
            } elseif ($n < $m) {
                $this->sheets->hapusBaris($this->spreadsheetId, $sheetId, $dari + $n, $sampai);
            }
            $total += $n - $m;

            $this->sheets->tulis($this->spreadsheetId, $this->dataBlok($lembar, $baris, $dari, $ids, $total, self::barisSaldoSebelum($nilai, $dari, $judul)) + $this->kepalaNoMobil($lembar, $nilai, $judul));

            return ['lembar' => $lembar, 'lembar_lama' => $lembarLama, 'baris_awal' => $dari, 'baris_akhir' => $dari + $n - 1, 'no_id' => $ids,
                'sebelum' => array_slice($sebelum, 0, $m)];
        });
    }

    /**
     * Isi sel B..R untuk blok baris mulai $mulai + sambungan saldo baris sesudahnya. Mengikuti cara admin: rumus Saldo (I)
     * hanya di baris yang punya Debet/Kredit (transfer & biaya transfer), merujuk baris saldo terakhir di atasnya;
     * baris transaksi detail dibiarkan kosong.
     *
     * @param  int  $saldoSebelum  nomor baris terakhir di atas blok yang kolom Saldo-nya terisi
     */
    private function dataBlok(string $lembar, array $baris, int $mulai, array $noId, int $total, int $saldoSebelum): array
    {
        $data = [];
        $acuan = $saldoSebelum;
        foreach ($baris as $i => $sel) {
            $n = $mulai + $i;
            if (! empty($sel[6]) || ! empty($sel[7])) {
                $sel[8] = "=I{$acuan}+G{$n}-H{$n}";
                $acuan = $n;
            }
            $sel[16] = $noId[$i];
            $sel[17] = '=CONCATENATE(TEXT(B'.$n.';"yymmdd");"-Jago-";Q'.$n.')';
            // B..S (S = NO MOBIL, nomor truk detail).
            $data["{$lembar}!B{$n}:S{$n}"] = [array_values(array_slice(array_replace(array_fill(1, 18, ''), $sel), 0, 18))];
        }
        // Baris pertama setelah blok (transfer berikutnya atau baris kosong siap isi): saldonya menyambung ke saldo terakhir blok.
        $setelah = $mulai + count($baris);
        if ($setelah < $total) {
            $data["{$lembar}!I{$setelah}"] = [["=I{$acuan}+G{$setelah}-H{$setelah}"]];
        }

        return $data;
    }

    /** Judul kolom S "NO MOBIL" di baris judul lembar, bila belum ada. */
    private function kepalaNoMobil(string $lembar, array $nilai, int $judul): array
    {
        return trim((string) ($nilai[$judul - 1][18] ?? '')) === '' ? ["{$lembar}!S{$judul}" => [['NO MOBIL']]] : [];
    }

    /**
     * Baris tempat transaksi dari $mutasi disisipkan: awal transaksi pertama di lembar yang tanggalnya lebih baru, atau bertanggal sama
     * dan tertaut ke mutasi Jago yang urutannya (rantai saldo bank) sesudah $mutasi. null = tidak ada → ditulis di bawah seperti biasa.
     * Baris "Biaya Transfer Keluar" ikut transaksi di atasnya; transaksi bertanggal sama tanpa tautan mutasi dilewati.
     */
    public static function barisMenurutMutasi(array $nilai, int $judul, int $terakhir, JagoMutasi $mutasi): ?int
    {
        $urut = collect(MutasiJago::validitas($mutasi->kantong)['urut'])->values()->pluck('id')->flip();
        $ke = $urut[$mutasi->id] ?? null;
        if ($ke === null) {
            return null;
        }
        $tgl = $mutasi->waktu->toDateString();
        $posNoId = JagoMutasi::where('kantong', $mutasi->kantong)->where('dipakai', 'like', 'kas:%')->get(['id', 'dipakai'])
            ->mapWithKeys(fn ($m) => [(int) substr($m->dipakai, 4) => $urut[$m->id] ?? null])->filter(fn ($v) => $v !== null);
        for ($r = $judul + 1; $r <= $terakhir; $r++) {
            $sel = $nilai[$r - 1] ?? [];
            if ((trim((string) ($sel[6] ?? '')) === '' && trim((string) ($sel[7] ?? '')) === '') || preg_match('/^\s*biaya transfer/i', (string) ($sel[5] ?? ''))) {
                continue;
            }
            $d = self::tanggalSel($sel[1] ?? '');
            if (! $d) {
                continue;
            }
            $d = $d->toDateString();
            $posisi = $posNoId[self::angkaNoId($sel[16] ?? '') ?? 0] ?? null;
            if ($d > $tgl || ($d === $tgl && $posisi !== null && $posisi > $ke)) {
                return $r;
            }
        }

        return null;
    }

    /** Tanggal di kolom B lembar Kas ("31-Okt-26", "31/10/2026", "2026-10-31"). */
    private static function tanggalSel(mixed $v): ?CarbonInterface
    {
        if ($d = KasSeabank::tanggal($v)) {
            return $d;
        }
        try {
            return \Carbon\Carbon::createFromFormat('!d/m/Y', trim((string) $v));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Nomor baris terakhir di atas $baris yang kolom Saldo (I) terisi — baris detail buatan admin dikosongkan. */
    public static function barisSaldoSebelum(array $nilai, int $baris, int $judul): int
    {
        for ($r = $baris - 1; $r > $judul; $r--) {
            if (trim((string) ($nilai[$r - 1][8] ?? '')) !== '') {
                return $r;
            }
        }

        return $judul + 1;
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
            ->filter(fn ($t) => preg_match('/^\d{4}$/', $t))
            ->sortBy(fn ($t) => substr($t, 2).substr($t, 0, 2))->values();
        $nilai = $this->sheets->nilai($this->spreadsheetId, $lembar->map(fn ($t) => "{$t}!Q:Q")->all());
        $maks = collect($nilai)->flatten()->map(fn ($v) => self::angkaNoId($v))->filter()->max();
        if (! $maks) {
            throw new RuntimeException('NO ID terakhir tidak ditemukan di lembar bulanan mana pun.');
        }

        // Pengaman: NO ID terbesar harus menyambung urutan di lembar terbaru, bukan angka yang melonjak (salah ketik).
        $terbaru = $lembar->last();
        $urutan = collect($nilai["{$terbaru}!Q:Q"] ?? [])->flatten()->map(fn ($v) => self::angkaNoId($v))->filter()->values();
        $median = $urutan->isEmpty() ? $maks : $urutan->sort()->values()[intdiv($urutan->count(), 2)];
        if ($maks - $median > 5000) {
            throw new RuntimeException("NO ID terbesar di sheet ({$maks}) jauh melompat dari urutan lembar {$terbaru}. Periksa kolom NO ID yang salah ketik, lalu simpan lagi.");
        }

        return $maks + 1;
    }

    /** "30959" / "30.959" → 30959; "27293-1" (satu NO ID dipecah jadi beberapa baris) → 27293; kosong/teks → null. */
    public static function angkaNoId(mixed $v): ?int
    {
        return preg_match('/^\s*(\d{1,3}(?:[.,]\d{3})+|\d+)/', (string) $v, $m) ? (int) str_replace(['.', ','], '', $m[1]) : null;
    }

    /** @return array<int, array<int, mixed>> per baris: indeks kolom (B=1 … R=17) => isi */
    private function susunBaris(array $input, array $nilai, int $judul, int $total, array $kecualiBaris = [], array $nnTetap = []): array
    {
        $tgl = $input['tanggal']->format('Y-m-d');
        $teks = fn (?string $v) => self::teks($v);
        $kepala = [1 => $tgl, 2 => $teks($input['nama_tujuan']), 3 => $input['no_rek'] ? "'".$input['no_rek'] : '', 4 => $teks($input['bank']), 5 => $teks($input['keterangan'])];

        if ($input['arah'] === 'masuk') {
            return [$kepala + [6 => (int) $input['nominal_masuk'], 14 => $teks($input['kode_gl_masuk'] ?? null)]];
        }

        $bon = array_values($input['bon']);
        $jumlahBon = array_sum(array_column($bon, 'nominal'));
        if (! $bon || (isset($input['nominal_transfer']) && (int) $input['nominal_transfer'] !== $jumlahBon)) {
            throw new RuntimeException('Jumlah transaksi detail '.rp($jumlahBon).' tidak sama dengan nominal transfer '.rp((int) ($input['nominal_transfer'] ?? 0)).'; tidak ditulis.');
        }
        $kodeBon = $this->kodeBon($input['tanggal'], $bon, $nilai, $judul, $total, $kecualiBaris, $nnTetap);
        $baris = [];
        foreach ($bon as $i => $b) {
            $sel = $i === 0 ? $kepala + [7 => array_sum(array_column($bon, 'nominal'))] : [1 => $tgl];
            $baris[] = $sel + [10 => (int) $b['nominal'], 11 => $teks($b['pic']), 12 => $teks($b['keterangan']), 14 => $teks($b['kode_gl']), 15 => ($i === 0 || empty($input['link_hanya_pertama']) ? $input['link_bon'] ?? null : null) ?? $kodeBon[$i],
                18 => (string) ($b['no_mobil'] ?? '')];
        }

        if (! empty($input['biaya_transfer'])) {
                    $biaya = (int) ($input['nominal_biaya'] ?? self::BIAYA_TRANSFER);
            $baris[] = [1 => $tgl, 5 => 'Biaya Transfer Keluar', 7 => $biaya, 10 => $biaya,
                12 => 'Biaya Transfer Keluar', 13 => 'Adm', 14 => 'Biaya Transfer Antar Bank'];
        }

        return $baris;
    }

    /**
     * Kode Bon per detail: nomor urut per tanggal & PIC melanjutkan yang sudah ada di lembar.
     * Saat edit: baris milik transaksi itu sendiri tidak dihitung, dan nomor lamanya ($nnTetap) dipakai lagi.
     */
    private function kodeBon(CarbonInterface $tanggal, array $bon, array $nilai, int $judul, int $total, array $kecualiBaris = [], array $nnTetap = []): array
    {
        $awalan = $tanggal->format('Ymd');
        $terpakai = [];
        $kecuali = array_flip($kecualiBaris);
        foreach (array_slice($nilai, $judul, $total - $judul - 1, true) as $i => $r) {
            if (isset($kecuali[$i + 1])) {
                continue;
            }
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
            $nn = str_pad((string) ($nnTetap[$kunci] ?? (($terpakai[$kunci] ?? 0) + 1)), 2, '0', STR_PAD_LEFT);
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
