<?php

namespace App\Support;

use App\Models\Bbm;
use App\Models\BbmUnitPeta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tulis ke lembar "Penggunaan BBM". Baris baru disisipkan tepat di bawah baris data terakhir (baris SUM admin di bawahnya ikut
 * turun, format mengikuti baris di atasnya). Rumus Stok (H) = stok baris data sebelumnya + Masuk − Keluar; Total HM (E) = D − C.
 * Edit menimpa A–G & I–P di tempat (H tetap rumus); Hapus menghapus barisnya lalu menyambung ulang rumus Stok baris sesudahnya.
 */
class TulisBbmSheet
{
    private string $id;

    public function __construct(private GoogleSheets $sheets, ?string $spreadsheetId = null)
    {
        $this->id = $spreadsheetId ?? LembarBbm::id();
    }

    /**
     * @param  array<int, array>  $baris  tanggal (Carbon), unit, hm_awal, hm_akhir, total_hm, masuk, keluar, jam_mulai, istirahat, jam_selesai,
     *                                     status, keterangan, rental, proyek, keterangan_kerja
     * @return array{baris_awal: int, baris_akhir: int}
     */
    public function tulis(array $baris): array
    {
        return Cache::lock('tulis-bbm-sheet', 120)->block(60, function () use ($baris) {
            $l = LembarBbm::kutip();
            $this->pastikanStruktur();
            $akhir = $this->barisAkhir();
            $mulai = $akhir + 1;
            $sampai = $mulai + count($baris) - 1;
            // Format angka ditetapkan (bukan warisan baris di atas, yang kadang salah format — mis. Total HM berformat jam).
            $sid = $this->sheetId();
            $format = fn (int $dari, int $sampaiKolom, ?array $nf) => ['repeatCell' => [
                'range' => ['sheetId' => $sid, 'startRowIndex' => $mulai - 1, 'endRowIndex' => $sampai, 'startColumnIndex' => $dari, 'endColumnIndex' => $sampaiKolom + 1],
                'cell' => ['userEnteredFormat' => $nf ? ['numberFormat' => $nf] : (object) []], 'fields' => 'userEnteredFormat.numberFormat',
            ]];
            $this->sheets->permintaan($this->id, [
                ['insertDimension' => ['range' => ['sheetId' => $sid, 'dimension' => 'ROWS', 'startIndex' => $mulai - 1, 'endIndex' => $sampai], 'inheritFromBefore' => true]],
                $format(0, 0, ['type' => 'DATE', 'pattern' => 'd-mmm-yy']),
                $format(3, 7, null),
                $format(8, 8, ['type' => 'NUMBER', 'pattern' => '#,##0']),
                $format(9, 11, ['type' => 'TIME', 'pattern' => 'h:mm']),
                $format(12, 12, ['type' => 'TIME', 'pattern' => '[h]:mm']),
            ], 'menyisipkan baris Penggunaan BBM');
            $data = [];
            foreach (array_values($baris) as $i => $b) {
                $n = $mulai + $i;
                $data["{$l}!A{$n}:P{$n}"] = [self::isi($b, $n, $n - 1)];
            }
            $this->sheets->tulis($this->id, $data);

            $peta = BbmUnitPeta::get()->keyBy('kunci')->all();
            $stok = (float) (Bbm::where('baris', '<=', $akhir)->whereNotNull('stok')->orderByDesc('baris')->value('stok') ?? 0);
            DB::transaction(function () use ($baris, $mulai, &$stok, &$peta) {
                foreach (array_values($baris) as $i => $b) {
                    $stok += (float) ($b['masuk'] ?? 0) - (float) ($b['keluar'] ?? 0);
                    Bbm::create(self::untukDb($b, $mulai + $i, $stok, $peta));
                }
            });

            return ['baris_awal' => $mulai, 'baris_akhir' => $sampai];
        });
    }

