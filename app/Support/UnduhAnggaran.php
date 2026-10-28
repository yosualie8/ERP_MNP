<?php

namespace App\Support;

use App\Models\Bbm;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use App\Models\TagihanProyek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel Anggaran Proyek (owner 28 Okt 2026): sheet Summary = halaman Anggaran (plus rincian biaya per sumber), lalu satu sheet
 * per sumber — Pemasukan (tagihan termin, omset tahap ritasi), Ritasi (Harga Jual), BBM (solar & HM), Kas Harian, Mutasi BCA,
 * Kas UJ (tahap ritasi) dan Biaya Umum teralokasi. Hanya tahap yang tampil di halaman; jumlah tiap sheet = kolom Summary-nya.
 * Ditulis baris per baris (OpenSpout) — Ritasi & BBM lewat cursor supaya muat di memori PHP-FPM.
 */
class UnduhAnggaran
{
    private Writer $w;

    /** @var array<string, Style> */
    private array $gaya = [];

    /** @var array<int, ?string> format per kolom sheet aktif */
    private array $format = [];

    private int $n = 0;

    private bool $pertama = true;

    /** @param Collection<int, object> $baris hasil AnggaranController::ringkasan() */
    public static function tulis(Collection $baris, string $ke): void
    {
        (new self)->jalankan($baris, $ke);
    }

    private function jalankan(Collection $baris, string $ke): void
    {
        $this->w = new Writer;
        $this->w->openToFile($ke);
        $this->gaya = [
            'judul' => (new Style)->setFontBold()->setBackgroundColor('DDE7F0')->setShouldWrapText(),
            'rp' => (new Style)->setFormat('#,##0'),
            // berakhiran x = format sama, tidak dijumlah di baris TOTAL (tarif, angka mentah sebelum dibagi)
            'rpx' => (new Style)->setFormat('#,##0'),
            'n1x' => (new Style)->setFormat('#,##0.0'),
            'n1' => (new Style)->setFormat('#,##0.0'),
            'n2' => (new Style)->setFormat('#,##0.00'),
            'p' => (new Style)->setFormat('0.0%'),
            'tgl' => (new Style)->setFormat('dd-mm-yyyy'),
            'total' => (new Style)->setFontBold()->setFormat('#,##0'),
            'total_n' => (new Style)->setFontBold()->setFormat('#,##0.0'),
            'tebal' => (new Style)->setFontBold(),
        ];
        $tahap = $baris->keyBy(fn ($x) => $x->t->id);
        $ids = $tahap->keys()->all();
        $nama = fn (int $id) => [$tahap[$id]->t->proyek->nama, $tahap[$id]->t->nama];
        $tagihan = TagihanProyek::whereIn('proyek_tahap_id', $ids ?: [0])->withSum('item as nilai', 'nilai')->orderBy('tanggal')->orderBy('id')->get();

        $this->summary($baris, $tagihan->groupBy('proyek_tahap_id')->map(fn ($g) => (float) $g->sum('nilai')));
        $this->pemasukan($baris, $tagihan, $nama);
        $this->ritasi($tahap, $nama);
        $this->bbm($tahap, $nama);
        $this->kas($baris, $nama);
        $this->kasUj($baris, $nama);
        $this->biayaUmum($baris, $nama);
        $this->tutupSheet();
        $this->w->close();
    }

