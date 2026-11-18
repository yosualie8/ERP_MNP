<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Lembar "UM format admin" di unduhan Rekap Absen & Gaji Mingguan (owner 18 Nov 2026): meniru file UM yang selama ini dibuat
 * admin (Valen) sendiri — blok per hari (UM | Lemburan | Total | Keterangan jam | Proyek) + subtotal per tahap & HM operator di
 * kanan, lalu rekap per orang + tabel tunjangan operator (basic, transport, kesehatan, HM), lalu rekap per tahap.
 * Semua total berupa rumus yang menunjuk sel harian yang tepat (dibangkitkan dari data, bukan diketik), jadi admin bisa
 * menelusuri / mengubah angka harian dan totalnya ikut. Angka sama persis dengan lembar Rekap per orang & Rekap per tahap.
 */
class LembarUmAdmin
{
    public const NAMA = 'UM format admin';

    /** Keterangan jam satu hari (sama dengan lembar Harian): absen masuk–pulang / ikut mandor + jam lembur. */
    public static function keterangan(object $x): string
    {
        $ket = $x->masuk || $x->pulang ? ($x->masuk ?? '?').' - '.($x->pulang ?? '?').($x->tunggu === false && $x->absen === 'kurang' ? ' (disetujui)' : '')
            : ($x->jam_mandor ? ($x->jam_mandor['masuk'] ?? '?').' - '.($x->jam_mandor['pulang'] ?? '?').' (ikut '.$x->jam_mandor['nama'].')'
                : ($x->sumber === 'mandor' ? 'diisi mandor (tidak ada yang absen di lokasi)' : 'disetujui admin'));
        if ($x->lembur_mulai || $x->lembur_selesai) {
            $ket .= ' · lembur '.($x->lembur_mulai ?? '?').' - '.($x->lembur_selesai ?? '?');
        }

        return $ket;
    }

