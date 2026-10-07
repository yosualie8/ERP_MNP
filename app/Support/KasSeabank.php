<?php

namespace App\Support;

use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Models\UjTransaksi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lembar "Kas Seabank" (spreadsheet "KAS MMP Uang Jalan dan UM"): kas uang jalan dump truck.
 * Kolom: A ID UJ, B Tanggal, C Bank, D Rekening Tujuan, E Nominal Master, F Nama, G Keterangan, H Nominal Detail,
 * I Kategori, J Jenis Kendaraan, K No Mobil, L No DO, M Status Reimburse (rumus dari N), N Tanggal Reimburse, Q Bon (chip Drive).
 * Baris master = baris yang berisi Bank/Rekening (atau Nominal Master + Nama); baris di bawahnya sampai master berikutnya = detail.
 * Baris "Biaya Transfer" ikut sebagai detail (tidak dihitung di Nominal Master).
 */
class KasSeabank
{
    public const LEMBAR = 'Kas Seabank';

    public const KOLOM_BON = 16; // Q

    public static function id(): string
    {
        return config('mnp.sheet_uj');
    }

    /** Nomor seri tanggal sheet → Carbon. */
    public static function tanggal(mixed $v): ?Carbon
    {
        if (is_int($v) || is_float($v)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v);
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $v = str_ireplace(['Mei', 'Agu', 'Okt', 'Des', 'Agt'], ['May', 'Aug', 'Oct', 'Dec', 'Aug'], $v);
        foreach (['j-M-y', 'd/M/Y', 'j-M-Y', 'd-m-Y', 'Y-m-d'] as $f) {
            try {
                return Carbon::createFromFormat('!'.$f, $v);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public static function angka(mixed $v): ?int
    {
        if (is_int($v) || is_float($v)) {
            return (int) round($v);
        }
        $d = preg_replace('/[^\d-]/', '', (string) $v);

        return $d === '' || $d === '-' ? null : (int) $d;
    }

    public static function noUj(?string $id): ?int
    {
        return preg_match('/^\s*UJ-?\s*(\d+)/i', (string) $id, $m) ? (int) $m[1] : null;
    }

    /**
     * Baris berisi transaksi bila salah satu kolom A–H terisi. Baris yang hanya berisi No DO / rumus status
     * (mis. sisa isian di baris paling bawah lembar) tidak dihitung sebagai data.
     */
    public static function adaData(array $r): bool
    {
        foreach (array_slice($r, 0, 8) as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return true;
            }
        }

        return false;
    }

    public static function biayaTransfer(?string $kategori, ?string $keterangan): bool
    {
        return (bool) preg_match('/biaya\s*transfer/i', (string) $kategori.' '.(string) $keterangan);
    }

    /**
     * Uraikan baris sheet menjadi transaksi (master + detail).
     *
     * @param  array<int, array<int, mixed>>  $mentah  baris A..N (nilai mentah), indeks 0 = baris $mulai
     * @param  array<int, array<int, mixed>>  $bon  kolom Q (teks tampil), indeks sama
     * @return array<int, array{master: array, detail: array<int, array>}>
     */
    public static function urai(array $mentah, array $bon, int $mulai): array
    {
        $hasil = [];
        $kini = null;
        foreach ($mentah as $i => $r) {
            $n = $mulai + $i;
            $sel = fn (int $k) => is_string($r[$k] ?? null) ? trim($r[$k]) : ($r[$k] ?? null);
            $teks = fn (int $k) => ($v = $sel($k)) === null || $v === '' ? null : (string) $v;
            if (! self::adaData($r)) {
                continue;
            }
            $master = $teks(2) !== null || $teks(3) !== null || ($teks(4) !== null && $teks(5) !== null);
            if ($master) {
                if ($kini !== null) {
                    $hasil[] = $kini;
                }
                $kini = ['master' => [
                    'baris' => $n, 'baris_akhir' => $n, 'tanggal' => self::tanggal($sel(1))?->toDateString(),
                    'bank' => DaftarBank::rapikan($teks(2)), 'rekening' => $teks(3), 'nama' => $teks(5), 'nominal' => self::angka($sel(4)),
                    'no_uj' => self::noUj($teks(0)), 'biaya' => null,
                ], 'detail' => []];
            }
            if ($kini === null) {
                continue; // baris sebelum master pertama
            }
            $kini['master']['baris_akhir'] = $n;
            if ($teks(6) === null && $sel(7) === null) {
                continue; // baris master tanpa detail
            }
            $biaya = self::biayaTransfer($teks(8), $teks(6));
            if ($biaya) {
                $kini['master']['biaya'] = ($kini['master']['biaya'] ?? 0) + (self::angka($sel(7)) ?? 0);
            }
            $kini['detail'][] = [
                'baris' => $n, 'id_uj' => $teks(0), 'tanggal' => self::tanggal($sel(1))?->toDateString(), 'nama' => $teks(5),
                'keterangan' => $teks(6) ? mb_substr($teks(6), 0, 500) : null, 'nominal' => self::angka($sel(7)), 'kategori' => $teks(8),
                'jenis_kendaraan' => NomorMobil::rapikanJenis($teks(9)), 'no_mobil' => NomorMobil::rapikan($teks(10)), 'no_do' => $teks(11), 'status' => $teks(12),
                'tanggal_reimburse' => self::tanggal($sel(13))?->toDateString(), 'bon' => trim((string) ($bon[$i][0] ?? '')) ?: null,
                'biaya_transfer' => $biaya,
            ];
        }
        if ($kini !== null) {
            $hasil[] = $kini;
        }

        return $hasil;
    }

    /** Impor ulang seluruh lembar ke tabel uj_transaksi/uj_detail. */
    public static function impor(GoogleSheets $sheets): array
    {
        $l = self::LEMBAR;
        $mentah = $sheets->nilaiMentah(self::id(), ["{$l}!A2:N"])["{$l}!A2:N"];
        $bon = $sheets->nilai(self::id(), ["{$l}!Q2:Q"])["{$l}!Q2:Q"];
        $transaksi = self::urai($mentah, $bon, 2);
        // Catat baris terakhir & ID UJ terbesar seluruh lembar (juga baris sesudah batas tanggal yang tidak diimpor),
        // supaya Input UJ tidak perlu membaca sheet untuk menentukan nomor & letak baris berikutnya.
        Cache::forever('uj-akhir', max(array_map(fn ($t) => $t['master']['baris_akhir'], $transaksi) ?: [1]));
        Cache::forever('uj-max', max(array_map(fn ($d) => self::noUj($d['id_uj']) ?? 0, array_merge(...array_map(fn ($t) => $t['detail'], $transaksi))) ?: [0]));

        // Data dari sheet hanya sampai tanggal batas (data admin sesudahnya belum dipakai), kecuali yang ditulis lewat aplikasi.
        $batas = config('mnp.uj_impor_sampai');
        if ($batas) {
            $dariAplikasi = KasRiwayat::whereIn('aksi', ['uj-tambah', 'uj-ubah'])->get()->flatMap(fn ($r) => (array) ($r->isi['id_uj'] ?? []))->map(fn ($n) => (int) $n)->flip();
            $transaksi = array_values(array_filter($transaksi, fn ($t) => ($t['master']['tanggal'] !== null && $t['master']['tanggal'] <= $batas)
                || collect($t['detail'])->contains(fn ($d) => isset($dariAplikasi[self::noUj($d['id_uj']) ?? -1]))));
        }

        DB::transaction(function () use ($transaksi) {
            UjDetail::query()->delete();
            UjTransaksi::query()->delete();
            self::simpan($transaksi);
        });
        Cache::forever('uj-diimpor-pada', now()->toIso8601String());

        return ['transaksi' => count($transaksi), 'detail' => array_sum(array_map(fn ($t) => count($t['detail']), $transaksi))];
    }

    /**
     * Setelah aplikasi menulis/menghapus satu blok: perbarui DB tanpa impor ulang seluruh lembar.
     * Baris di bawah blok bergeser $geser baris; blok baru (bila ada) dibaca ulang dari sheet.
     */
    public static function perbaruiBlok(?UjTransaksi $lama, int $geserDari, int $geser, ?array $mentah = null, ?int $dari = null): void
    {
        // Blok baru diambil dari baris yang barusan ditulis aplikasi (tanpa membaca ulang sheet).
        $baru = $mentah !== null ? self::urai($mentah, [], $dari) : [];
        DB::transaction(function () use ($lama, $geserDari, $geser, $baru) {
            $lama?->delete();
            if ($geser !== 0) {
                UjTransaksi::where('baris', '>=', $geserDari)->update(['baris' => DB::raw("baris + ({$geser})"), 'baris_akhir' => DB::raw("baris_akhir + ({$geser})")]);
                UjDetail::where('baris', '>=', $geserDari)->update(['baris' => DB::raw("baris + ({$geser})")]);
            }
            self::simpan($baru);
        });
    }

    private static function simpan(array $transaksi): void
    {
        $waktu = now();
        foreach (array_chunk($transaksi, 300) as $potong) {
            foreach ($potong as $t) {
                $m = UjTransaksi::create($t['master']);
                $baris = array_map(fn ($d) => [...$d, 'uj_transaksi_id' => $m->id, 'created_at' => $waktu, 'updated_at' => $waktu], $t['detail']);
                foreach (array_chunk($baris, 200) as $b) {
                    UjDetail::insert($b);
                }
            }
        }
    }
}