    private function summary(Collection $baris, Collection $tagihan): void
    {
        $this->sheet('Summary', [
            ['Proyek', null, 20], ['Tahap', null, 24], ['Tipe', null, 12], ['Status', null, 12],
            ['Omset rencana', 'rp', 15], ['Keterangan omset', null, 26], ['HPP rencana', 'rp', 15], ['Margin rencana', 'rp', 15], ['Margin %', 'p', 9],
            ['Ritasi (Harga Jual)', 'rp', 15], ['Ngepok (Harga Jual)', 'rp', 14], ['Solar (Data BBM)', 'rp', 14], ['Rental alat (HM)', 'rp', 14],
            ['Kas Harian & BCA', 'rp', 15], ['Kas UJ', 'rp', 14], ['Biaya langsung', 'rp', 15], ['Biaya umum', 'rp', 14], ['Realisasi HPP', 'rp', 15],
            ['Serapan biaya', 'p', 9], ['Progres', 'p', 9], ['Dasar progres', null, 34], ['Tagihan (pemasukan)', 'rp', 15], ['Baseline', null, 13],
        ]);
        foreach ($baris as $x) {
            $t = $x->t;
            $persen = fn ($v) => $v === null ? null : $v / 100;
            if (isset($x->ritasi)) {
                $r = $x->ritasi;
                $b = $r['biaya'];
                $biaya = [null, null, $b['solar'][2], $b['rental'][2], $b['kas'][2], $b['uj'][2] + $b['tanah'][2] + $b['uj_lain'][2] + $b['uj_alih'][2]];
                $omset = [$r['omset'], 'realisasi: '.number_format($r['dasar'] === 'rit' ? $r['rit'] : $r['m3'], 0, ',', '.').($r['dasar'] === 'rit' ? ' rit' : ' m³').' × '.number_format($r['harga'], 0, ',', '.'),
                    null, $r['margin'], $persen($r['persen_margin'])];
                $progres = [null, $r['rit'].' rit bongkar, UJ diberi '.$r['uj_diberi'].' rit (belum bongkar '.$r['uj_belum'].')'];
            } else {
                $c = $x->rincian;
                $biaya = [$c['rit'][1], $c['ngepok'][1], $c['solar'][1], $c['rental'][1], $c['kas'][1], null];
                $omset = $x->ada ? [$x->h['omset'], null, $x->h['hpp'], $x->h['margin'], $persen($x->h['persen_margin'])] : [null, null, null, null, null];
                $progres = [$persen($x->progres), $x->fisik !== null ? $x->fisikKet : ($x->progres !== null ? 'serapan biaya (belum ada dasar progres fisik)' : null)];
            }
            $this->baris([$t->proyek->nama, $t->nama, ProyekTahap::TIPE[$t->tipe] ?? $t->tipe, ProyekTahap::STATUS[$t->status] ?? $t->status,
                ...$omset, ...$biaya, $x->langsung, $x->overhead, $x->realisasi, $persen($x->serapan), ...$progres, $tagihan[$t->id] ?? null,
                $t->anggaran_dikunci_at ? $t->anggaran_dikunci_at->format('d-m-Y') : ($x->ada && ! isset($x->ritasi) ? 'belum dikunci' : null)]);
        }
        $this->total();
        $this->w->addRow(Row::fromValues([]));
        foreach ([
            'Realisasi HPP = biaya langsung + biaya umum teralokasi. Biaya langsung = Ritasi & Ngepok (Harga Jual) + Solar (liter × tarif BBM) + Rental (jam HM × tarif rental) + Kas Harian & BCA yang dihitung (+ Kas UJ untuk tahap tipe Ritasi).',
            'Tahap tipe Ritasi: tanpa anggaran — omset = realisasi bongkaran × harga, Harga Jual ritasi tidak dihitung, biaya UJ dari Kas UJ (sheet Kas UJ).',
            'Rincian tiap kolom ada di sheet sumbernya; baris TOTAL memakai SUBTOTAL sehingga ikut menyesuaikan saringan.',
            'Dibuat '.now()->translatedFormat('j F Y H:i').'.',
        ] as $catatan) {
            $this->w->addRow(Row::fromValues([$catatan]));
        }
    }

    private function pemasukan(Collection $baris, Collection $tagihan, \Closure $nama): void
    {
        $isi = [];
        foreach ($tagihan as $g) {
            $isi[] = [...$nama($g->proyek_tahap_id), 'Tagihan termin (BOQ)', $g->tanggal, $g->nomor.($g->catatan ? ' — '.$g->catatan : ''), null, null, (float) $g->nilai];
        }
        foreach ($baris->filter(fn ($x) => isset($x->ritasi)) as $x) {
            $r = $x->ritasi;
            foreach ($r['hari'] as $tgl => $h) {
                if (empty($h['omset'])) {
                    continue;
                }
                $isi[] = [...$nama($x->t->id), 'Omset ritasi (bongkaran × harga)', Carbon::parse($tgl), $r['dasar'] === 'rit' ? 'per rit '.number_format($r['harga'], 0, ',', '.') : 'per m³ '.number_format($r['harga'], 0, ',', '.'),
                    $h['rit'] ?? 0, round($h['m3'] ?? 0, 2), (float) $h['omset']];
            }
        }
        if (! $isi) {
            return;
        }
        $this->sheet('Pemasukan', [['Proyek', null, 20], ['Tahap', null, 24], ['Sumber', null, 30], ['Tanggal', 'tgl', 12], ['Nomor / dasar', null, 34], ['Rit', 'rp', 8], ['m³', 'n2', 11], ['Nominal', 'rp', 16]]);
        foreach ($isi as $r) {
            $this->baris($r);
        }
        $this->total();
    }