    /** Tulis lembar ke sheet yang sedang aktif. $d = RekapAbsenMingguan::data(). */
    public static function tulis(Writer $w, array $d): void
    {
        $tebal = (new Style)->setFontBold();
        $kepala = (new Style)->setFontBold()->setBackgroundColor('DDE7F0');
        $rp = (new Style)->setFormat('#,##0');
        $rpTebal = (new Style)->setFontBold()->setFormat('#,##0');
        $teks = fn ($v, ?Style $g = null) => new Cell\StringCell((string) $v, $g);
        $angka = fn ($v, ?Style $g = null) => $v === null || $v === '' ? new Cell\EmptyCell(null, null) : new Cell\NumericCell($v, $g);
        $kosong = fn () => new Cell\EmptyCell(null, null);
        $rumus = fn (string $f, $nilai, ?Style $g = null) => new Cell\FormulaCell($f, $g ?? $rp, $nilai);
        $sum = fn (array $sel) => $sel ? '=SUM('.implode(',', $sel).')' : '=0';

        $s = $w->getCurrentSheet();
        $s->setName(self::NAMA);
        // A No · B Hari · C Nama · D Jabatan · E UM · F Lemburan · G Total · H Keterangan · I Proyek · J (jarak) · K Tahap · L Total · M Operator · N HM
        foreach ([5, 26, 30, 22, 13, 13, 13, 40, 30, 3, 30, 14, 26, 14, 12, 12, 14] as $i => $l) {
            $s->setColumnWidth($l, $i + 1);
        }

        $senin = $d['senin'];
        $minggu = $senin->copy()->addDays(6);
        $periode = $senin->translatedFormat('j M').' – '.$minggu->translatedFormat('j M Y');
        $w->addRow(Row::fromValues(['UANG MAKAN KARYAWAN'], $tebal));
        $w->addRow(Row::fromValues(['Periode '.$periode]));
        $w->addRow(Row::fromValues([]));
        $n = 3;

        $dibayar = $d['baris']->filter(fn ($b) => $b->total > 0)->values();
        $selOrang = [];   // karyawan_id => sel G harian
        $selTahap = [];   // nama tahap => sel L subtotal harian
        $nilaiTahap = []; // nama tahap => UM + lembur (nilai cache rumus)

        // 1. Blok per hari.
        foreach ($d['hari'] as $h) {
            $tgl = $h->toDateString();
            $hariIni = $dibayar->map(fn ($b) => [$b, $b->hari[$tgl]])->filter(fn ($x) => $x[1]->hadir && $x[1]->bayar + $x[1]->bayar_lembur > 0)->values();
            if ($hariIni->isEmpty()) {
                continue;
            }
            $n++;
            $w->addRow(Row::fromValues(['No', 'Hari, Tanggal', 'Nama Karyawan', 'Jabatan', 'UM', 'Lemburan', 'Total', 'Keterangan', 'Proyek', '',
                'Tahap', 'Total', 'Operator', 'HM (jam)'], $kepala));
            $awal = $n + 1;
            $label = $h->translatedFormat('l, j F Y');

            $kiri = [];
            $perTahapHari = []; // nama tahap => [sel G, nilai]
            foreach ($hariIni as $i => [$b, $x]) {
                $r = $awal + $i;
                $tahap = $x->tahap ?? RekapAbsenMingguan::BELUM_TAHAP;
                $jumlah = $x->bayar + $x->bayar_lembur;
                $kiri[] = [$angka($i + 1), $teks($label), $teks($b->k->nama), $teks($b->k->jabatan), $angka($x->bayar, $rp), $angka($x->bayar_lembur ?: null, $rp),
                    $rumus("=SUM(E{$r}:F{$r})", $jumlah), $teks(self::keterangan($x)), $teks($tahap)];
                $selOrang[$b->k->id][] = "G{$r}";
                $perTahapHari[$tahap][0][] = "G{$r}";
                $perTahapHari[$tahap][1] = ($perTahapHari[$tahap][1] ?? 0) + $jumlah;
            }
            $kanan = [];
            foreach ($perTahapHari as $tahap => [$sel, $nilai]) {
                $r = $awal + count($kanan);
                $kanan[] = [$teks($tahap), $rumus($sum($sel), $nilai)];
                $selTahap[$tahap][] = "L{$r}";
                $nilaiTahap[$tahap] = ($nilaiTahap[$tahap] ?? 0) + $nilai;
            }
            $hm = $hariIni->filter(fn ($x) => $x[0]->tarif_hm && $x[1]->hm)->map(fn ($x) => [$teks($x[0]->k->nama), $angka((float) $x[1]->hm)])->values()->all();

            $baris = max(count($kiri), count($kanan), count($hm));
            for ($i = 0; $i < $baris; $i++) {
                $sel = array_merge($kiri[$i] ?? array_fill(0, 9, null), [null], $kanan[$i] ?? [null, null], $hm[$i] ?? [null, null]);
                $w->addRow(new Row(array_map(fn ($c) => $c ?? $kosong(), $sel)));
            }
            $n += $baris;
            $w->addRow(Row::fromValues([]));
            $n++;
        }

        // 2. Rekap per orang (kiri) + tunjangan operator (kanan, mulai baris yang sama).
        $w->addRow(Row::fromValues([]));
        $n++;
        $operator = $dibayar->filter(fn ($b) => $b->total_mingguan || $b->total_hm)->values();
        $w->addRow(Row::fromValues(['REKAP PER ORANG', '', '', '', '', '', '', '', '', '', 'TUNJANGAN OPERATOR — '.$periode], $tebal));
        $n++;
        $w->addRow(Row::fromValues(['No', 'Nama Karyawan', 'Jabatan', 'Site', 'UM & lembur', 'Tunjangan & HM', 'Total', 'Rekening', '', '',
            'Nama Karyawan', 'Basic', 'Uang transport', 'Uang kesehatan', 'HM (jam)', 'Uang HM', 'Total'], $kepala));
        $n++;
        $awal = $n + 1;
        $barisOperator = []; // karyawan_id => baris tabel operator
        foreach ($operator as $i => $b) {
            $barisOperator[$b->k->id] = $awal + $i;
        }
        $baris = max($dibayar->count(), $operator->count());
        for ($i = 0; $i < $baris; $i++) {
            $r = $awal + $i;
            $sel = array_fill(0, 17, null);
            if ($b = $dibayar[$i] ?? null) {
                $tunj = $b->total_mingguan + $b->total_hm;
                $sel[0] = $angka($i + 1);
                $sel[1] = $teks($b->k->nama);
                $sel[2] = $teks($b->k->jabatan);
                $sel[3] = $teks($b->k->site);
                $sel[4] = $rumus($sum($selOrang[$b->k->id] ?? []), $b->total_um + $b->total_lembur);
                $sel[5] = isset($barisOperator[$b->k->id]) ? $rumus('=Q'.$barisOperator[$b->k->id], $tunj) : $kosong();
                $sel[6] = $rumus("=SUM(E{$r}:F{$r})", $b->total, $rpTebal);
                $sel[7] = $teks(trim(implode(' ', array_filter([$b->k->bank, $b->k->no_rekening, $b->k->nama_rekening ? 'a.n. '.$b->k->nama_rekening : null]))));
            }
            if ($o = $operator[$i] ?? null) {
                $m = $o->mingguan;
                $sel[10] = $teks($o->k->nama);
                $sel[11] = $angka($m['basic_mingguan'] ?? 0, $rp);
                $sel[12] = $angka($m['transport_mingguan'] ?? 0, $rp);
                $sel[13] = $angka($m['kesehatan_mingguan'] ?? 0, $rp);
                $sel[14] = $angka($o->jam_hm ?: 0);
                $sel[15] = $rumus("=O{$r}*".(int) $o->tarif_hm, $o->total_hm);
                $sel[16] = $rumus("=SUM(L{$r}:N{$r})+P{$r}", $o->total_mingguan + $o->total_hm, $rpTebal);
            }
            $w->addRow(new Row(array_map(fn ($c) => $c ?? $kosong(), $sel)));
        }
        $n += $baris;
        $akhir = max($n, $awal);
        $total = array_fill(0, 17, null);
        $total[1] = $teks('TOTAL', $tebal);
        $total[4] = $rumus("=SUM(E{$awal}:E{$akhir})", $dibayar->sum(fn ($b) => $b->total_um + $b->total_lembur), $rpTebal);
        $total[5] = $rumus("=SUM(F{$awal}:F{$akhir})", $dibayar->sum(fn ($b) => $b->total_mingguan + $b->total_hm), $rpTebal);
        $total[6] = $rumus("=SUM(G{$awal}:G{$akhir})", $dibayar->sum('total'), $rpTebal);
        if ($operator->isNotEmpty()) {
            $total[10] = $teks('TOTAL', $tebal);
            foreach (['L' => 11, 'M' => 12, 'N' => 13, 'P' => 15, 'Q' => 16] as $kol => $i) {
                $total[$i] = $rumus("=SUM({$kol}{$awal}:{$kol}{$akhir})", match ($kol) {
                    'L' => $operator->sum(fn ($o) => $o->mingguan['basic_mingguan'] ?? 0), 'M' => $operator->sum(fn ($o) => $o->mingguan['transport_mingguan'] ?? 0),
                    'N' => $operator->sum(fn ($o) => $o->mingguan['kesehatan_mingguan'] ?? 0), 'P' => $operator->sum('total_hm'),
                    'Q' => $operator->sum(fn ($o) => $o->total_mingguan + $o->total_hm),
                }, $rpTebal);
            }
        }
        $w->addRow(new Row(array_map(fn ($c) => $c ?? $kosong(), $total)));
        $n++;
        $barisTotalOrang = $n;

        // 3. Rekap per tahap: UM & lembur dari subtotal harian, tunjangan & HM operator dibebankan ke tahap hari kerjanya (sama dengan ERP).
        $tunjTahap = [];
        foreach ($dibayar as $b) {
            foreach ($b->hari as $x) {
                if ($x->bayar_hm + $x->bayar_mingguan > 0) {
                    $t = $x->tahap ?? RekapAbsenMingguan::BELUM_TAHAP;
                    $tunjTahap[$t] = ($tunjTahap[$t] ?? 0) + $x->bayar_hm + $x->bayar_mingguan;
                }
            }
        }
        $semuaTahap = collect(array_keys($nilaiTahap + $tunjTahap))
            ->sortByDesc(fn ($t) => ($nilaiTahap[$t] ?? 0) + ($tunjTahap[$t] ?? 0))->values();
        $w->addRow(Row::fromValues([]));
        $w->addRow(Row::fromValues(['REKAP PER TAHAP'], $tebal));
        $w->addRow(Row::fromValues(['No', 'Tahap / Proyek', '', '', 'UM & lembur', 'Tunjangan & HM', 'Total'], $kepala));
        $n += 3;
        $awal = $n + 1;
        foreach ($semuaTahap as $i => $t) {
            $r = $awal + $i;
            $w->addRow(new Row([$angka($i + 1), $teks($t), $kosong(), $kosong(), $rumus($sum($selTahap[$t] ?? []), $nilaiTahap[$t] ?? 0),
                isset($tunjTahap[$t]) ? $angka($tunjTahap[$t], $rp) : $kosong(), $rumus("=SUM(E{$r}:F{$r})", ($nilaiTahap[$t] ?? 0) + ($tunjTahap[$t] ?? 0), $rpTebal)]));
        }
        $n += $semuaTahap->count();
        $akhir = max($n, $awal);
        $w->addRow(new Row([$kosong(), $teks('TOTAL', $tebal), $kosong(), $kosong(),
            $rumus("=SUM(E{$awal}:E{$akhir})", array_sum($nilaiTahap), $rpTebal), $rumus("=SUM(F{$awal}:F{$akhir})", array_sum($tunjTahap), $rpTebal),
            $rumus("=SUM(G{$awal}:G{$akhir})", array_sum($nilaiTahap) + array_sum($tunjTahap), $rpTebal),
            $rumus("=IF(G".($n + 1)."=G{$barisTotalOrang},\"✓ sama dengan total per orang\",\"✗ beda dengan total per orang\")",
                abs(array_sum($nilaiTahap) + array_sum($tunjTahap) - $dibayar->sum('total')) < 1 ? '✓ sama dengan total per orang' : '✗ beda dengan total per orang')]));
    }
}
