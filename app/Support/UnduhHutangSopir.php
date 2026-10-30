<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel Hutang Supir (owner 30 Okt 2026): sheet Ringkasan (nama sopir, mobil, saldo hutang — diambil dari sheet sopirnya), satu sheet buku
 * per sopir (Tanggal, Keterangan, Debit, Kredit, Saldo; terbaru di atas seperti di layar, SEMUA transaksi sejak settle, saldo berumus),
 * dan sheet UJ belum bongkar. Sopir yang tampil = yang masih berhutang atau punya transaksi sejak settle.
 */
class UnduhHutangSopir
{
    public static function tulis(string $ke): void
    {
        $judul = (new Style)->setFontBold()->setBackgroundColor('DDE7F0');
        $rp = (new Style)->setFormat('#,##0');
        $rpTebal = (new Style)->setFontBold()->setFormat('#,##0');
        $tgl = (new Style)->setFormat('dd-mm-yyyy');
        $tebal = (new Style)->setFontBold();
        $teks = fn (?string $v, ?Style $g = null) => new Cell\StringCell((string) $v, $g);
        $hari = fn ($t) => $t ? Carbon::parse($t)->startOfDay()->toDateTimeImmutable() : null;

        $hutang = SaldoSopir::hutang();
        $buku = $hutang->map(fn ($h) => SaldoSopir::riwayat($h['sopir']))
            ->filter(fn ($r, $k) => $hutang[$k]['total'] > 0.5 || $r['baris']->isNotEmpty())
            ->sortByDesc(fn ($r) => $r['akhir']);
        $mobil = fn ($k) => DaftarSopir::cari($k)['mobil'] ?? ($hutang[$k]['belum']->last()?->mobil ?: ($hutang[$k]['saldo']['mobil'][0] ?? ''));
        // Nama sheet: maks 31 karakter, tanpa karakter terlarang, unik.
        $namaSheet = [];
        foreach ($buku->keys() as $k) {
            $n = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $k)), 0, 31) ?: 'Sopir';
            while (in_array(mb_strtolower($n), array_map('mb_strtolower', $namaSheet), true) || in_array(mb_strtolower($n), ['ringkasan', 'uj belum bongkar'], true)) {
                $n = mb_substr($n, 0, 28).' '.random_int(2, 9);
            }
            $namaSheet[$k] = $n;
        }

        $w = new Writer;
        $w->openToFile($ke);

        // Ringkasan
        $s = $w->getCurrentSheet();
        $s->setName('Ringkasan');
        $s->setSheetView((new SheetView)->setFreezeRow(2));
        foreach ([24, 12, 16] as $i => $lebar) {
            $s->setColumnWidth($lebar, $i + 1);
        }
        $w->addRow(Row::fromValues(['Nama sopir', 'Mobil', 'Saldo hutang'], $judul));
        $n = 1;
        foreach ($buku as $k => $r) {
            $w->addRow(new Row([$teks($k), $teks($mobil($k)), new Cell\FormulaCell("='".str_replace("'", "''", $namaSheet[$k])."'!E2", $rp, $r['akhir'])]));
            $n++;
        }
        $w->addRow(new Row([$teks('TOTAL', $tebal), $teks($buku->count().' sopir', $tebal), new Cell\FormulaCell('=SUM(C2:C'.max($n, 2).')', $rpTebal, $buku->sum('akhir'))]));
        $s->setAutoFilter(new AutoFilter(0, 1, 2, max($n, 2)));

        // Buku per sopir: terbaru di atas; baris terakhir = saldo awal settle; Saldo = saldo baris di bawahnya + Debit − Kredit.
        foreach ($buku as $k => $r) {
            $s = $w->addNewSheetAndMakeItCurrent();
            $s->setName($namaSheet[$k]);
            $s->setSheetView((new SheetView)->setFreezeRow(2));
            foreach ([12, 60, 14, 14, 14] as $i => $lebar) {
                $s->setColumnWidth($lebar, $i + 1);
            }
            $w->addRow(Row::fromValues(['Tanggal', 'Keterangan', 'Debit', 'Kredit', 'Saldo'], $judul));
            $baris = $r['baris']->reverse()->values();
            $akhir = $baris->count() + 2; // baris saldo awal
            foreach ($baris as $i => $b) {
                $no = $i + 2;
                $w->addRow(new Row([Cell::fromValue($hari($b['tgl']), $tgl), $teks(SaldoSopir::ketBuku($b)), Cell::fromValue($b['masuk'] ?: null, $rp), Cell::fromValue($b['keluar'] ?: null, $rp),
                    new Cell\FormulaCell('=E'.($no + 1)."+C{$no}-D{$no}", $rp, $b['sisa'])]));
            }
            $w->addRow(new Row([Cell::fromValue($hari(SaldoSopir::SETTLE), $tgl), $teks('Saldo awal (settle)'), Cell::fromValue(null), Cell::fromValue(null), Cell::fromValue($r['awal'], $rpTebal)]));
            $s->setAutoFilter(new AutoFilter(0, 1, 4, $akhir));
        }

        // UJ belum bongkar (rincian bagian hutang yang masih menunggu ritnya bongkar)
        $s = $w->addNewSheetAndMakeItCurrent();
        $s->setName('UJ belum bongkar');
        $s->setSheetView((new SheetView)->setFreezeRow(2));
        foreach ([22, 12, 11, 10, 9, 50, 14, 11] as $i => $lebar) {
            $s->setColumnWidth($lebar, $i + 1);
        }
        $w->addRow(Row::fromValues(['Sopir', 'Tanggal UJ', 'ID UJ', 'No DO', 'DT', 'Keterangan', 'Nominal', 'Umur (hari)'], $judul));
        $n = 1;
        foreach ($hutang->sortKeys() as $k => $h) {
            foreach ($h['belum'] as $u) {
                $w->addRow(new Row([$teks($k), Cell::fromValue($hari($u->tgl), $tgl), $teks($u->id_uj), $teks($u->do), $teks($u->mobil), $teks($u->ket), Cell::fromValue($u->rp, $rp), Cell::fromValue($u->umur)]));
                $n++;
            }
        }
        $w->addRow(new Row([$teks('TOTAL', $tebal), Cell::fromValue(null), Cell::fromValue(null), Cell::fromValue(null), Cell::fromValue(null), Cell::fromValue(null),
            new Cell\FormulaCell('=SUBTOTAL(109,G2:G'.max($n, 2).')', $rpTebal, $hutang->sum('belum_rp')), Cell::fromValue(null)]));
        $s->setAutoFilter(new AutoFilter(0, 1, 7, max($n, 2)));

        $w->close();
    }
}
