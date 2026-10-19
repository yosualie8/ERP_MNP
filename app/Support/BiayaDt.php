<?php

namespace App\Support;

use App\Models\AsetTruk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring Biaya DT (owner 19 Okt 2026): biaya per dump truck per bulan & kategori, dari dua sumber —
 *  - Kas UJ (uj_detail, per No Mobil): uang jalan, tanah, material, makan, perangsang, MOB, sparepart, dst.;
 *  - Kas Harian (kas_bon ber-No Mobil, kolom S sheet): sparepart, service, laka, solar, parkir & tol, … menurut Akun GL.
 * Transfer Kas Harian "Reimburse Uang Jalan" TIDAK dihitung: isinya cermin baris Kas UJ yang sudah direimburse (dobel).
 * Hanya truk kategori dump_truck di Data Aset (No Mobil baku "DT 0xx").
 */
class BiayaDt
{
    /** Kelompok kategori (urutan tampil). Kategori yang tidak terdaftar → Lain-lain. */
    public const KELOMPOK = [
        'Perjalanan' => ['Uang Jalan', 'Uang Makan', 'Uang Perangsang', 'Uang MOB', 'Uang Geser', 'Uang Lembur', 'Solar / BBM', 'Parkir & Tol'],
        'Perawatan' => ['Sparepart', 'Service', 'Laka', 'Uang Storing', 'Uang Derek'],
        'Material' => ['Uang Tanah', 'Uang Material', 'Uang Pasir'],
    ];

    /** Kategori Kas UJ → nama gabungan (sama dengan kategori Kas Harian yang sejenis). */
    private const GABUNG_UJ = ['Uang Laka' => 'Laka', 'Uang Solar' => 'Solar / BBM'];

    public static function kelompok(string $kategori): string
    {
        foreach (self::KELOMPOK as $k => $isi) {
            if (in_array($kategori, $isi, true)) {
                return $k;
            }
        }

        return 'Lain-lain';
    }

    public static function kategoriUj(?string $kategori): string
    {
        $k = KategoriUj::baku($kategori) ?? 'Tanpa kategori';

        return self::GABUNG_UJ[$k] ?? $k;
    }

    /** Akun GL Kas Harian → kategori biaya DT. */
    public static function kategoriKas(?string $akun): string
    {
        $a = (string) $akun;

        return match (true) {
            $a === '' => 'Tanpa Kode GL',
            (bool) preg_match('/spar|spare/i', $a) => 'Sparepart',
            (bool) preg_match('/bbm|solar|bensin|baiay bbm/i', $a) => 'Solar / BBM',
            (bool) preg_match('/laka/i', $a) => 'Laka',
            (bool) preg_match('/parki?r|tol/i', $a) => 'Parkir & Tol',
            (bool) preg_match('/servi/i', $a) => 'Service',
            (bool) preg_match('/hpp/i', $a) => 'Uang Jalan',
            (bool) preg_match('/operas|ops\b/i', $a) => 'Operasional',
            default => trim(preg_replace('/^bi+a?ya\s+/i', '', $a)) ?: $a,
        };
    }

    /** No Mobil dump truck terdaftar → info aset. @return Collection<string, object> */
    public static function aset(): Collection
    {
        return AsetTruk::truk()->get(['no_lambung', 'jenis', 'kepemilikan', 'status', 'driver_tetap'])->keyBy('no_lambung');
    }

