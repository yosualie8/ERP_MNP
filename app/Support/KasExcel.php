<?php

namespace App\Support;

use App\Models\KasTransfer;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel Kas Harian berformat lembar bulanan "Kas Harian MNP" (owner 6 Nov 2026), SEMUA bulan periode dalam SATU lembar:
 * judul Kas Bank Jago / PT MULTI NIAGA PUTRA, header baris 6, Saldo Awal, lalu baris transfer (B–I) dengan detail di kolom K–S
 * (detail pertama sebaris dengan transfernya, detail berikutnya di baris bawahnya), kolom J pemisah hitam, baris TOTAL.
 * Isinya transfer yang tampil di grid (periode, tanggal, pencarian, status, saringan kolom), urut seperti di sheet (lama → baru).
 * Tambahan di kanan: T Jam (mutasi Jago), U Status reimburse.
 */
class KasExcel
{
    /** Lebar kolom A..U (piksel lembar bulanan ÷ 7). */
    private const LEBAR = [20, 79, 182, 136, 100, 326, 118, 104, 107, 27, 78, 72, 438, 58, 208, 176, 139, 100, 100, 60, 150];

    /**
     * @param  Collection<int, KasTransfer>  $transfer
     * @param  array<int, int>|null  $bonTampil  id detail yang tampil bila saringan kolom detail aktif (null = semua detail)
     * @param  array<string, mixed>  $d  data halaman (KasController::data)
     * @param  (callable(int, int): void)|null  $kemajuan  dipanggil berkala (transfer ke-i dari n)
     */
    public static function buat(Collection $transfer, ?array $bonTampil, array $d, ?callable $kemajuan = null, ?string $path = null): string
    {
        $path ??= storage_path('app/kas-harian-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(3)).'.xlsx');
        $urutLembar = collect($d['lembar'])->mapWithKeys(fn ($b) => [$b->id => $b->bulan->format('Ym')]);
        $transfer = $transfer->sortBy(fn ($t) => ($urutLembar[$t->kas_bulan_id] ?? '000000').sprintf('%07d', $t->baris))->values();

        $opsi = new Options();
        foreach (self::LEBAR as $i => $px) {
            $opsi->setColumnWidth(round($px / 7, 1), $i + 1);
        }
        $w = new Writer($opsi);
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Kas Harian');
        $w->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(7));

        $garis = new Border(...array_map(fn ($s) => new BorderPart($s, 'BFBFBF', Border::WIDTH_THIN), [Border::TOP, Border::BOTTOM, Border::LEFT, Border::RIGHT]));
        $st = fn () => (new Style())->setFontName('Calibri')->setFontSize(11);
        $AKUN = '_-* #,##0_-;\-* #,##0_-;_-* "-"??_-;_-@_-';
        $s = [
            'judul' => $st()->setFontBold()->setFontSize(12),
            'info' => $st()->setFontItalic()->setFontColor('595959'),
            'kepala' => $st()->setFontSize(14)->setFontBold()->setCellAlignment(CellAlignment::CENTER)->setBorder($garis)->setShouldWrapText(),
            'kepalaJ' => $st()->setBackgroundColor(Color::BLACK)->setBorder($garis),
            'tgl' => $st()->setFormat('d-mmm-yy')->setCellAlignment(CellAlignment::LEFT)->setBorder($garis),
            'teks' => $st()->setBorder($garis),
            'rp' => $st()->setFormat($AKUN)->setBorder($garis),
            'j' => $st()->setBackgroundColor(Color::BLACK)->setBorder($garis),
            'detail' => $st()->setFormat('#,##0')->setCellAlignment(CellAlignment::CENTER)->setBorder($garis),
            'polos' => $st(),
            'tengah' => $st()->setCellAlignment(CellAlignment::CENTER),
            'tot' => $st()->setFontBold()->setBorder($garis)->setBackgroundColor('DDEBF7'),
            'totRp' => $st()->setFontBold()->setFormat($AKUN)->setBorder($garis)->setBackgroundColor('DDEBF7'),
            'totDetail' => $st()->setFontBold()->setFormat('#,##0')->setCellAlignment(CellAlignment::CENTER)->setBorder($garis)->setBackgroundColor('DDEBF7'),
        ];
        $c = fn ($v, string $k) => Cell::fromValue($v ?? '', $s[$k]);
        $tgl = fn ($v) => $v ? \DateTimeImmutable::createFromInterface($v) : '';

        // Judul (baris 1–5) & header (baris 6)
        $saring = array_filter([
            $d['tanggal'] ? 'tanggal '.$d['tanggal'] : null, $d['q'] !== '' ? 'pencarian "'.$d['q'].'"' : null,
            $d['filterStatus'] ? ($d['filterStatus'] === 'belum' ? 'belum reimburse' : 'sudah reimburse') : null,
            ($bonTampil !== null || $transfer->count() < ($d['transfer']->count() ?? 0)) ? 'saringan kolom grid' : null,
        ]);
        $w->addRow(Row::fromValues([]));
        $w->addRow(new Row([$c('', 'polos'), $c('Kas Bank Jago', 'judul')]));
        $w->addRow(new Row([$c('', 'polos'), $c('PT MULTI NIAGA PUTRA', 'judul')]));
        $w->addRow(new Row([$c('', 'polos'), $c('Periode '.$d['periode']->label().' · diunduh '.now()->format('d/m/Y H:i'), 'judul')]));
        $w->addRow(new Row([$c('', 'polos'), $c($saring ? 'Saringan: '.implode(', ', $saring).' — saldo mengikuti sheet (bukan dijumlah ulang)' : '', 'info')]));
        $kepala = ['Tanggal', 'Nama Rek Tujuan', 'No RekTujuan', 'Bank', 'Keterangan', 'Debet', 'Kredit', 'Saldo', '', 'Detail Kredit', 'PIC', 'Keterangan', 'GL', 'Kode GL', 'Kode Bon', 'NO ID', 'ID TRANSAKSI', 'NO MOBIL', 'Jam', 'Reimburse'];
        $w->addRow(new Row([$c('', 'polos'), ...array_map(fn ($h, $i) => $c($h, $i === 8 ? 'kepalaJ' : 'kepala'), $kepala, array_keys($kepala))]));

        // Saldo Awal periode (lembar pertama)
        $awal = collect($d['lembar'])->first();
        $w->addRow(new Row([$c('', 'polos'), $c($tgl($awal?->bulan), 'tgl'), $c('', 'teks'), $c('', 'teks'), $c('', 'teks'), $c('Saldo Awal', 'teks'),
            $c((int) ($awal->saldo_awal ?? 0), 'rp'), $c('', 'rp'), $c((int) ($awal->saldo_awal ?? 0), 'rp'), $c('', 'j'), ...array_fill(0, 6, $c('', 'teks'))]));
        $baris = 7;
        $mulaiData = 8;

        $jam = $d['jam'];
        $sT = $d['statusTransfer'];
        $sB = $d['statusBon'];
        $statusT = function (KasTransfer $t) use ($sT) {
            $x = $sT[$t->id] ?? ['kode' => ''];

            return match ($x['kode']) {
                'sudah' => 'Sudah'.(! empty($x['tanggal']) ? ' '.$x['tanggal']->format('d/m/Y') : ''),
                'sebagian' => "Sebagian {$x['sudah']}/{$x['jumlah']}", 'belum' => 'Belum', 'arsip' => 'Arsip', 'masuk' => 'Saldo masuk', default => '',
            };
        };
        $statusB = function ($b) use ($sB) {
            $x = $sB[$b->id] ?? null;

            return $x === null ? '' : ($x['sudah'] ? 'Sudah'.(! empty($x['tanggal']) ? ' '.$x['tanggal']->format('d/m/Y') : '') : 'Belum');
        };
        $detailSel = fn ($b, $t, bool $pertama) => [
            $c((int) $b->nominal, 'detail'), $c($b->pic, 'teks'), $c($b->keterangan, 'teks'), $c($b->gl, 'teks'), $c($b->kodeGl?->kode_asli, 'teks'),
            $c($b->kode_bon, 'teks'), $c((string) ($b->no_id ?: ($pertama ? $t->no_id : '')), 'polos'), $c($b->id_transaksi, 'polos'), $c($b->no_mobil ?? '', 'polos'),
        ];

        $n = $transfer->count();
        foreach ($transfer as $i => $t) {
            if ($kemajuan && $i % 100 === 0) {
                $kemajuan($i, $n);
            }
            $bon = $bonTampil === null ? $t->bon : $t->bon->filter(fn ($b) => isset($bonTampil[$b->id]))->values();
            $kepalaT = [$c('', 'polos'), $c($tgl($t->tanggal), 'tgl'), $c($t->nama_tujuan, 'teks'), $c((string) $t->no_rek_tujuan, 'teks'), $c($t->bank_tujuan, 'teks'),
                $c($t->keterangan, 'teks'), $c($t->debet ?: '', 'rp'), $c($t->kredit ?: '', 'rp'), $c($t->saldo, 'rp'), $c('', 'j')];
            $ekor = [$c($jam[$t->id] ?? '', 'tengah'), $c($statusT($t), 'polos')];
            if ($bon->isEmpty()) {
                // Uang masuk / transfer tanpa detail: Kode GL transfer di kolom O.
                $w->addRow(new Row([...$kepalaT, $c('', 'detail'), $c('', 'teks'), $c('', 'teks'), $c('', 'teks'), $c($t->kodeGl?->kode_asli, 'teks'), $c('', 'teks'),
                    $c($t->no_id ? (string) $t->no_id : '', 'polos'), $c('', 'polos'), $c('', 'polos'), ...$ekor]));
                $baris++;

                continue;
            }
            foreach ($bon as $k => $b) {
                $awalBaris = $k === 0 ? $kepalaT : [$c('', 'polos'), $c($tgl($b->tanggal ?? $t->tanggal), 'tgl'), $c('', 'teks'), $c('', 'teks'), $c('', 'teks'), $c('', 'teks'),
                    $c('', 'rp'), $c('', 'rp'), $c('', 'rp'), $c('', 'j')];
                $w->addRow(new Row([...$awalBaris, ...$detailSel($b, $t, $k === 0), $c($k === 0 ? ($jam[$t->id] ?? '') : '', 'tengah'), $c($statusB($b), 'polos')]));
                $baris++;
            }
        }
        if ($kemajuan) {
            $kemajuan($n, $n);
        }

        // TOTAL (rumus, ikut berubah bila baris diubah di Excel)
        $akhir = max($mulaiData, $baris);
        $w->addRow(new Row([$c('', 'polos'), $c('TOTAL', 'tot'), $c('', 'tot'), $c('', 'tot'), $c('', 'tot'), $c($n.' transfer', 'tot'),
            $c("=SUM(G{$mulaiData}:G{$akhir})", 'totRp'), $c("=SUM(H{$mulaiData}:H{$akhir})", 'totRp'), $c($transfer->last()?->saldo ?? ($awal->saldo_awal ?? 0), 'totRp'),
            $c('', 'j'), $c("=SUM(K{$mulaiData}:K{$akhir})", 'totDetail')]));
        $w->close();

        return $path;
    }
}
