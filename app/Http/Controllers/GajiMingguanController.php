<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\RekapAbsen;
use App\Support\RekapAbsenMingguan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap Absen & Gaji Mingguan (owner 30 Okt 2026, menggantikan versi awal 27 Okt): grid karyawan × hari Senin–Minggu dari absensi,
 * admin menyetujui absen belum lengkap, mengisi tahap kerja, mengubah UM per orang; lembur ½ UM; rekap per tahap; Excel seperti
 * file UM admin. Hitungan di App\Support\RekapAbsenMingguan.
 */
class GajiMingguanController extends Controller
{
    public function index(Request $request): View
    {
        return view('karyawan.gaji-mingguan', RekapAbsenMingguan::data(RekapAbsenMingguan::senin($request->query('minggu'))));
    }

    /** Simpan keputusan satu karyawan satu hari (bayar / tidak / ikut absen, lembur, tahap). */
    public function hari(Request $request): JsonResponse
    {
        $v = $request->validate([
            'karyawan_id' => ['required', 'integer', 'exists:karyawan,id'], 'tanggal' => ['required', 'date'],
            'hadir' => ['required', 'in:auto,1,0'], 'lembur' => ['required', 'in:auto,1,0'],
            'tahap' => ['nullable', 'integer', 'exists:proyek_tahap,id'], 'semua_minggu' => ['nullable', 'boolean'], 'catatan' => ['nullable', 'string', 'max:300'],
        ]);
        $tanggal = \Carbon\Carbon::parse($v['tanggal'])->toDateString();
        $this->simpan((int) $v['karyawan_id'], $tanggal, [
            'hadir' => $v['hadir'] === 'auto' ? null : (bool) $v['hadir'], 'lembur' => $v['lembur'] === 'auto' ? null : (bool) $v['lembur'],
            'proyek_tahap_id' => $v['tahap'] ?? null, 'catatan' => trim((string) ($v['catatan'] ?? '')) ?: null,
        ], $request->user()->id);

        // Tahap yang sama untuk semua hari kerja orang itu minggu ini.
        if (! empty($v['semua_minggu']) && ! empty($v['tahap'])) {
            $b = RekapAbsenMingguan::data(RekapAbsenMingguan::senin($tanggal))['baris']->first(fn ($b) => $b->k->id === (int) $v['karyawan_id']);
            foreach ($b?->hari ?? [] as $d => $h) {
                if ($d !== $tanggal && ($h->hadir || $h->tunggu)) {
                    $this->simpan((int) $v['karyawan_id'], $d, ['proyek_tahap_id' => (int) $v['tahap']], $request->user()->id, true);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /** UM per hari satu orang (kosong = ikut tarif jabatan). */
    public function um(Request $request): JsonResponse
    {
        $v = $request->validate(['karyawan_id' => ['required', 'integer', 'exists:karyawan,id'], 'um' => ['nullable', 'integer', 'min:0', 'max:5000000']]);
        Karyawan::whereKey($v['karyawan_id'])->update(['um_harian' => $v['um'] ?: null]);

        return response()->json(['ok' => true]);
    }

    /** Setujui semua absen / lembur yang belum lengkap minggu itu (setelah admin memeriksa). */
    public function setujuiSemua(Request $request): RedirectResponse
    {
        $n = 0;
        foreach (RekapAbsenMingguan::data(RekapAbsenMingguan::senin($request->input('minggu')))['baris'] as $b) {
            foreach ($b->hari as $d => $h) {
                $ubah = array_filter(['hadir' => $h->tunggu ? true : null, 'lembur' => $h->tunggu_lembur ? true : null], fn ($x) => $x !== null);
                if ($ubah) {
                    $this->simpan($b->k->id, $d, $ubah, $request->user()->id, true);
                    $n++;
                }
            }
        }

        return back()->with('success', "{$n} hari absen / lembur yang belum lengkap disetujui (dibayar).");
    }

    /**
     * Simpan rekap satu hari. $sebagian = hanya kolom yang diberikan yang diubah; selain itu semua kolom diganti.
     * Baris yang kembali "ikut absen" seluruhnya (tanpa tahap/catatan) dihapus.
     */
    private function simpan(int $karyawan, string $tanggal, array $isi, int $user, bool $sebagian = false): void
    {
        $r = RekapAbsen::firstOrNew(['karyawan_id' => $karyawan, 'tanggal' => $tanggal]);
        $r->fill($sebagian ? $isi : $isi + ['hadir' => null, 'lembur' => null, 'proyek_tahap_id' => null, 'catatan' => null]);
        // Mandor tetap tercatat sebagai pengisi bila admin hanya mengubah tahap / catatan.
        if (! $r->exists || $r->isDirty(['hadir', 'lembur'])) {
            $r->sumber = 'admin';
        }
        $r->user_id = $user;
        if ($r->hadir === null && $r->lembur === null && ! $r->proyek_tahap_id && ! $r->catatan) {
            if ($r->exists) {
                $r->delete();
            }

            return;
        }
        $r->save();
    }

    public function unduh(Request $request): StreamedResponse
    {
        $d = RekapAbsenMingguan::data(RekapAbsenMingguan::senin($request->query('minggu')));
        $senin = $d['senin'];
        $minggu = $senin->copy()->addDays(6);

        return response()->streamDownload(function () use ($d, $senin, $minggu) {
            $tebal = (new Style)->setFontBold();
            $kepala = (new Style)->setFontBold()->setBackgroundColor('DDE7F0');
            $rp = (new Style)->setFormat('#,##0');
            $rpTebal = (new Style)->setFontBold()->setFormat('#,##0');
            $teks = fn ($v, ?Style $g = null) => new Cell\StringCell((string) $v, $g);
            $angka = fn ($v, ?Style $g = null) => $v === null || $v === '' ? new Cell\StringCell('') : new Cell\NumericCell($v, $g);
            $dibayar = $d['baris']->filter(fn ($b) => $b->total > 0)->values();

            $w = new Writer;
            $w->openToFile('php://output');

            // 1. Harian (seperti file UM admin): per hari yang dibayar + baris lembur, subtotal per hari.
            $s = $w->getCurrentSheet();
            $s->setName('Harian');
            foreach ([5, 26, 26, 22, 12, 18, 26] as $i => $l) {
                $s->setColumnWidth($l, $i + 1);
            }
            $w->addRow(Row::fromValues(['UANG MAKAN KARYAWAN'], $tebal));
            $w->addRow(Row::fromValues(['Periode '.$senin->translatedFormat('j M').' – '.$minggu->translatedFormat('j M Y')]));
            $w->addRow(Row::fromValues([]));
            $w->addRow(Row::fromValues(['No', 'Hari, Tanggal', 'Nama Karyawan', 'Jabatan', 'UM', 'Keterangan', 'Proyek'], $kepala));
            $no = 0;
            foreach ($d['hari'] as $h) {
                $tgl = $h->toDateString();
                $hariIni = $dibayar->map(fn ($b) => [$b, $b->hari[$tgl]])->filter(fn ($x) => $x[1]->bayar > 0)->values();
                if ($hariIni->isEmpty()) {
                    continue;
                }
                $label = $h->translatedFormat('l, j F Y');
                foreach ($hariIni as [$b, $x]) {
                    $ket = $x->masuk || $x->pulang ? ($x->masuk ?? '?').' - '.($x->pulang ?? '?').($x->tunggu === false && $x->absen === 'kurang' ? ' (disetujui)' : '')
                        : ($x->sumber === 'mandor' ? 'diisi mandor' : 'disetujui admin');
                    $w->addRow(new Row([$angka(++$no), $teks($label), $teks($b->k->nama), $teks($b->k->jabatan), $angka($x->bayar, $rp), $teks($ket),
                        $teks($x->tahap ?? RekapAbsenMingguan::BELUM_TAHAP)]));
                }
                foreach ($hariIni->filter(fn ($x) => $x[1]->bayar_lembur > 0) as [$b, $x]) {
                    $jam = $x->lembur_mulai || $x->lembur_selesai ? ($x->lembur_mulai ?? '?').' - '.($x->lembur_selesai ?? '?') : '';
                    $w->addRow(new Row([$teks(''), $teks($label), $teks($b->k->nama), $teks('Lembur (½ UM)'), $angka($x->bayar_lembur, $rp), $teks($jam),
                        $teks($x->tahap ?? RekapAbsenMingguan::BELUM_TAHAP)]));
                }
                $w->addRow(new Row([$teks(''), $teks(''), $teks(''), $teks('Subtotal '.$h->translatedFormat('D j/n'), $tebal),
                    $angka($hariIni->sum(fn ($x) => $x[1]->bayar + $x[1]->bayar_lembur), $rpTebal)]));
            }

            // 2. Rekap per orang.
            $s = $w->addNewSheetAndMakeItCurrent();
            $s->setName('Rekap per orang');
            $s->setSheetView((new SheetView)->setFreezeRow(2));
            foreach ([5, 26, 22, 10, 12, 8, 8, 14, 14, 14, 36] as $i => $l) {
                $s->setColumnWidth($l, $i + 1);
            }
            $w->addRow(Row::fromValues(['No', 'Nama Karyawan', 'Jabatan', 'Site', 'UM / hari', 'Hari', 'Lembur', 'Total UM', 'Total lembur', 'Total', 'Rekening'], $kepala));
            foreach ($dibayar as $i => $b) {
                $n = $i + 2;
                $w->addRow(new Row([$angka($i + 1), $teks($b->k->nama), $teks($b->k->jabatan), $teks($b->k->site), $angka($b->um, $rp), $angka($b->hadir), $angka($b->lembur),
                    $angka($b->total_um, $rp), $angka($b->total_lembur, $rp), new Cell\FormulaCell("=H{$n}+I{$n}", $rpTebal, $b->total),
                    $teks(trim(implode(' ', array_filter([$b->k->bank, $b->k->no_rekening, $b->k->nama_rekening ? 'a.n. '.$b->k->nama_rekening : null]))))]));
            }
            $akhir = max($dibayar->count() + 1, 2);
            $w->addRow(new Row([$teks(''), $teks('TOTAL', $tebal), $teks(''), $teks(''), $teks(''),
                new Cell\FormulaCell("=SUM(F2:F{$akhir})", $tebal, $d['ringkas']['hari']), new Cell\FormulaCell("=SUM(G2:G{$akhir})", $tebal, $d['ringkas']['lembur']),
                new Cell\FormulaCell("=SUM(H2:H{$akhir})", $rpTebal, $d['ringkas']['total_um']), new Cell\FormulaCell("=SUM(I2:I{$akhir})", $rpTebal, $d['ringkas']['total_lembur']),
                new Cell\FormulaCell("=SUM(J2:J{$akhir})", $rpTebal, $d['ringkas']['total'])]));

            // 3. Rekap per tahap (biaya UM per proyek).
            $s = $w->addNewSheetAndMakeItCurrent();
            $s->setName('Rekap per tahap');
            $s->setColumnWidth(36, 1);
            $s->setColumnWidth(16, 2);
            $w->addRow(Row::fromValues(['Tahap / Proyek', 'Total'], $kepala));
            foreach ($d['perTahap'] as $t => $v) {
                $w->addRow(new Row([$teks($t), $angka($v, $rp)]));
            }
            $w->addRow(new Row([$teks('TOTAL', $tebal), new Cell\FormulaCell('=SUM(B2:B'.max($d['perTahap']->count() + 1, 2).')', $rpTebal, $d['perTahap']->sum())]));

            // 4. Belum disetujui (belum ikut dibayar sampai admin menyetujui).
            $tunggu = $d['baris']->flatMap(fn ($b) => collect($b->hari)->filter(fn ($x) => $x->tunggu || $x->tunggu_lembur)->map(fn ($x) => [$b, $x])->values());
            if ($tunggu->isNotEmpty()) {
                $s = $w->addNewSheetAndMakeItCurrent();
                $s->setName('Belum disetujui');
                foreach ([26, 22, 16, 10, 10, 13, 13] as $i => $l) {
                    $s->setColumnWidth($l, $i + 1);
                }
                $w->addRow(Row::fromValues(['Nama', 'Jabatan', 'Tanggal', 'Masuk', 'Pulang', 'Mulai lembur', 'Selesai lembur'], $kepala));
                foreach ($tunggu as [$b, $x]) {
                    $w->addRow(Row::fromValues([$b->k->nama, $b->k->jabatan, $x->tanggal->translatedFormat('D j M'), $x->masuk ?? '–', $x->pulang ?? '–', $x->lembur_mulai ?? '–', $x->lembur_selesai ?? '–']));
                }
            }
            $w->close();
        }, 'Rekap Absen & UM '.$senin->translatedFormat('j M').' - '.$minggu->translatedFormat('j M Y').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
