<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasReimburse;
use App\Models\KasTransfer;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Reimburse Kas Harian (menu Reimburse Kas, seperti Reimburse UJ): transfer keluar yang masih punya detail belum reimburse
 * dipilih owner, lalu ditandai sudah reimburse di aplikasi (StatusReimburse::tandai → ditulis ke lembar "Sudah Reimburse").
 * Baris "Biaya Transfer Keluar" ikut transfer induknya. Setiap batch dicatat (kas_reimburse) beserta sidik detailnya.
 */
class ReimburseKas
{
    /**
     * Antrean per transfer (biaya transfernya digabung), urut dari yang paling lama.
     *
     * @return Collection<int, array>
     */
    public static function antrean(): Collection
    {
        $belum = KasBon::query()->join('kas_transfer', 'kas_transfer.id', '=', 'kas_bon.kas_transfer_id')
            ->leftJoin('kas_sudah_reimburse', 'kas_sudah_reimburse.id_transaksi', '=', 'kas_bon.id_transaksi')
            ->whereNull('kas_sudah_reimburse.id_transaksi')->where('kas_transfer.kredit', '>', 0)
            ->pluck('kas_bon.kas_transfer_id')->unique();
        $transfer = KasTransfer::whereIn('id', $belum)->with(['bon.kodeGl', 'kasBulan'])->get();
        $sudah = StatusReimburse::untuk($transfer->flatMap(fn ($t) => $t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t)))->all());

        // Biaya transfer → induknya = transfer bukan-biaya terdekat di atasnya pada lembar yang sama (bila induknya juga di antrean).
        $biaya = fn (KasTransfer $t) => stripos((string) $t->keterangan, 'biaya transfer') !== false && $t->kredit <= TulisKasSheet::BIAYA_TRANSFER_MAKS;
        $induk = [];
        foreach ($transfer->filter($biaya) as $b) {
            $atas = KasTransfer::where('kas_bulan_id', $b->kas_bulan_id)->where('baris', '<', $b->baris)->orderByDesc('baris')
                ->get(['id', 'keterangan', 'kredit'])->first(fn ($x) => ! $biaya($x));
            if ($atas && $transfer->contains('id', $atas->id)) {
                $induk[$b->id] = $atas->id;
            }
        }

        $detail = fn (KasTransfer $t) => $t->bon->reject(fn ($b) => array_key_exists(StatusReimburse::idBon($b, $t), $sudah))
            ->map(fn (KasBon $b) => [
                'id' => StatusReimburse::idBon($b, $t), 'no_id' => (int) $b->no_id, 'pic' => $b->pic, 'ket' => $b->keterangan,
                'kode_gl' => $b->kodeGl?->kode_asli, 'nominal' => (int) $b->nominal, 'fee' => false, // true = baris biaya transfer milik induk
            ])->values();

        return $transfer->reject(fn ($t) => isset($induk[$t->id]))
            ->sortBy(fn ($t) => $t->tanggal->format('Ymd').sprintf('%07d', $t->baris))->values()
            ->map(function (KasTransfer $t) use ($transfer, $induk, $detail, $sudah) {
                $anak = $transfer->filter(fn ($x) => ($induk[$x->id] ?? null) === $t->id);
                $baris = $detail($t)->concat($anak->flatMap(fn ($x) => $detail($x)->map(fn ($d) => [...$d, 'fee' => true])));

                return [
                    'id' => $t->id, 'ids' => [$t->id, ...$anak->pluck('id')->all()], 'no_id' => $t->no_id, 'lembar' => $t->kasBulan->lembar,
                    'tanggal' => $t->tanggal->toDateString(), 'tgl' => $t->tanggal->translatedFormat('j M Y'),
                    'nama' => $t->nama_tujuan, 'bank' => $t->bank_tujuan, 'rekening' => $t->no_rek_tujuan, 'ket' => $t->keterangan,
                    'sebagian' => $t->bon->contains(fn ($b) => array_key_exists(StatusReimburse::idBon($b, $t), $sudah)),
                    'total' => (int) $baris->sum('nominal'), 'detail' => $baris->all(),
                ];
            });
    }

    /**
     * Tandai transfer-transfer ini (beserta biaya transfernya) sudah reimburse pada $tanggal dan catat batch-nya.
     *
     * @param  int[]  $transferIds  id transfer induk dari antrean
     */
    public static function tandai(array $transferIds, CarbonInterface $tanggal, ?int $target, ?int $userId): KasReimburse
    {
        return Cache::lock('tandai-reimburse-kas', 60)->block(30, function () use ($transferIds, $tanggal, $target, $userId) {
            $pilih = self::antrean()->whereIn('id', array_map('intval', $transferIds))->values();
            if ($pilih->count() !== count(array_unique($transferIds))) {
                throw new RuntimeException('Sebagian transfer yang dipilih sudah tidak berstatus belum reimburse (mungkin baru ditandai/diubah). Muat ulang halaman lalu pilih lagi.');
            }
            $baris = $pilih->flatMap(fn ($t) => collect($t['detail'])->map(fn ($d) => [...$d, 'tanggal' => $t['tanggal'], 'tujuan' => $t['nama'], 'no_id_transfer' => $t['no_id']]));
            if ($baris->isEmpty()) {
                throw new RuntimeException('Tidak ada detail yang bisa ditandai.');
            }

            return DB::transaction(function () use ($pilih, $baris, $tanggal, $target, $userId) {
                StatusReimburse::tandai($baris->pluck('id')->all(), $tanggal instanceof \Illuminate\Support\Carbon ? $tanggal : \Illuminate\Support\Carbon::instance($tanggal), $userId);

                return KasReimburse::create([
                    'tanggal' => $tanggal->toDateString(), 'target' => $target, 'total' => (int) $baris->sum('nominal'),
                    'jumlah_transfer' => $pilih->count(), 'jumlah_baris' => $baris->count(), 'user_id' => $userId,
                    // Sidik: ID, NO ID, tanggal, nominal & keterangan saat ditandai — untuk mengecek bila kelak transaksinya berubah.
                    'isi' => $baris->map(fn ($d) => collect($d)->only(['id', 'no_id', 'no_id_transfer', 'tanggal', 'tujuan', 'pic', 'ket', 'kode_gl', 'nominal', 'fee'])->all())->values()->all(),
                ]);
            });
        });
    }

    /**
     * File Excel transaksi Kas Harian yang BELUM reimburse, berformat sama dengan lembar "Mutasi Reimburse" di spreadsheet
     * "(0) Transaksi Belum Reimburse V3": kolom A–R (No … Reimburse), header biru muda tebal, baris 1 dibekukan, font 12.
     * Isi baris = cermin Mutasi Reimburse (CerminReimburse::baris), termasuk kolom UJ bila detailnya tertaut ke Kas UJ;
     * Saldo = akumulasi nilai belum reimburse dari atas; Reimburse = "Belum".
     */
    public static function excelBelum(): string
    {
        $antrean = self::antrean();
        $belum = $antrean->flatMap(fn ($t) => collect($t['detail'])->pluck('id'))->flip();
        $baris = KasTransfer::whereIn('id', $antrean->flatMap(fn ($t) => $t['ids']))->with('bon.kodeGl')->get()
            ->flatMap(fn (KasTransfer $t) => CerminReimburse::baris($t))
            ->filter(fn ($r) => isset($belum[$r['id']]))
            ->sortBy(fn ($r) => sprintf('%010d', (int) $r['no']).$r['id'])->values();

        $path = storage_path('app/kas-belum-reimburse-'.now()->format('YmdHis').'.xlsx');
        $opsi = new Options();
        // Lebar kolom mengikuti lembar Mutasi Reimburse (piksel ÷ 7).
        foreach ([48, 133, 66, 90, 90, 58, 369, 52, 94, 136, 120, 88, 193, 166, 95, 91, 103, 103] as $i => $px) {
            $opsi->setColumnWidth(round($px / 7, 1), $i + 1);
        }
        $w = new Writer($opsi);
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Belum Reimburse');
        $w->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(2));

        $dasar = fn () => (new Style())->setFontSize(12);
        $kepala = $dasar()->setFontBold()->setBackgroundColor('BDD6EE')->setShouldWrapText()->setCellAlignment(CellAlignment::CENTER);
        $tengah = $dasar()->setCellAlignment(CellAlignment::CENTER);
        $tanggal = $dasar()->setFormat('dd/mmm/yyyy')->setCellAlignment(CellAlignment::CENTER);
        $tanggalUj = $dasar()->setFormat('dd-mmm-yy')->setCellAlignment(CellAlignment::CENTER);
        $angka = $dasar()->setFormat('#,##0');
        $teks = $dasar();

        $w->addRow(Row::fromValues(['No', 'ID Transaksi Kas', 'Id Transaksi UJ', 'Tanggal Reimburse', 'Tanggal Transaksi Terjadi', 'PIC', 'Keterangan',
            'Jenis Mobil', 'No DO ', 'Galian', 'Kategori', 'Tanggal Reimburse UJ', 'Kode GL', 'Bon', 'Debit', 'Kredit', 'Saldo', 'Reimburse'], $kepala));
        $tgl = fn ($v) => $v ? new \DateTimeImmutable((string) $v) : '';
        $nilai = fn ($v) => is_string($v) && str_starts_with($v, "'") ? substr($v, 1) : $v; // tanda kutip penanda teks di sheet
        $akumulasi = 0;
        foreach ($baris as $r) {
            $s = $r['sel'];
            $akumulasi += (int) ($s[15] ?? 0) - (int) ($s[14] ?: 0);
            $w->addRow(new Row([
                Cell::fromValue($s[0] ?? '', $tengah), Cell::fromValue((string) $s[1], $teks), Cell::fromValue((string) $nilai($s[2] ?? ''), $teks),
                Cell::fromValue($tgl($s[3] ?? null), $tanggal), Cell::fromValue($tgl($s[4] ?? null), $tanggal),
                Cell::fromValue((string) $nilai($s[5] ?? ''), $teks), Cell::fromValue((string) $nilai($s[6] ?? ''), $teks),
                Cell::fromValue((string) $nilai($s[7] ?? ''), $tengah), Cell::fromValue((string) $nilai($s[8] ?? ''), $tengah),
                Cell::fromValue((string) $nilai($s[9] ?? ''), $tengah), Cell::fromValue((string) $nilai($s[10] ?? ''), $tengah),
                Cell::fromValue($tgl($s[11] ?? null), $tanggalUj),
                Cell::fromValue((string) $nilai($s[12] ?? ''), $teks), Cell::fromValue((string) $nilai($s[13] ?? ''), $teks),
                Cell::fromValue(($s[14] ?? '') === '' ? '' : (int) $s[14], $angka), Cell::fromValue((int) ($s[15] ?? 0), $angka),
                Cell::fromValue($akumulasi, $angka), Cell::fromValue('Belum', $tengah),
            ]));
        }
        // Tanpa baris ringkasan/total di bawah (permintaan owner): hanya header + baris transaksi.
        $w->close();

        return $path;
    }

    /** File Excel batch reimburse (untuk diunduh / dibagikan ke WhatsApp). */
    public static function excel(KasReimburse $r): string
    {
        $path = storage_path('app/reimburse-kas-'.$r->id.'.xlsx');
        $opsi = new Options();
        foreach ([1 => 5, 2 => 20, 3 => 11, 4 => 22, 5 => 12, 6 => 46, 7 => 28, 8 => 14] as $kolom => $lebar) {
            $opsi->setColumnWidth($lebar, $kolom);
        }
        $w = new Writer($opsi);
        $w->openToFile($path);

        $judul = (new Style())->setFontBold()->setFontSize(14);
        $kepala = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('B4232C');
        $rupiah = (new Style())->setFormat('#,##0');
        $totalStyle = (new Style())->setFontBold()->setFormat('#,##0')->setBackgroundColor('F2F2F2');

        $w->addRow(Row::fromValues(['REIMBURSE KAS HARIAN — PT MULTI NIAGA PUTRA'], $judul));
        $w->addRow(Row::fromValues(['Tanggal reimburse', '', $r->tanggal->translatedFormat('j F Y')]));
        $w->addRow(new Row([Cell::fromValue('Total'), Cell::fromValue(''), Cell::fromValue($r->total, (new Style())->setFontBold()->setFormat('"Rp "#,##0'))]));
        $w->addRow(Row::fromValues(['Jumlah', '', "{$r->jumlah_transfer} transfer · {$r->jumlah_baris} transaksi detail"]));
        $w->addRow(Row::fromValues(['Sumber', '', 'Kas Harian MNP (rekening Bank Jago)']));
        $w->addRow(Row::fromValues([]));
        $w->addRow(Row::fromValues(['No', 'ID Transaksi', 'Tanggal', 'Tujuan', 'PIC', 'Keterangan', 'Kode GL', 'Nominal'], $kepala));
        foreach ($r->isi as $i => $d) {
            $w->addRow(new Row([
                Cell::fromValue($i + 1), Cell::fromValue((string) $d['id']),
                Cell::fromValue($d['tanggal'] ? \Carbon\Carbon::parse($d['tanggal'])->format('d/m/Y') : ''),
                Cell::fromValue((string) ($d['tujuan'] ?? '')), Cell::fromValue((string) ($d['pic'] ?? '')), Cell::fromValue((string) ($d['ket'] ?? '')),
                Cell::fromValue((string) ($d['kode_gl'] ?? '')), Cell::fromValue((int) $d['nominal'], $rupiah),
            ]));
        }
        $w->addRow(new Row([...array_map(fn ($v) => Cell::fromValue($v, $totalStyle), ['', '', '', '', '', 'TOTAL', '']), Cell::fromValue($r->total, $totalStyle)]));
        $w->close();

        return $path;
    }
}
