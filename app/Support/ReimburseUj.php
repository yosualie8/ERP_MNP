<?php

namespace App\Support;

use App\Models\UjDetail;
use App\Models\UjReimburse;
use App\Models\UjTransaksi;
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
 * Reimburse uang jalan: transaksi Kas Seabank berstatus "Belum Reimburse" (kolom M) dipilih per transfer,
 * lalu kolom N (Tanggal Reimburse) diisi di sheet — status di kolom M otomatis menjadi "Sudah Reimburse".
 * Setiap batch dicatat (uj_reimburse) beserta salinan barisnya untuk file Excel yang bisa dibagikan.
 */
class ReimburseUj
{
    /** Antrean: transfer yang punya baris belum reimburse, urut dari yang paling lama. */
    public static function antrean(): Collection
    {
        return UjTransaksi::query()
            ->whereHas('detail', fn ($q) => self::belum($q))
            ->with(['detail' => fn ($q) => self::belum($q)->orderBy('baris')])
            ->orderBy('tanggal')->orderBy('baris')->get()
            ->map(fn (UjTransaksi $t) => [
                'id' => $t->id, 'no_uj' => $t->no_uj, 'tanggal' => $t->tanggal?->toDateString(), 'tgl' => $t->tanggal?->translatedFormat('j M Y'),
                'nama' => $t->nama, 'bank' => $t->bank, 'rekening' => $t->rekening, 'baris' => $t->baris,
                'total' => (int) $t->detail->sum('nominal'), 'cicil' => self::bolehCicil($t->detail),
                'detail' => $t->detail->map(fn (UjDetail $d) => [
                    'id' => $d->id, 'id_uj' => $d->id_uj, 'nama' => $d->nama, 'ket' => $d->keterangan, 'kategori' => $d->kategori,
                    'mobil' => $d->no_mobil, 'do' => $d->no_do, 'nominal' => (int) $d->nominal, 'fee' => $d->biaya_transfer,
                ])->values()->all(),
            ])->values();
    }

    /** Urutan antrean (id transfer, paling lama dulu) — dipakai untuk memaksa reimburse berurutan dari yang paling tua. @return int[] */
    public static function urutan(): array
    {
        return UjTransaksi::query()->whereHas('detail', fn ($q) => self::belum($q))->orderBy('tanggal')->orderBy('baris')->pluck('id')->all();
    }

    public static function belum($q)
    {
        return $q->whereNull('tanggal_reimburse')->where('status', 'like', 'Belum%');
    }

    /**
     * Owner 29 Okt 2026: transfer bayar tanah (semua baris Uang Tanah; biaya transfer boleh ikut) boleh direimburse dicicil per baris —
     * satu transfer bisa berisi puluhan DO senilai belasan juta, jadi tidak harus diambil utuh untuk mencapai nominal.
     */
    public static function bolehCicil(Collection $detail): bool
    {
        $isi = $detail->reject(fn (UjDetail $d) => $d->biaya_transfer);

        return $isi->count() > 1 && $isi->every(fn (UjDetail $d) => ValidasiUj::jenisKategori($d->kategori) === 'ut');
    }

