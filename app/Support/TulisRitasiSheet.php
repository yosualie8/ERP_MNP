<?php

namespace App\Support;

use App\Models\Ritasi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tulis rit ke lembar "Ritasi": baris baru di bawah data terakhir (letak dari database aplikasi; beberapa baris tujuan dicek
 * masih kosong supaya ketikan admin langsung di sheet tidak tertimpa). Edit menimpa B–O & Q di tempat; Hapus menghapus barisnya.
 * Kolom A (No) & P (Status Bayar) — rumus lama — tidak disentuh. Kolom S diisi rumus pengecek DO dobel.
 */
class TulisRitasiSheet
{
    private string $id;

    public function __construct(private GoogleSheets $sheets, ?string $spreadsheetId = null)
    {
        $this->id = $spreadsheetId ?? LembarRitasi::id();
    }

    /**
     * @param  array  $kepala  tahap, tanggal (Carbon), galian, jenis_buangan, jenis_tanah
     * @param  array<int, array>  $rit  no_seri, jam, no_lambung, plat, driver, jenis_kendaraan, pemilik, no_do, harga_jual, keterangan
     * @return array{baris_awal: int, baris_akhir: int}
     */
    public function tulis(array $kepala, array $rit): array
    {
        return Cache::lock('tulis-ritasi-sheet', 120)->block(60, function () use ($kepala, $rit) {
            $l = LembarRitasi::LEMBAR;
            $akhir = max((int) Ritasi::max('baris'), (int) Cache::get('ritasi-akhir', 1));
            if (! $this->kosong($akhir + 1, $akhir + count($rit) + 2)) {
                $akhir = 1;
                foreach ($this->sheets->nilaiMentah($this->id, ["{$l}!A2:Q"])["{$l}!A2:Q"] as $i => $r) {
                    if (LembarRitasi::adaData($r)) {
                        $akhir = $i + 2;
                    }
                }
            }
            $mulai = $akhir + 1;
            $sampai = $mulai + count($rit) - 1;
            $data = [];
            foreach (array_values($rit) as $i => $r) {
                $n = $mulai + $i;
                $data["{$l}!B{$n}:O{$n}"] = [self::isiBO($kepala, $r)];
                $data["{$l}!Q{$n}"] = [[self::angkaTeks($r['no_do'])]];
                $data["{$l}!S{$n}"] = [["=COUNTIF(Q:Q;Q{$n})"]];
            }
            // Format (tanggal, jam, angka) mengikuti baris data terakhir.
            $sheetId = $this->sheetId();
            $this->sheets->permintaan($this->id, [['copyPaste' => [
                'source' => ['sheetId' => $sheetId, 'startRowIndex' => $akhir - 1, 'endRowIndex' => $akhir, 'startColumnIndex' => 0, 'endColumnIndex' => 19],
                'destination' => ['sheetId' => $sheetId, 'startRowIndex' => $mulai - 1, 'endRowIndex' => $sampai, 'startColumnIndex' => 0, 'endColumnIndex' => 19],
                'pasteType' => 'PASTE_FORMAT',
            ]]], 'menyiapkan baris Ritasi');
            $this->sheets->tulis($this->id, $data);
            Cache::forever('ritasi-akhir', $sampai);

            DB::transaction(function () use ($kepala, $rit, $mulai) {
                foreach (array_values($rit) as $i => $r) {
                    Ritasi::create(self::barisDb($kepala, $r, $mulai + $i));
                }
            });

            return ['baris_awal' => $mulai, 'baris_akhir' => $sampai];
        });
    }

    /** Ubah satu rit di tempatnya (setelah memastikan baris sheet masih sama dengan data aplikasi). */
    public function ubah(Ritasi $lama, array $kepala, array $r): void
    {
        Cache::lock('tulis-ritasi-sheet', 120)->block(60, function () use ($lama, $kepala, $r) {
            $l = LembarRitasi::LEMBAR;
            $this->periksa($lama);
            $n = $lama->baris;
            $this->sheets->tulis($this->id, ["{$l}!B{$n}:O{$n}" => [self::isiBO($kepala, $r)], "{$l}!Q{$n}" => [[self::angkaTeks($r['no_do'])]]]);
            $lama->update(self::barisDb($kepala, $r, $n));
        });
    }

