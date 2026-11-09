<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\RekapAbsen;
use App\Models\TunjanganMingguan;
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
 * file UM admin; operator alat berat + basic/transport/kesehatan mingguan & HM (3 Nov 2026). Hitungan di App\Support\RekapAbsenMingguan.
 */
class GajiMingguanController extends Controller
{
    public function index(Request $request): View
    {
        return view('karyawan.gaji-mingguan', RekapAbsenMingguan::data(RekapAbsenMingguan::senin($request->query('minggu')))
            + ['bolehJam' => \App\Support\MenuAkses::boleh($request->user(), 'absen-ubah-jam')]);
    }

    /** Simpan keputusan satu karyawan satu hari (bayar / tidak / ikut absen, lembur, HM operator, tahap). */
    public function hari(Request $request): JsonResponse
    {
        $v = $request->validate([
            'karyawan_id' => ['required', 'integer', 'exists:karyawan,id'], 'tanggal' => ['required', 'date'],
            'hadir' => ['required', 'in:auto,1,0'], 'lembur' => ['required', 'in:auto,1,0'], 'hm' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'tahap' => ['nullable', 'integer', 'exists:proyek_tahap,id'], 'semua_minggu' => ['nullable', 'boolean'], 'catatan' => ['nullable', 'string', 'max:300'],
        ]);
        $tanggal = \Carbon\Carbon::parse($v['tanggal'])->toDateString();
        $this->simpan((int) $v['karyawan_id'], $tanggal, [
            'hadir' => $v['hadir'] === 'auto' ? null : (bool) $v['hadir'], 'lembur' => $v['lembur'] === 'auto' ? null : (bool) $v['lembur'],
            'proyek_tahap_id' => $v['tahap'] ?? null, 'catatan' => trim((string) ($v['catatan'] ?? '')) ?: null,
            'hm' => isset($v['hm']) && (float) $v['hm'] > 0 ? round((float) $v['hm'], 1) : null,
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

    /** Tunjangan mingguan (basic / transport / kesehatan) satu orang untuk satu minggu; isian kosong = ikut tarif jabatan. */
    public function tunjangan(Request $request): JsonResponse
    {
        $v = $request->validate([
            'karyawan_id' => ['required', 'integer', 'exists:karyawan,id'], 'minggu' => ['required', 'date'], 'catatan' => ['nullable', 'string', 'max:300'],
            'basic_mingguan' => ['nullable', 'integer', 'min:0', 'max:10000000'], 'transport_mingguan' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'kesehatan_mingguan' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);
        $isi = collect(\App\Models\JabatanMenu::MINGGUAN)->mapWithKeys(fn ($l, $kol) => [$kol => isset($v[$kol]) ? (int) $v[$kol] : null])->all();
        $catatan = trim((string) ($v['catatan'] ?? '')) ?: null;
        $kunci = ['karyawan_id' => (int) $v['karyawan_id'], 'senin' => RekapAbsenMingguan::senin($v['minggu'])->toDateString()];
        if (collect($isi)->every(fn ($x) => $x === null) && ! $catatan) {
            TunjanganMingguan::where($kunci)->delete();
        } else {
            TunjanganMingguan::updateOrCreate($kunci, $isi + ['catatan' => $catatan, 'user_id' => $request->user()->id]);
        }

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
     * Baris yang kembali "ikut absen" seluruhnya (tanpa HM/tahap/catatan) dihapus.
     */
    private function simpan(int $karyawan, string $tanggal, array $isi, int $user, bool $sebagian = false): void
    {
        $r = RekapAbsen::firstOrNew(['karyawan_id' => $karyawan, 'tanggal' => $tanggal]);
        $r->fill($sebagian ? $isi : $isi + ['hadir' => null, 'lembur' => null, 'hm' => null, 'proyek_tahap_id' => null, 'catatan' => null]);
        // Mandor tetap tercatat sebagai pengisi bila admin hanya mengubah tahap / catatan.
        if (! $r->exists || $r->isDirty(['hadir', 'lembur'])) {
            $r->sumber = 'admin';
        }
        $r->user_id = $user;
        if ($r->hadir === null && $r->lembur === null && ! $r->hm && ! $r->proyek_tahap_id && ! $r->catatan) {
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
            $angka = fn ($v, ?Style $g = null) => $v === null || $v === '' ? new Cell\StringCell('', null) : new Cell\NumericCell($v, $g);
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
                        : ($x->jam_mandor ? ($x->jam_mandor['masuk'] ?? '?').' - '.($x->jam_mandor['pulang'] ?? '?').' (ikut '.$x->jam_mandor['nama'].')'
                            : ($x->sumber === 'mandor' ? 'diisi mandor (mandor tidak absen)' : 'disetujui admin'));
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
            foreach ([5, 26, 22, 10, 12, 8, 8, 14, 14, 12, 12, 14, 9, 9, 12, 14, 36] as $i => $l) {
                $s->setColumnWidth($l, $i + 1);
            }
            // Basic / transport / kesehatan / HM: operator alat berat (tarif di Master Jabatan); Uang HM = HM × tarif HM.
            $w->addRow(Row::fromValues(['No', 'Nama Karyawan', 'Jabatan', 'Site', 'UM / hari', 'Hari', 'Lembur', 'Total UM', 'Total lembur',
                'Basic', 'Transport', 'Uang kesehatan', 'HM (jam)', 'Tarif HM', 'Uang HM', 'Total', 'Rekening'], $kepala));
            foreach ($dibayar as $i => $b) {
                $n = $i + 2;
                $m = $b->mingguan;
                $w->addRow(new Row([$angka($i + 1), $teks($b->k->nama), $teks($b->k->jabatan), $teks($b->k->site), $angka($b->um, $rp), $angka($b->hadir), $angka($b->lembur),
                    $angka($b->total_um, $rp), $angka($b->total_lembur, $rp), $angka($m['basic_mingguan'] ?: null, $rp), $angka($m['transport_mingguan'] ?: null, $rp),
                    $angka($m['kesehatan_mingguan'] ?: null, $rp), $angka($b->jam_hm ?: null), $angka($b->tarif_hm ?: null, $rp),
                    $b->tarif_hm ? new Cell\FormulaCell("=M{$n}*N{$n}", $rp, $b->total_hm) : $teks(''),
                    new Cell\FormulaCell("=SUM(H{$n}:L{$n})+O{$n}", $rpTebal, $b->total),
                    $teks(trim(implode(' ', array_filter([$b->k->bank, $b->k->no_rekening, $b->k->nama_rekening ? 'a.n. '.$b->k->nama_rekening : null]))))]));
            }
            $akhir = max($dibayar->count() + 1, 2);
            $jumlah = fn (string $kol, $nilai, Style $g) => new Cell\FormulaCell("=SUM({$kol}2:{$kol}{$akhir})", $g, $nilai);
            $kolom = fn (string $k) => $dibayar->sum(fn ($b) => $b->mingguan[$k] ?? 0);
            $w->addRow(new Row([$teks(''), $teks('TOTAL', $tebal), $teks(''), $teks(''), $teks(''),
                $jumlah('F', $d['ringkas']['hari'], $tebal), $jumlah('G', $d['ringkas']['lembur'], $tebal),
                $jumlah('H', $d['ringkas']['total_um'], $rpTebal), $jumlah('I', $d['ringkas']['total_lembur'], $rpTebal),
                $jumlah('J', $kolom('basic_mingguan'), $rpTebal), $jumlah('K', $kolom('transport_mingguan'), $rpTebal), $jumlah('L', $kolom('kesehatan_mingguan'), $rpTebal),
                $jumlah('M', $d['ringkas']['jam_hm'], $tebal), $teks(''), $jumlah('O', $d['ringkas']['total_hm'], $rpTebal),
                $jumlah('P', $d['ringkas']['total'], $rpTebal)]));

            // 2b. HM operator per hari (dasar Uang HM).
            $operator = $dibayar->filter(fn ($b) => $b->operator)->values();
            if ($operator->isNotEmpty()) {
                $s = $w->addNewSheetAndMakeItCurrent();
                $s->setName('HM operator');
                foreach ([26, 22, 9, 9, 9, 9, 9, 9, 9, 10, 10, 14] as $i => $l) {
                    $s->setColumnWidth($l, $i + 1);
                }
                $w->addRow(Row::fromValues(array_merge(['Nama Karyawan', 'Jabatan'], $d['hari']->map(fn ($h) => $h->translatedFormat('D j/n'))->all(),
                    ['Total HM', 'Tarif HM', 'Uang HM']), $kepala));
                foreach ($operator as $i => $b) {
                    $n = $i + 2;
                    $w->addRow(new Row(array_merge([$teks($b->k->nama), $teks($b->k->jabatan)],
                        array_map(fn ($x) => $angka($x->hadir && $x->hm ? $x->hm : null), array_values($b->hari)),
                        [new Cell\FormulaCell("=SUM(C{$n}:I{$n})", $tebal, $b->jam_hm), $angka($b->tarif_hm, $rp), new Cell\FormulaCell("=J{$n}*K{$n}", $rpTebal, $b->total_hm)])));
                }
            }

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