    /**
     * Periksa pilihan baris: transfer harus berurutan dari yang paling lama, dan hanya transfer terakhir yang boleh diambil sebagian
     * (transfer bayar tanah, baris teratas dulu). Mengembalikan pesan salah, atau null bila pilihan sah.
     *
     * @param  int[]  $detailIds
     */
    public static function periksaPilihan(array $detailIds): ?string
    {
        $pilih = array_flip($detailIds);
        $transaksi = UjTransaksi::whereIn('id', UjDetail::whereIn('id', $detailIds)->pluck('uj_transaksi_id')->unique())
            ->with(['detail' => fn ($q) => self::belum($q)->orderBy('baris')])->get()->keyBy('id');
        $semua = self::urutan();
        $urut = array_values(array_filter($semua, fn ($id) => $transaksi->has($id)));
        $lewat = array_values(array_filter(array_slice($semua, 0, count($urut)), fn ($id) => ! $transaksi->has($id)));
        if ($lewat) {
            $t = UjTransaksi::find($lewat[0]);

            return 'Reimburse harus berurutan dari transaksi paling lama: '.count($lewat).' transaksi yang lebih lama belum dipilih (mis. '
                .($t?->no_uj ? 'UJ-'.$t->no_uj.' ' : '').$t?->tanggal?->translatedFormat('j M Y').'). Muat ulang halaman lalu pilih lagi.';
        }
        foreach ($urut as $i => $id) {
            $t = $transaksi[$id];
            $ambil = $t->detail->filter(fn (UjDetail $d) => isset($pilih[$d->id]))->count();
            $awalan = $t->detail->take($ambil)->every(fn (UjDetail $d) => isset($pilih[$d->id]));
            $nama = ($t->no_uj ? 'UJ-'.$t->no_uj.' ' : '').$t->nama;
            if ($ambil === $t->detail->count()) {
                continue;
            }
            if ($i !== count($urut) - 1) {
                return "Transfer {$nama} hanya dipilih sebagian, padahal ada transfer yang lebih baru ikut dipilih. Hanya transfer terakhir yang boleh dicicil.";
            }
            if (! self::bolehCicil($t->detail)) {
                return "Transfer {$nama} tidak bisa dicicil (hanya transfer bayar tanah). Pilih semua barisnya atau lepas transfer itu.";
            }
            if (! $awalan) {
                return "Cicilan transfer {$nama} harus berurutan dari baris paling atas. Muat ulang halaman lalu pilih lagi.";
            }
        }

        return null;
    }