    public function ubah(Bbm $lama, array $b): void
    {
        Cache::lock('tulis-bbm-sheet', 120)->block(60, function () use ($lama, $b) {
            $l = LembarBbm::kutip();
            $this->periksa($lama);
            $n = $lama->baris;
            $isi = self::isi($b, $n, $n - 1);
            $this->sheets->tulis($this->id, ["{$l}!A{$n}:H{$n}" => [array_slice($isi, 0, 8)], "{$l}!J{$n}:P{$n}" => [array_slice($isi, 9)]]);
            $selisih = ((float) ($b['masuk'] ?? 0) - (float) ($b['keluar'] ?? 0)) - ((float) $lama->masuk - (float) $lama->keluar);
            $peta = null;
            DB::transaction(function () use ($lama, $b, $n, $selisih, &$peta) {
                $lama->update(self::untukDb($b, $n, (float) $lama->stok + $selisih, $peta));
                if ($selisih != 0) {
                    Bbm::where('baris', '>', $n)->whereNotNull('stok')->increment('stok', $selisih);
                }
            });
        });
    }

    public function hapus(Bbm $lama): void
    {
        Cache::lock('tulis-bbm-sheet', 120)->block(60, function () use ($lama) {
            $l = LembarBbm::kutip();
            $this->periksa($lama);
            $n = $lama->baris;
            $sebelum = Bbm::where('baris', '<', $n)->whereNotNull('tanggal')->max('baris');
            $sesudah = Bbm::where('baris', '>', $n)->whereNotNull('tanggal')->min('baris');
            $this->sheets->hapusBaris($this->id, $this->sheetId(), $n, $n);
            if ($sesudah) {
                $s = $sesudah - 1;
                $this->sheets->tulis($this->id, ["{$l}!I{$s}" => [[$sebelum ? "=I{$sebelum}+N(G{$s})-N(H{$s})" : "=N(G{$s})-N(H{$s})"]]]);
            }
            $selisih = (float) $lama->masuk - (float) $lama->keluar;
            DB::transaction(function () use ($lama, $n, $selisih) {
                $lama->delete();
                Bbm::where('baris', '>', $n)->decrement('baris');
                if ($selisih != 0) {
                    Bbm::where('baris', '>=', $n)->whereNotNull('stok')->decrement('stok', $selisih);
                }
            });
        });
    }

    /** Baris data terakhir di sheet (dari database; dipastikan beberapa baris di bawahnya memang tanpa tanggal & unit). */
    private function barisAkhir(): int
    {
        $l = LembarBbm::kutip();
        $akhir = (int) Bbm::max('baris') ?: 1;
        $bawah = $this->sheets->nilaiMentah($this->id, ["{$l}!A".($akhir + 1).':C'.($akhir + 40)])["{$l}!A".($akhir + 1).':C'.($akhir + 40)];
        if (collect($bawah)->contains(fn ($r) => LembarBbm::adaData($r))) {
            $akhir = 1;
            foreach ($this->sheets->nilaiMentah($this->id, ["{$l}!A2:C"])["{$l}!A2:C"] as $i => $r) {
                if (LembarBbm::adaData($r)) {
                    $akhir = $i + 2;
                }
            }
        }

        return $akhir;
    }

    /** Baris sheet harus masih berisi data yang sama (tanggal, unit, masuk, keluar) — kalau tidak, sheet sudah berubah. */
    private function periksa(Bbm $b): void
    {
        $l = LembarBbm::kutip();
        $this->pastikanStruktur();
        $isi = $this->sheets->nilaiMentah($this->id, ["{$l}!A{$b->baris}:H{$b->baris}"])["{$l}!A{$b->baris}:H{$b->baris}"][0] ?? [];
        $sama = KasSeabank::tanggal($isi[0] ?? null)?->toDateString() === $b->tanggal?->toDateString()
            && BbmUnitPeta::kunci($isi[2] ?? '') === BbmUnitPeta::kunci($b->unit)
            && LembarBbm::angka($isi[6] ?? null) == $b->masuk && LembarBbm::angka($isi[7] ?? null) == $b->keluar;
        if (! $sama) {
            throw new RuntimeException("Isi sheet Penggunaan BBM baris {$b->baris} sudah berubah sejak data terakhir diperbarui. Tunggu beberapa menit (data diperbarui otomatis tiap 5 menit), muat ulang, lalu ulangi.");
        }
    }