    /** Ritasi bongkaran & ngepok: Harga Jual = biaya tahap biasa; tahap tipe Ritasi = omset (Harga Jual tidak dihitung). */
    private function ritasi(Collection $tahap, \Closure $nama): void
    {
        $this->sheet('Ritasi', [
            ['Proyek', null, 20], ['Tahap', null, 24], ['Tanggal', 'tgl', 12], ['Tahap (lembar Ritasi)', null, 22], ['No Seri', null, 14], ['No DO', null, 10],
            ['No Lambung', null, 11], ['No Polisi / driver', null, 20], ['Pemilik', null, 14], ['Galian', null, 16], ['Jenis buangan', null, 13], ['Jenis tanah', null, 12],
            ['Kubikasi (m³)', 'n2', 11], ['Harga Jual', 'rpx', 13], ['Biaya proyek', 'rp', 14], ['Omset (tahap ritasi)', 'rp', 14], ['Keterangan', null, 30],
        ]);
        // nama di lembar Ritasi bisa beda huruf besar / spasi (collation MySQL menganggapnya sama) — tahap dicari ulang per baris
        $namaRitasi = Ritasi::distinct()->pluck('tahap')->filter(fn ($n) => isset($tahap[DaftarProyek::tahapId('ritasi', $n)]))->values();
        if ($namaRitasi->isNotEmpty()) {
            foreach (Ritasi::whereIn('tahap', $namaRitasi)->orderBy('tanggal')->orderBy('baris')->cursor() as $r) {
                if (! ($x = $tahap[DaftarProyek::tahapId('ritasi', $r->tahap)] ?? null)) {
                    continue;
                }
                $ngepok = strcasecmp((string) $r->jenis_buangan, 'Ngepok') === 0;
                $omset = null;
                if (isset($x->ritasi) && ! $ngepok) {
                    $omset = $x->ritasi['dasar'] === 'rit' ? $x->ritasi['harga'] : (float) $r->kubikasi * $x->ritasi['harga'];
                }
                $this->baris([...$nama($x->t->id), $r->tanggal, $r->tahap, $r->no_seri, $r->no_do, $r->no_lambung, $r->no_polisi, $r->pemilik, $r->galian, $r->jenis_buangan, $r->jenis_tanah,
                    (float) $r->kubikasi, (float) $r->harga_jual, isset($x->ritasi) ? null : (float) $r->harga_jual, $omset, $r->keterangan]);
            }
        }
        $this->total();
    }