    /**
     * Tandai baris-baris ini sudah reimburse pada $tanggal: isi kolom N di sheet, perbarui aplikasi, catat batch. Biasanya semua baris
     * satu transfer; transfer bayar tanah boleh sebagian (dicicil, lihat bolehCicil()).
     *
     * @param  int[]  $detailIds
     */
    public static function tandai(array $detailIds, CarbonInterface $tanggal, ?int $target, ?int $userId): UjReimburse
    {
        return Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($detailIds, $tanggal, $target, $userId) {
            $detailIds = array_values(array_unique($detailIds));
            $transaksi = UjTransaksi::whereHas('detail', fn ($q) => $q->whereIn('id', $detailIds))
                ->with(['detail' => fn ($q) => self::belum($q)->whereIn('id', $detailIds)->orderBy('baris')])->orderBy('tanggal')->orderBy('baris')->get();
            $baris = $transaksi->flatMap->detail;
            if ($baris->count() !== count($detailIds) || $baris->isEmpty()) {
                throw new RuntimeException('Sebagian transaksi yang dipilih sudah tidak berstatus belum reimburse (mungkin baru diubah/disinkron). Muat ulang halaman lalu pilih lagi.');
            }

            // Pastikan baris di sheet masih sama (ID UJ / biaya transfer & nominal) dan belum diisi tanggal reimburse.
            $sheets = GoogleSheets::wajib();
            $l = KasSeabank::LEMBAR;
            [$dari, $sampai] = [$baris->min('baris'), $baris->max('baris')];
            $isi = $sheets->nilaiMentah(KasSeabank::id(), ["{$l}!A{$dari}:N{$sampai}"])["{$l}!A{$dari}:N{$sampai}"];
            foreach ($baris as $d) {
                $r = $isi[$d->baris - $dari] ?? [];
                $sama = $d->biaya_transfer
                    ? KasSeabank::biayaTransfer((string) ($r[8] ?? ''), (string) ($r[6] ?? '')) && KasSeabank::angka($r[7] ?? null) === (int) $d->nominal
                    : trim((string) ($r[0] ?? '')) === (string) $d->id_uj && KasSeabank::angka($r[7] ?? null) === (int) $d->nominal;
                if (! $sama) {
                    throw new RuntimeException("Isi sheet Kas Seabank baris {$d->baris} sudah berubah sejak sinkron terakhir. Tunggu beberapa menit (data diperbarui otomatis tiap 5 menit) lalu muat ulang di Kas UJ, lalu ulangi.");
                }
                if (trim((string) ($r[13] ?? '')) !== '') {
                    throw new RuntimeException("Baris {$d->baris} ({$d->id_uj}) di sheet sudah berisi tanggal reimburse. Sinkron dulu, lalu ulangi.");
                }
            }

            $data = [];
            foreach ($baris as $d) {
                $data["{$l}!N{$d->baris}"] = [[$tanggal->format('Y-m-d')]];
            }
            $sheets->tulis(KasSeabank::id(), $data);

            return DB::transaction(function () use ($transaksi, $baris, $tanggal, $target, $userId) {
                UjDetail::whereIn('id', $baris->pluck('id'))->update(['tanggal_reimburse' => $tanggal->toDateString(), 'status' => 'Sudah Reimburse']);

                return UjReimburse::create([
                    'tanggal' => $tanggal->toDateString(), 'target' => $target, 'total' => (int) $baris->sum('nominal'),
                    'jumlah_transfer' => $transaksi->count(), 'jumlah_baris' => $baris->count(), 'user_id' => $userId,
                    'isi' => $transaksi->flatMap(fn (UjTransaksi $t) => $t->detail->map(fn (UjDetail $d) => [
                        'id_uj' => $d->id_uj, 'tanggal' => $d->tanggal?->toDateString() ?? $t->tanggal?->toDateString(), 'penerima' => $t->nama,
                        'bank' => $t->bank, 'rekening' => $t->rekening, 'nama' => $d->nama, 'keterangan' => $d->keterangan, 'kategori' => $d->kategori,
                        'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do, 'nominal' => (int) $d->nominal, 'baris' => $d->baris,
                    ]))->values()->all(),
                ]);
            });
        });
    }

    /** File Excel batch reimburse (untuk diunduh / dibagikan ke WhatsApp). */
    public static function excel(UjReimburse $r): string
    {
        $path = storage_path('app/reimburse-uj-'.$r->id.'.xlsx');
        $opsi = new Options();
        foreach ([1 => 5, 2 => 11, 3 => 11, 4 => 20, 5 => 16, 6 => 44, 7 => 16, 8 => 10, 9 => 9, 10 => 14] as $kolom => $lebar) {
            $opsi->setColumnWidth($lebar, $kolom);
        }
        $w = new Writer($opsi);
        $w->openToFile($path);

        $judul = (new Style())->setFontBold()->setFontSize(14);
        $tebal = (new Style())->setFontBold();
        $kepala = (new Style())->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('B4232C');
        $rupiah = (new Style())->setFormat('#,##0');
        $totalStyle = (new Style())->setFontBold()->setFormat('#,##0')->setBackgroundColor('F2F2F2');

        $w->addRow(Row::fromValues(['REIMBURSE UANG JALAN — PT MULTI NIAGA PUTRA'], $judul));
        $w->addRow(Row::fromValues(['Tanggal reimburse', '', $r->tanggal->translatedFormat('j F Y')]));
        $w->addRow(new Row([Cell::fromValue('Total'), Cell::fromValue(''), Cell::fromValue($r->total, (new Style())->setFontBold()->setFormat('"Rp "#,##0'))]));
        $w->addRow(Row::fromValues(['Jumlah', '', "{$r->jumlah_transfer} transfer · {$r->jumlah_baris} baris"]));
        $w->addRow(Row::fromValues(['Sumber', '', 'Sheet KAS MMP Uang Jalan dan UM · lembar Kas Seabank']));
        $w->addRow(Row::fromValues([]));
        $w->addRow(Row::fromValues(['No', 'ID UJ', 'Tanggal', 'Penerima', 'Nama', 'Keterangan', 'Kategori', 'No Mobil', 'No DO', 'Nominal'], $kepala));
        foreach ($r->isi as $i => $d) {
            $w->addRow(new Row([
                Cell::fromValue($i + 1), Cell::fromValue((string) $d['id_uj']),
                Cell::fromValue($d['tanggal'] ? \Carbon\Carbon::parse($d['tanggal'])->format('d/m/Y') : ''),
                Cell::fromValue((string) $d['penerima']), Cell::fromValue((string) $d['nama']), Cell::fromValue((string) $d['keterangan']),
                Cell::fromValue((string) $d['kategori']), Cell::fromValue((string) $d['no_mobil']), Cell::fromValue((string) $d['no_do']),
                Cell::fromValue($d['nominal'], $rupiah),
            ]));
        }
        $w->addRow(new Row([...array_map(fn ($v) => Cell::fromValue($v, $totalStyle), ['', '', '', '', '', 'TOTAL', '', '', '']), Cell::fromValue($r->total, $totalStyle)], $tebal));
        $w->close();

        return $path;
    }
}