    /**
     * Baris ringkas biaya: [mobil, bulan Y-m, kategori, sumber uj|kas, n, rp] untuk rentang tanggal.
     *
     * @return Collection<int, array{mobil: string, bulan: string, kategori: string, sumber: string, n: int, rp: int}>
     */
    public static function baris(Carbon $awal, Carbon $akhir): Collection
    {
        $aset = self::aset();
        $hasil = collect();
        $tambah = function (?string $mobil, string $bulan, string $kategori, string $sumber, int $n, int $rp) use ($aset, &$hasil) {
            $m = NomorMobil::rapikan($mobil);
            if ($m && isset($aset[$m])) {
                $hasil->push(['mobil' => $m, 'bulan' => $bulan, 'kategori' => $kategori, 'sumber' => $sumber, 'n' => $n, 'rp' => $rp]);
            }
        };

        $uj = DB::table('uj_detail')->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])->whereNotNull('no_mobil')
            ->selectRaw("no_mobil, DATE_FORMAT(tanggal, '%Y-%m') bln, kategori, COUNT(*) n, SUM(nominal) rp")->groupBy('no_mobil', 'bln', 'kategori')->get();
        foreach ($uj as $r) {
            $tambah($r->no_mobil, $r->bln, self::kategoriUj($r->kategori), 'uj', (int) $r->n, (int) $r->rp);
        }

        foreach (self::kasQuery($awal, $akhir)->selectRaw("b.no_mobil, DATE_FORMAT(b.tanggal, '%Y-%m') bln, a.nama akun, COUNT(*) n, SUM(b.nominal) rp")
            ->groupBy('b.no_mobil', 'bln', 'a.nama')->get() as $r) {
            $tambah($r->no_mobil, $r->bln, self::kategoriKas($r->akun), 'kas', (int) $r->n, (int) $r->rp);
        }

        // Gabungkan baris yang jatuh ke kunci yang sama (mis. beberapa Akun GL → "Sparepart").
        return $hasil->groupBy(fn ($r) => "{$r['mobil']}|{$r['bulan']}|{$r['kategori']}|{$r['sumber']}")
            ->map(fn ($g) => [...$g->first(), 'n' => $g->sum('n'), 'rp' => $g->sum('rp')])->values();
    }

    /** Detail Kas Harian ber-No Mobil, tanpa cermin "Reimburse Uang Jalan" & tanpa akun Non-biaya (refund, talangan). */
    private static function kasQuery(Carbon $awal, Carbon $akhir)
    {
        return DB::table('kas_bon as b')
            ->join('kas_transfer as t', 't.id', '=', 'b.kas_transfer_id')
            ->leftJoin('kode_gl as g', 'g.id', '=', 'b.kode_gl_id')
            ->leftJoin('akun_gl as a', 'a.id', '=', 'g.akun_gl_id')
            ->whereNotNull('b.no_mobil')
            ->whereBetween('b.tanggal', [$awal->toDateString(), $akhir->toDateString()])
            ->where(fn ($q) => $q->whereNull('t.keterangan')->orWhere('t.keterangan', 'not like', ReimburseUjKeKas::KETERANGAN.'%'))
            ->where(fn ($q) => $q->whereNull('a.kelompok')->orWhere('a.kelompok', '!=', 'Non-biaya'));
    }

    /** Jumlah rit per truk per bulan (lembar Ritasi, No Lambung). @return array<string, array<string, int>> [mobil][Y-m] */
    public static function rit(Carbon $awal, Carbon $akhir): array
    {
        $aset = self::aset();
        $hasil = [];
        foreach (DB::table('ritasi')->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])->whereNotNull('no_lambung')
            ->selectRaw("no_lambung, DATE_FORMAT(tanggal, '%Y-%m') bln, COUNT(*) n")->groupBy('no_lambung', 'bln')->get() as $r) {
            $m = NomorMobil::rapikan($r->no_lambung);
            if ($m && isset($aset[$m])) {
                $hasil[$m][$r->bln] = ($hasil[$m][$r->bln] ?? 0) + (int) $r->n;
            }
        }

        return $hasil;
    }

    /**
     * Semua transaksi satu truk dalam rentang (untuk rincian), terbaru dulu.
     *
     * @return Collection<int, array{sumber: string, tanggal: string, id: ?string, kategori: string, keterangan: ?string, nama: ?string, no_do: ?string, rp: int}>
     */
    public static function transaksi(string $mobil, Carbon $awal, Carbon $akhir): Collection
    {
        // No Mobil di data bisa belum baku ("DT027", "Dt 27") → cocokkan setelah dirapikan.
        $varian = fn (string $kolom) => DB::raw("UPPER(REPLACE({$kolom}, ' ', ''))");
        $kunci = str_replace(' ', '', $mobil);
        $angka = (int) preg_replace('/\D/', '', $mobil);
        $cocok = fn ($q, string $kolom) => $q->whereIn($varian($kolom), [$kunci, 'DT'.$angka, 'DT'.str_pad((string) $angka, 2, '0', STR_PAD_LEFT)]);

        $uj = $cocok(DB::table('uj_detail'), 'no_mobil')->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])
            ->get(['id_uj', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'no_do'])
            ->map(fn ($r) => ['sumber' => 'uj', 'tanggal' => (string) $r->tanggal, 'id' => $r->id_uj, 'kategori' => self::kategoriUj($r->kategori),
                'keterangan' => $r->keterangan, 'nama' => $r->nama, 'no_do' => $r->no_do, 'rp' => (int) $r->nominal]);
        $kas = $cocok(self::kasQuery($awal, $akhir), 'b.no_mobil')
            ->get(['b.no_id', 'b.tanggal', 'b.pic', 'b.keterangan', 'b.nominal', 'a.nama as akun', 'g.kode_asli'])
            ->map(fn ($r) => ['sumber' => 'kas', 'tanggal' => (string) $r->tanggal, 'id' => $r->no_id ? (string) $r->no_id : null, 'kategori' => self::kategoriKas($r->akun),
                'keterangan' => $r->keterangan, 'nama' => $r->pic, 'no_do' => null, 'rp' => (int) $r->nominal, 'kode_gl' => $r->kode_asli]);

        return $uj->concat($kas)->sortByDesc(fn ($r) => $r['tanggal'].'|'.$r['sumber'].'|'.str_pad((string) $r['id'], 12, '0', STR_PAD_LEFT))->values();
    }

    /** Bulan yang punya data (Y-m) untuk pemilih periode. */
    public static function bulanAda(): Collection
    {
        $uj = DB::table('uj_detail')->whereNotNull('no_mobil')->whereNotNull('tanggal')->selectRaw("DISTINCT DATE_FORMAT(tanggal, '%Y-%m') b")->pluck('b');
        $kas = DB::table('kas_bon')->whereNotNull('no_mobil')->whereNotNull('tanggal')->selectRaw("DISTINCT DATE_FORMAT(tanggal, '%Y-%m') b")->pluck('b');

        return $uj->concat($kas)->unique()->sort()->values();
    }
}
