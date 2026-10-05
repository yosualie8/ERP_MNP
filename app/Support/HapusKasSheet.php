<?php

namespace App\Support;

use App\Models\KasTransfer;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Hapus satu transfer beserta rincian bonnya dari lembar bulanan di sheet.
 * Sebelum menghapus, isi baris di sheet dicocokkan dengan data aplikasi; bila sheet sudah berubah
 * sejak sinkron terakhir, penghapusan dibatalkan supaya baris lain tidak ikut terhapus.
 */
class HapusKasSheet
{
    public function __construct(private GoogleSheets $sheets, private ?string $spreadsheetId = null)
    {
        $this->spreadsheetId ??= config('mnp.sheet_kas_harian');
    }

    /** Baris "Biaya Transfer Keluar" tepat di bawah transfer ini (milik transfer ini), bila ada. */
    public static function biayaTransferMilik(KasTransfer $t): ?KasTransfer
    {
        $akhir = max($t->baris, (int) $t->bon->max('baris'));
        $berikut = KasTransfer::where('kas_bulan_id', $t->kas_bulan_id)->where('baris', '>', $akhir)->orderBy('baris')->with('bon')->first();

        return $berikut && $berikut->kredit === TulisKasSheet::BIAYA_TRANSFER && $berikut->bon->count() <= 1
            && stripos((string) $berikut->keterangan, 'biaya transfer') !== false && $berikut->baris === $akhir + 1
            ? $berikut : null;
    }

    /** @return array{lembar: string, baris_awal: int, baris_akhir: int, isi: array} */
    public function hapus(KasTransfer $t, bool $denganBiaya): array
    {
        return Cache::lock('tulis-kas-sheet', 60)->block(30, function () use ($t, $denganBiaya) {
            $t->loadMissing('bon', 'kasBulan');
            $lembar = $t->kasBulan->lembar;
            $blok = [$t];
            if ($denganBiaya && ($biaya = self::biayaTransferMilik($t))) {
                $blok[] = $biaya;
            }
            $dari = $t->baris;
            $sampai = max(array_map(fn ($x) => max($x->baris, (int) $x->bon->max('baris')), $blok));

            $info = collect($this->sheets->info($this->spreadsheetId)['sheets'])->firstWhere('properties.title', $lembar)
                ?? throw new RuntimeException("Lembar {$lembar} tidak ditemukan di sheet.");
            $sheetId = $info['properties']['sheetId'];

            // Baca blok + satu baris sesudahnya untuk memastikan batas blok.
            $range = "{$lembar}!A{$dari}:R".($sampai + 1);
            $isi = $this->sheets->nilai($this->spreadsheetId, [$range])[$range];
            $this->cocokkan($blok, $isi, $dari, $sampai);

            $semua = $this->sheets->nilai($this->spreadsheetId, ["{$lembar}!B:B"])["{$lembar}!B:B"];
            $total = collect($semua)->search(fn ($r) => strtoupper(trim((string) ($r[0] ?? ''))) === 'TOTAL');
            if ($total === false || $total + 1 <= $sampai) {
                throw new RuntimeException("Baris TOTAL di lembar {$lembar} tidak ditemukan di bawah transfer ini.");
            }
            $total += 1;

            $this->sheets->hapusBaris($this->spreadsheetId, $sheetId, $dari, $sampai);

            // Baris yang naik ke posisi $dari: rumus saldonya merujuk baris yang terhapus, sambungkan ulang.
            $totalBaru = $total - ($sampai - $dari + 1);
            if ($dari < $totalBaru) {
                $this->sheets->tulis($this->spreadsheetId, ["{$lembar}!I{$dari}" => [["=I".($dari - 1)."+G{$dari}-H{$dari}"]]]);
            }

            return ['lembar' => $lembar, 'baris_awal' => $dari, 'baris_akhir' => $sampai, 'isi' => array_slice($isi, 0, $sampai - $dari + 1)];
        });
    }

    /** Isi sheet harus sama dengan data aplikasi: nominal transfer, keterangan, nominal tiap bon, dan baris sesudah blok bukan bon. */
    private function cocokkan(array $blok, array $isi, int $dari, int $sampai): void
    {
        $sel = fn (int $baris, int $kolom) => trim((string) ($isi[$baris - $dari][$kolom] ?? ''));
        $berubah = fn (string $alasan) => new RuntimeException("Isi sheet sudah berubah sejak sinkron terakhir ({$alasan}). Klik \"Sinkron dari sheet\", periksa lagi, lalu hapus ulang.");

        foreach ($blok as $t) {
            if ((BacaLembarKas::rupiah($sel($t->baris, 6)) ?? 0) !== $t->debet || (BacaLembarKas::rupiah($sel($t->baris, 7)) ?? 0) !== $t->kredit) {
                throw $berubah("nominal di baris {$t->baris} tidak sama");
            }
            if ($sel($t->baris, 5) !== trim((string) $t->keterangan)) {
                throw $berubah("keterangan di baris {$t->baris} tidak sama");
            }
            foreach ($t->bon as $b) {
                if (BacaLembarKas::rupiah($sel($b->baris, 10)) !== $b->nominal) {
                    throw $berubah("transaksi detail di baris {$b->baris} tidak sama");
                }
            }
        }
        // Baris sesudah blok tidak boleh berupa bon lanjutan (bon tanpa Debet/Kredit) — artinya blok di sheet lebih panjang.
        $sesudah = $sampai + 1;
        if (BacaLembarKas::rupiah($sel($sesudah, 10)) && ! BacaLembarKas::rupiah($sel($sesudah, 6)) && ! BacaLembarKas::rupiah($sel($sesudah, 7))) {
            throw $berubah("baris {$sesudah} masih berisi transaksi detail milik transfer ini");
        }
    }
}
