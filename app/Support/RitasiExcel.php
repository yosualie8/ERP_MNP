<?php

namespace App\Support;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel Ritasi berformat lembar "Ritasi" (sheet Proyek ASG) — owner 6 Nov 2026: satu lembar, header baris 1 (tebal, biru muda),
 * kolom A No … Q No Do sama dengan sheet, R Kubikasi; isi = rit yang tampil di grid (periode, tanggal, pencarian, saringan kolom),
 * urut seperti di sheet (lama → baru). Baris TOTAL berumus di bawah. No = nomor baris sheet − 1 (sama dengan kolom No di sheet).
 */
class RitasiExcel
{
    /** Lebar kolom A..R (piksel lembar Ritasi ÷ 7). */
    private const LEBAR = [46, 200, 120, 98, 81, 137, 110, 161, 130, 121, 116, 122, 116, 95, 150, 109, 80, 80];

    /**
     * @param  Collection<int, \App\Models\Ritasi>  $rit
     * @param  (callable(int, int): void)|null  $kemajuan
     */
    public static function buat(Collection $rit, ?callable $kemajuan, string $path): string
    {
        $rit = $rit->sortBy('baris')->values();
        $opsi = new Options();
        foreach (self::LEBAR as $i => $px) {
            $opsi->setColumnWidth(round($px / 7, 1), $i + 1);
        }
        $w = new Writer($opsi);
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Ritasi');
        $w->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(2));

        $st = fn () => (new Style())->setFontName('Calibri')->setFontSize(11);
        $kepala = $st()->setFontSize(12)->setFontBold()->setBackgroundColor('BDD6EE')->setCellAlignment(CellAlignment::CENTER)->setShouldWrapText();
        $kepalaDo = $st()->setFontSize(12)->setFontBold()->setBackgroundColor('B4C6E7')->setCellAlignment(CellAlignment::CENTER);
        $s = ['teks' => $st(), 'tgl' => $st()->setFormat('d-mmm-yy'), 'jam' => $st()->setFormat('h:mm'), 'rp' => $st()->setFormat('#,##0'),
            'm3' => $st()->setFormat('#,##0.00'), 'tot' => $st()->setFontBold()->setBackgroundColor('DDEBF7'),
            'totRp' => $st()->setFontBold()->setBackgroundColor('DDEBF7')->setFormat('#,##0'), 'totM3' => $st()->setFontBold()->setBackgroundColor('DDEBF7')->setFormat('#,##0.00')];
        $c = fn ($v, string $k) => Cell::fromValue($v ?? '', $s[$k]);

        $judul = ['No', 'Tahap', 'No Seri', 'Tanggal', 'Jam', 'Plat Nomor', 'No Lambung', 'No Polisi', 'Galian', 'Jenis Buangan', 'Jenis Tanah', 'Jenis Kendaraan',
            'Nama Pemilik', 'Harga Jual', 'Keterangan', 'Status Bayar', 'No Do', 'Kubikasi'];
        $w->addRow(new Row(array_map(fn ($h) => Cell::fromValue($h, $h === 'No Do' ? $kepalaDo : $kepala), $judul)));

        // Jam "17:00" → pecahan hari supaya terbaca sebagai jam di Excel.
        $jam = function (?string $v) {
            if (! $v || ! preg_match('/^(\d{1,2})[:.](\d{2})/', trim($v), $m)) {
                return $v ?? '';
            }

            return ((int) $m[1] * 60 + (int) $m[2]) / 1440;
        };
        $n = $rit->count();
        foreach ($rit as $i => $r) {
            if ($kemajuan && $i % 200 === 0) {
                $kemajuan($i, $n);
            }
            $w->addRow(new Row([
                $c($r->baris ? $r->baris - 1 : '', 'teks'), $c($r->tahap, 'teks'), $c((string) $r->no_seri, 'teks'),
                $c($r->tanggal ? \DateTimeImmutable::createFromInterface($r->tanggal) : '', 'tgl'), $c($jam($r->jam), 'jam'),
                $c($r->plat, 'teks'), $c($r->no_lambung, 'teks'), $c($r->no_polisi, 'teks'), $c($r->galian, 'teks'), $c($r->jenis_buangan, 'teks'),
                $c($r->jenis_tanah, 'teks'), $c($r->jenis_kendaraan, 'teks'), $c($r->pemilik, 'teks'), $c($r->harga_jual !== null ? (int) $r->harga_jual : '', 'rp'),
                $c($r->keterangan, 'teks'), $c($r->status_bayar, 'teks'), $c((string) ($r->no_do ?? ''), 'teks'), $c($r->kubikasi !== null ? (float) $r->kubikasi : '', 'm3'),
            ]));
        }
        if ($kemajuan) {
            $kemajuan($n, $n);
        }
        $akhir = max(2, $n + 1);
        $w->addRow(new Row([$c('', 'tot'), $c('TOTAL · '.$n.' rit', 'tot'), ...array_fill(0, 11, $c('', 'tot')), $c("=SUM(N2:N{$akhir})", 'totRp'),
            $c('', 'tot'), $c('', 'tot'), $c('', 'tot'), $c("=SUM(R2:R{$akhir})", 'totM3')]));
        $w->close();

        return $path;
    }
}