    /** Data BBM & HM: solar keluar & jam HM; baris "Tahap A + Tahap B" dibagi rata (sama dengan DaftarProyek::bbm). */
    private function bbm(Collection $tahap, \Closure $nama): void
    {
        $this->sheet('BBM', [
            ['Proyek', null, 20], ['Tahap', null, 24], ['Tanggal', 'tgl', 12], ['Unit', null, 16], ['Jenis', null, 12], ['Proyek (lembar BBM)', null, 22], ['Keterangan kerja', null, 28],
            ['Liter keluar', 'n1x', 10], ['Jam HM', 'n1x', 8], ['Dibagi ke', null, 9], ['Liter (tahap ini)', 'n2', 11], ['HM (tahap ini)', 'n2', 10],
            ['Tarif BBM', 'rpx', 10], ['Solar', 'rp', 13], ['Tarif rental', 'rpx', 11], ['Rental alat', 'rp', 13],
        ]);
        foreach (Bbm::whereNotNull('proyek')->orderBy('tanggal')->orderBy('baris')->cursor() as $b) {
            $liter = (float) $b->keluar;
            $hm = $b->total_hm !== null && $b->total_hm >= 0 && $b->total_hm <= 24 ? (float) $b->total_hm : 0;
            $bagi = $b->tahap_ids ?? [null];
            if ((! $liter && ! $hm) || in_array(null, $bagi, true)) {
                continue;
            }
            foreach ($bagi as $id) {
                if (! isset($tahap[$id])) {
                    continue;
                }
                $t = $tahap[$id]->t;
                $tb = (float) ($t->tarif_bbm ?? ProyekTahap::BAWAAN['tarif_bbm']);
                $tr = (float) ($t->tarif_rental ?? ProyekTahap::BAWAAN['tarif_rental']);
                $l = $liter / count($bagi);
                $h = $hm / count($bagi);
                $this->baris([...$nama($id), $b->tanggal, $b->unit, $b->jenis, $b->proyek, $b->keterangan_kerja ?: $b->keterangan, $liter, $hm, count($bagi) > 1 ? count($bagi).' tahap' : null,
                    $l, $h, $tb, $l * $tb, $tr, $h * $tr]);
            }
        }
        $this->total();
    }

    /** Kas Harian yang dihitung (tanpa akun yang diabaikan) + Mutasi BCA PT yang ditandai dihitung — Anggaran::transaksiKas. */
    private function kas(Collection $baris, \Closure $nama): void
    {
        $semua = Anggaran::transaksiKas($baris->map(fn ($x) => $x->t->id)->all());
        $bca = [];
        $this->sheet('Kas Harian', [
            ['Proyek', null, 20], ['Tahap', null, 24], ['Tanggal', 'tgl', 12], ['NO ID', null, 9], ['Keterangan', null, 44], ['PIC', null, 14], ['Kode GL', null, 22],
            ['Akun', null, 24], ['Kelompok akun', null, 16], ['Masuk ke', null, 14], ['Nominal', 'rp', 14],
        ]);
        foreach ($baris as $x) {
            $id = $x->t->id;
            $tipe = $x->t->tipe;
            foreach (($semua[$id] ?? collect())->reverse() as $r) {
                if ($r->no_id === 'BCA') {
                    $bca[] = [...$nama($id), Carbon::parse($r->tanggal), $r->keterangan, (float) $r->nominal];

                    continue;
                }
                $grup = preg_match('/omis/i', (string) $r->akun) ? 'Komisi' : ($tipe === 'infra' && $r->kel === 'HPP' ? 'Material'
                    : ($tipe === 'infra' && Overhead::akunBbm($r->akun) ? 'BBM kendaraan' : ($r->kel === 'Gaji & Tunjangan' ? 'Gaji' : 'Operasional')));
                $this->baris([...$nama($id), Carbon::parse($r->tanggal), $r->no_id, $r->keterangan, $r->pic, $r->kode_asli, $r->akun, $r->kel, $grup, (float) $r->nominal]);
            }
        }
        $this->total();
        if ($bca) {
            $this->sheet('Mutasi BCA', [['Proyek', null, 20], ['Tahap', null, 24], ['Tanggal', 'tgl', 12], ['Keterangan', null, 60], ['Nominal', 'rp', 15]]);
            foreach ($bca as $r) {
                $this->baris($r);
            }
            $this->total();
        }
    }