    /** Pastikan judul kolom sheet = struktur yang dipahami aplikasi (lihat LembarBbm::JUDUL). */
    private function pastikanStruktur(): void
    {
        $l = LembarBbm::kutip();
        LembarBbm::pastikanStruktur($this->sheets->nilaiMentah($this->id, ["{$l}!A1:P1"])["{$l}!A1:P1"][0] ?? []);
    }

    /** Kolom A..P satu baris; $sebelum = baris data sebelumnya untuk rumus Stok. */
    private static function isi(array $b, int $n, int $sebelum): array
    {
        $t = fn ($v) => ($v = trim((string) $v)) !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
        $a = fn ($v) => $v === null || $v === '' ? '' : (float) $v;
        $total = $b['hm_awal'] !== null && $b['hm_akhir'] !== null ? "=E{$n}-D{$n}" : $a($b['total_hm'] ?? null);
        $jenis = $b['jenis'] ?? null;
        if (! isset(LembarBbm::labelJenis()[$jenis])) {
            $peta = null;
            [$jenis] = LembarBbm::arti($b['unit'], $peta);
        }

        return [
            $b['tanggal']->format('Y-m-d'), LembarBbm::labelJenis()[$jenis] ?? '', $t($b['unit']), $a($b['hm_awal']), $a($b['hm_akhir']), $total,
            $a($b['masuk']), $a($b['keluar']),
            $sebelum >= 2 ? "=I{$sebelum}+N(G{$n})-N(H{$n})" : "=N(G{$n})-N(H{$n})",
            $b['jam_mulai'] ?? '', $b['istirahat'] ?? '', $b['jam_selesai'] ?? '',
            "=IF(OR(J{$n}=\"\";L{$n}=\"\");\"\";MOD(L{$n}-J{$n};1)-N(K{$n}))",
            $t($b['status'] ?? ''), $t($b['proyek'] ?? ''), $t($b['keterangan'] ?? ''),
        ];
    }

    private static function untukDb(array $b, int $n, float $stok, ?array &$peta): array
    {
        [$jenis, $aset] = LembarBbm::arti($b['unit'], $peta);
        if (isset(LembarBbm::labelJenis()[$b['jenis'] ?? null])) {
            $jenis = $b['jenis'];
        }
        $total = $b['hm_awal'] !== null && $b['hm_akhir'] !== null ? round($b['hm_akhir'] - $b['hm_awal'], 2) : ($b['total_hm'] ?? null);

        return [
            'baris' => $n, 'tanggal' => $b['tanggal']->toDateString(), 'unit' => $b['unit'], 'jenis' => $jenis, 'aset_id' => $aset,
            'hm_awal' => $b['hm_awal'], 'hm_akhir' => $b['hm_akhir'], 'total_hm' => $total, 'masuk' => $b['masuk'], 'keluar' => $b['keluar'], 'stok' => $stok,
            'jam_mulai' => $b['jam_mulai'] ?? null, 'istirahat' => $b['istirahat'] ?? null, 'jam_selesai' => $b['jam_selesai'] ?? null,
            'status' => $b['status'] ?? null, 'keterangan' => $b['keterangan'] ?? null, 'rental' => null,
            'proyek' => $b['proyek'] ?? null, 'tahap_ids' => LembarBbm::tahapIds($b['proyek'] ?? null), 'keterangan_kerja' => null,
        ];
    }

    private function sheetId(): int
    {
        return Cache::rememberForever("bbm-sheet-id-{$this->id}", fn () => collect($this->sheets->info($this->id)['sheets'])
            ->firstWhere('properties.title', LembarBbm::LEMBAR)['properties']['sheetId']
            ?? throw new RuntimeException('Lembar "'.LembarBbm::LEMBAR.'" tidak ditemukan.'));
    }
}
