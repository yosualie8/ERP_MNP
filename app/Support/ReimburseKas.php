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
use OpenSpout\Common\Entity\Style\Style;
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