    /** Kas UJ tahap tipe Ritasi (Uang Jalan + MOB, Uang Tanah, Kas UJ lain, saldo sopir dipakai) — RitasiProyek::hitung. */
    private function kasUj(Collection $baris, \Closure $nama): void
    {
        $ritasi = $baris->filter(fn ($x) => isset($x->ritasi));
        if ($ritasi->every(fn ($x) => collect($x->ritasi['rincian'])->every(fn ($g) => $g->isEmpty()))) {
            return;
        }
        $this->sheet('Kas UJ', [
            ['Proyek', null, 20], ['Tahap', null, 24], ['Jenis biaya', null, 30], ['ID UJ', null, 11], ['Tanggal', 'tgl', 12], ['Nama', null, 18], ['No mobil', null, 12],
            ['No DO', null, 10], ['Kategori', null, 14], ['Keterangan', null, 44], ['Nominal', 'rp', 14],
        ]);
        foreach ($ritasi as $x) {
            foreach ($x->ritasi['rincian'] as $k => $daftar) {
                foreach ($daftar->sortBy(fn ($u) => [$u->tanggal?->toDateString(), $u->id_uj]) as $u) {
                    $pengalihan = $u instanceof \App\Models\UjPengalihan;
                    $this->baris([...$nama($x->t->id), $x->ritasi['biaya'][$k][0], $u->id_uj, $u->tanggal, $pengalihan ? ($u->nama ?: $u->sopir) : $u->nama, $u->no_mobil, $u->no_do,
                        $pengalihan ? (\App\Models\UjPengalihan::PERUNTUKAN[$u->peruntukan] ?? $u->peruntukan) : $u->kategori, $u->keterangan, (float) $u->nominal]);
                }
            }
        }
        $this->total();
    }

    private function biayaUmum(Collection $baris, \Closure $nama): void
    {
        $rincian = Overhead::rincianTeralokasi();
        $ada = $baris->filter(fn ($x) => ! empty($rincian[$x->t->id]));
        if ($ada->isEmpty()) {
            return;
        }
        $this->sheet('Biaya Umum', [['Proyek', null, 20], ['Tahap', null, 24], ['Bulan', null, 9], ['Pos biaya umum', null, 26], ['Metode bagi', null, 14], ['Porsi', 'p', 9], ['Nominal', 'rp', 15]]);
        foreach ($ada as $x) {
            foreach ($rincian[$x->t->id] as $r) {
                $this->baris([...$nama($x->t->id), $r['bulan'], $r['pos'], $r['metode'], $r['porsi'] / 100, (float) $r['rp']]);
            }
        }
        $this->total();
    }

    /** @param array<int, array{0: string, 1: ?string, 2: float}> $kolom [judul, format, lebar] */
    private function sheet(string $nama, array $kolom): void
    {
        if ($this->pertama) {
            $this->pertama = false;
            $s = $this->w->getCurrentSheet();
        } else {
            $this->tutupSheet();
            $s = $this->w->addNewSheetAndMakeItCurrent();
        }
        $s->setName($nama);
        $s->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn('C'));
        foreach (array_values($kolom) as $i => [, , $lebar]) {
            $s->setColumnWidth($lebar, $i + 1);
        }
        $this->format = array_column($kolom, 1);
        $this->w->addRow(Row::fromValues(array_column($kolom, 0), $this->gaya['judul']));
        $this->n = 1;
    }

    private function baris(array $nilai): void
    {
        $sel = [];
        foreach (array_values($nilai) as $i => $v) {
            $g = $this->gaya[$this->format[$i] ?? ''] ?? null;
            // teks selalu teks (keterangan berawalan "=" tidak menjadi rumus)
            $sel[] = is_string($v) && $v !== '' ? new Cell\StringCell($v, $g) : Cell::fromValue($v instanceof \DateTimeInterface || is_scalar($v) || $v === null ? $v : (string) $v, $g);
        }
        $this->w->addRow(new Row($sel));
        $this->n++;
    }

    /** Baris TOTAL: SUBTOTAL(109) untuk kolom angka — ikut saringan. */
    private function total(): void
    {
        $sel = [new Cell\StringCell('TOTAL', $this->gaya['tebal'])];
        foreach (array_slice($this->format, 1, null, true) as $i => $f) {
            $huruf = self::huruf($i);
            $sel[] = in_array($f, ['rp', 'n1', 'n2'], true) && $this->n > 1
                ? new Cell\FormulaCell("=SUBTOTAL(109,{$huruf}2:{$huruf}{$this->n})", $this->gaya[$f === 'rp' ? 'total' : 'total_n'], null) : Cell::fromValue(null);
        }
        $this->w->addRow(new Row($sel));
    }

    private function tutupSheet(): void
    {
        $this->w->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, count($this->format) - 1, max($this->n, 2)));
    }

    private static function huruf(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }
}