    public function hapus(Ritasi $lama): void
    {
        Cache::lock('tulis-ritasi-sheet', 120)->block(60, function () use ($lama) {
            $this->periksa($lama);
            $this->sheets->hapusBaris($this->id, $this->sheetId(), $lama->baris, $lama->baris);
            DB::transaction(function () use ($lama) {
                $baris = $lama->baris;
                $lama->delete();
                Ritasi::where('baris', '>', $baris)->decrement('baris');
            });
            if (($akhir = Cache::get('ritasi-akhir')) && $akhir >= $lama->baris) {
                Cache::forever('ritasi-akhir', $akhir - 1);
            }
        });
    }

    /** Baris sheet harus masih berisi rit yang sama (No Seri, tanggal, DT, No DO) — kalau tidak, sheet sudah berubah. */
    private function periksa(Ritasi $r): void
    {
        $l = LembarRitasi::LEMBAR;
        $isi = $this->sheets->nilaiMentah($this->id, ["{$l}!A{$r->baris}:Q{$r->baris}"])["{$l}!A{$r->baris}:Q{$r->baris}"][0] ?? [];
        $sama = trim((string) ($isi[2] ?? '')) === (string) $r->no_seri
            && KasSeabank::tanggal($isi[3] ?? null)?->toDateString() === $r->tanggal?->toDateString()
            && NomorMobil::rapikan((string) ($isi[6] ?? '')) === $r->no_lambung
            && trim((string) ($isi[16] ?? '')) === (string) $r->no_do;
        if (! $sama) {
            throw new RuntimeException("Isi sheet Ritasi baris {$r->baris} sudah berubah sejak sinkron terakhir. Klik \"Sinkron dari sheet\", periksa lagi, lalu ulangi.");
        }
    }

    /** Kolom B..O satu rit. */
    private static function isiBO(array $k, array $r): array
    {
        $t = fn (?string $v) => self::teks($v);
        $polisi = trim(implode('/', array_filter([trim((string) $r['plat']), trim((string) $r['driver'])])));

        return [
            $t($k['tahap']), self::angkaTeks($r['no_seri']), $k['tanggal']->format('Y-m-d'), $r['jam'] ?: '',
            $t($r['plat']), $t($r['no_lambung']), $t($polisi), $t($k['galian']), $t($k['jenis_buangan']), $t($k['jenis_tanah']),
            $t($r['jenis_kendaraan']), $t($r['pemilik']), $r['harga_jual'] ? (int) $r['harga_jual'] : '', $t($r['keterangan']),
        ];
    }

    private static function barisDb(array $k, array $r, int $n): array
    {
        $polisi = trim(implode('/', array_filter([trim((string) $r['plat']), trim((string) $r['driver'])])));

        return [
            'baris' => $n, 'tahap' => $k['tahap'], 'no_seri' => $r['no_seri'], 'tanggal' => $k['tanggal']->toDateString(), 'jam' => $r['jam'] ?: null,
            'plat' => $r['plat'] ?: null, 'no_lambung' => $r['no_lambung'] ?: null, 'no_polisi' => $polisi ?: null, 'galian' => $k['galian'],
            'jenis_buangan' => $k['jenis_buangan'], 'jenis_tanah' => $k['jenis_tanah'], 'jenis_kendaraan' => $r['jenis_kendaraan'] ?: null,
            'pemilik' => $r['pemilik'] ?: null, 'harga_jual' => $r['harga_jual'] ?: null, 'keterangan' => $r['keterangan'] ?: null, 'no_do' => $r['no_do'] ?: null,
        ];
    }

    private function kosong(int $dari, int $sampai): bool
    {
        $l = LembarRitasi::LEMBAR;
        foreach ($this->sheets->nilaiMentah($this->id, ["{$l}!A{$dari}:Q{$sampai}"])["{$l}!A{$dari}:Q{$sampai}"] as $r) {
            if (LembarRitasi::adaData($r)) {
                return false;
            }
        }

        return true;
    }

    private function sheetId(): int
    {
        return Cache::rememberForever("ritasi-sheet-id-{$this->id}", fn () => collect($this->sheets->info($this->id)['sheets'])
            ->firstWhere('properties.title', LembarRitasi::LEMBAR)['properties']['sheetId']
            ?? throw new RuntimeException('Lembar "'.LembarRitasi::LEMBAR.'" tidak ditemukan.'));
    }

    /** Angka biasa tetap angka (seperti diketik admin); nol di depan / campuran huruf dipertahankan sebagai teks. */
    private static function angkaTeks(?string $v): string
    {
        $v = trim((string) $v);

        return $v !== '' && ($v[0] === '0' || ! ctype_digit($v)) ? "'".$v : $v;
    }

    private static function teks(?string $v): string
    {
        $v = trim((string) $v);

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v;
    }
}
