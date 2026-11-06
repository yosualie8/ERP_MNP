<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasTransfer;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel isi grid Kas Harian (owner 6 Nov 2026) — baris yang tampil di layar, urutan sama:
 *  - lembar "Kas Harian": seperti grid (baris transfer lalu transaksi detail di bawahnya, semua detail terbuka);
 *  - lembar "Detail": satu baris per transaksi detail + data transfernya (untuk saring/pivot);
 *  - lembar "Info": periode & saringan yang dipakai.
 */
class KasExcel
{
    /**
     * @param  Collection<int, KasTransfer>  $transfer  transfer yang tampil (urut seperti layar)
     * @param  array<int, int>|null  $bonTampil  id detail yang tampil bila saringan kolom detail aktif (null = semua detail)
     * @param  array<string, mixed>  $d  data halaman (KasController::data)
     */
    public static function buat(Collection $transfer, ?array $bonTampil, array $d): string
    {
        $path = storage_path('app/kas-harian-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(3)).'.xlsx');
        $w = new Writer(new Options());
        $w->openToFile($path);

        $dasar = fn () => (new Style())->setFontName('Arial')->setFontSize(10);
        $kepala = $dasar()->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('1F4E78')->setShouldWrapText()->setCellAlignment(CellAlignment::CENTER);
        $teks = $dasar();
        $tebal = $dasar()->setFontBold()->setBackgroundColor('EAF1FB');
        $tgl = $dasar()->setFormat('dd mmm yyyy')->setCellAlignment(CellAlignment::CENTER);
        $tglTebal = $dasar()->setFontBold()->setBackgroundColor('EAF1FB')->setFormat('dd mmm yyyy')->setCellAlignment(CellAlignment::CENTER);
        $angka = $dasar()->setFormat('#,##0;(#,##0);-');
        $angkaTebal = $dasar()->setFontBold()->setBackgroundColor('EAF1FB')->setFormat('#,##0;(#,##0);-');
        $tengah = $dasar()->setCellAlignment(CellAlignment::CENTER);
        $tengahTebal = $dasar()->setFontBold()->setBackgroundColor('EAF1FB')->setCellAlignment(CellAlignment::CENTER);

        $jam = $d['jam'];
        $lembarNama = $d['namaLembar'];
        $sT = $d['statusTransfer'];
        $sB = $d['statusBon'];
        $bon = fn (KasTransfer $t) => $bonTampil === null ? $t->bon : $t->bon->filter(fn ($b) => isset($bonTampil[$b->id]))->values();
        $gl = fn ($k) => $k ? [(string) $k->kode_asli, (string) ($k->akun?->nama ?? ''), (string) ($k->costCenter?->kode ?? ''), (string) ($k->ref ?? '')] : ['', '', '', ''];
        $statusT = function (KasTransfer $t) use ($sT) {
            $s = $sT[$t->id] ?? ['kode' => ''];

            return match ($s['kode']) {
                'sudah' => 'Sudah'.(! empty($s['tanggal']) ? ' · '.$s['tanggal']->format('d/m/Y') : ''),
                'sebagian' => "Sebagian {$s['sudah']}/{$s['jumlah']} · belum ".rp($s['nilai_belum']),
                'belum' => 'Belum', 'arsip' => 'Arsip', 'masuk' => 'Saldo masuk', default => '',
            };
        };
        $statusB = function (KasBon $b) use ($sB) {
            $s = $sB[$b->id] ?? null;

            return $s === null ? 'Arsip' : ($s['sudah'] ? 'Sudah'.(! empty($s['tanggal']) ? ' · '.$s['tanggal']->format('d/m/Y') : '') : 'Belum');
        };
        $c = fn ($v, $st) => Cell::fromValue($v ?? '', $st);
        $tanggal = fn ($v) => $v ? \DateTimeImmutable::createFromInterface($v) : '';

        // ---- Lembar 1: seperti grid ----
        $w->getCurrentSheet()->setName('Kas Harian');
        $w->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(2));
        foreach ([8, 12, 7, 9, 7, 9, 30, 26, 48, 20, 20, 8, 12, 10, 14, 14, 14, 16, 24] as $i => $lebar) {
            $w->getOptions()->setColumnWidth($lebar, $i + 1);
        }
        $w->addRow(Row::fromValues(['Jenis', 'Tanggal', 'Jam', 'Lembar', 'Baris', 'NO ID', 'Tujuan / PIC', 'Bank · rekening / No Mobil', 'Keterangan',
            'Kode GL', 'Akun', 'Cost center', 'Tahap (ref)', 'ID transaksi', 'Masuk', 'Keluar', 'Nominal detail', 'Saldo', 'Reimburse'], $kepala));
        foreach ($transfer as $t) {
            $dt = $bon($t);
            [$kode, $akun, $cc, $ref] = $gl($t->kodeGl);
            if ($t->bon->isNotEmpty()) {
                $akunBon = $t->bon->map(fn ($b) => $b->kodeGl ? ($b->kodeGl->akun?->nama ?? $b->kodeGl->kode_asli) : 'tanpa Kode GL')->unique()->values();
                [$kode, $akun, $cc, $ref] = ['', $akunBon->implode(', '), '', ''];
            }
            $w->addRow(new Row([
                $c('Transfer', $tengahTebal), $c($tanggal($t->tanggal), $tglTebal), $c($jam[$t->id] ?? '', $tengahTebal), $c($lembarNama[$t->kas_bulan_id] ?? '', $tengahTebal),
                $c($t->baris, $tengahTebal), $c($t->no_id ? (string) $t->no_id : '', $tengahTebal), $c($t->nama_tujuan, $tebal),
                $c(trim($t->bank_tujuan.' '.$t->no_rek_tujuan), $tebal), $c($t->keterangan, $tebal), $c($kode, $tebal), $c($akun, $tebal), $c($cc, $tebal), $c($ref, $tebal),
                $c('', $tebal), $c($t->debet ?: '', $angkaTebal), $c($t->kredit ?: '', $angkaTebal), $c($t->bon->isNotEmpty() ? (int) $t->bon->sum('nominal') : '', $angkaTebal),
                $c($t->saldo, $angkaTebal), $c($statusT($t), $tebal),
            ]));
            foreach ($dt as $b) {
                [$kode, $akun, $cc, $ref] = $gl($b->kodeGl);
                $w->addRow(new Row([
                    $c('Detail', $tengah), $c($tanggal($b->tanggal ?? $t->tanggal), $tgl), $c('', $teks), $c('', $teks), $c($b->baris, $tengah), $c('', $teks),
                    $c($b->pic, $teks), $c($b->no_mobil ?? '', $teks), $c($b->keterangan, $teks), $c($kode.($b->kode_gl_ditebak ? ' (ditebak)' : ''), $teks), $c($akun, $teks),
                    $c($cc, $teks), $c($ref, $teks), $c($b->id_transaksi, $teks), $c('', $teks), $c('', $teks), $c((int) $b->nominal, $angka), $c('', $teks), $c($statusB($b), $teks),
                ]));
            }
        }

        // ---- Lembar 2: satu baris per detail ----
        $w->addNewSheetAndMakeItCurrent()->setName('Detail');
        $w->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(2));
        $w->addRow(Row::fromValues(['Tanggal transfer', 'Jam', 'NO ID', 'Tujuan transfer', 'Keterangan transfer', 'Nilai transfer', 'Tanggal detail', 'PIC', 'Keterangan detail',
            'No Mobil', 'Kode GL', 'Akun', 'Kelompok akun', 'Cost center', 'Tahap (ref)', 'ID transaksi', 'Nominal', 'Reimburse', 'Lembar'], $kepala));
        foreach ($transfer as $t) {
            foreach ($bon($t) as $b) {
                [$kode, $akun, $cc, $ref] = $gl($b->kodeGl);
                $w->addRow(new Row([
                    $c($tanggal($t->tanggal), $tgl), $c($jam[$t->id] ?? '', $tengah), $c($t->no_id ? (string) $t->no_id : '', $tengah), $c($t->nama_tujuan, $teks), $c($t->keterangan, $teks),
                    $c($t->kredit ?: $t->debet, $angka), $c($tanggal($b->tanggal ?? $t->tanggal), $tgl), $c($b->pic, $teks), $c($b->keterangan, $teks), $c($b->no_mobil ?? '', $teks),
                    $c($kode, $teks), $c($akun, $teks), $c((string) ($b->kodeGl?->akun?->kelompok ?? ''), $teks), $c($cc, $teks), $c($ref, $teks), $c($b->id_transaksi, $teks),
                    $c((int) $b->nominal, $angka), $c($statusB($b), $teks), $c($lembarNama[$t->kas_bulan_id] ?? '', $tengah),
                ]));
            }
        }

        // ---- Lembar 3: info saringan ----
        $w->addNewSheetAndMakeItCurrent()->setName('Info');
        $nDetail = $transfer->sum(fn ($t) => $bon($t)->count());
        foreach ([
            ['Kas Harian — diunduh', now()->format('d/m/Y H:i')],
            ['Periode', $d['periode']->label()],
            ['Tanggal', $d['tanggal'] ? (string) $d['tanggal'] : 'semua'],
            ['Pencarian', $d['q'] !== '' ? $d['q'] : '-'],
            ['Status reimburse', $d['filterStatus'] ? ucfirst($d['filterStatus']).' reimburse' : 'semua'],
            ['Saringan kolom grid', $bonTampil !== null ? 'ya (kolom detail)' : 'mengikuti baris yang tampil'],
            ['Jumlah transfer', $transfer->count()],
            ['Jumlah transaksi detail', $nDetail],
            ['Total masuk', (int) $transfer->sum('debet')],
            ['Total keluar', (int) $transfer->sum('kredit')],
        ] as [$k, $v]) {
            $w->addRow(new Row([$c($k, $dasar()->setFontBold()), $c($v, is_int($v) ? $angka : $teks)]));
        }
        $w->close();

        return $path;
    }
}
