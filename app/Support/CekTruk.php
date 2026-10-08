<?php

namespace App\Support;

use App\Models\AsetTruk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pencocokan truk MNP (Data Aset) × Ritasi × Kas UJ sejak 1 Jan 2026 (sebelumnya ritasi belum mencatat nomor DT).
 * A biaya truk tanpa nomor truk · B nomor truk tidak valid / bukan aset MNP · C uang jalan tanpa rit · D rit truk MNP tanpa
 * uang jalan · E DT beda ritasi vs Kas UJ · F driver beda · G uang jalan dobel satu DO · H uang jalan untuk truk tidak beroperasi.
 */
class CekTruk
{
    public const SEJAK = '2026-01-01';

    public const JENIS = [
        'A' => ['Biaya truk tanpa nomor truk', 'Uang jalan / UM / sparepart dibayar tanpa nomor truk — lengkapi No Mobil di Kas UJ.'],
        'B' => ['Nomor truk tidak valid / bukan aset MNP', 'No Mobil bukan format "DT 000" atau tidak terdaftar di Data Aset — perbaiki penulisan atau daftarkan truknya.'],
        'C' => ['Uang jalan tanpa rit', 'Uang jalan dibayar tapi DO-nya tidak ada di ritasi — rit belum dicatat, DO salah ketik, atau uang jalan tanpa rit.'],
        'D' => ['Rit truk MNP tanpa uang jalan', 'Rit tercatat tapi DO-nya tidak ada di Kas UJ — uang jalan belum dicatat / DO salah ketik / DO kosong.'],
        'E' => ['DT beda ritasi vs Kas UJ', 'DO sama tapi nomor DT di ritasi dan Kas UJ berbeda — salah satu salah tulis.'],
        'F' => ['Driver beda ritasi vs Kas UJ', 'DO sama tapi nama driver berbeda — ganti driver atau salah tulis.'],
        'G' => ['Uang jalan dobel untuk satu DO', 'Satu DO dibayar uang jalan lebih dari sekali (tanpa kata "Kekurangan") — cek pembayaran dobel / DO salah ketik.'],
        'H' => ['Uang jalan untuk truk tidak beroperasi', 'Uang jalan untuk truk yang ritnya sudah >30 hari berhenti — cek truknya masih jalan atau salah nomor.'],
    ];

    /** Nomor DT truk MNP: dari Data Aset (kecuali yang sudah dijual/keluar). */
    public static function aset(): Collection
    {
        return AsetTruk::where('status', '!=', 'dijual')->pluck('no_lambung');
    }

    /**
     * Semua temuan (disimpan 10 menit; dihitung ulang bila ritasi/Kas UJ baru diimpor atau Data Aset berubah).
     *
     * @return Collection<int, array{kode: string, tanggal: string, mobil: ?string, ref: string, do: ?string, nama: ?string, ket: ?string, kategori: ?string, nominal: int, rincian: string}>
     */
    public static function temuan(): Collection
    {
        $versi = md5(Cache::get('ritasi-diimpor-pada').'|'.Cache::get('uj-diimpor-pada').'|'.AsetTruk::max('updated_at').'|'.AsetTruk::count());

        return collect(Cache::remember("cek-truk:{$versi}", 600, fn () => self::hitung()));
    }

    private static function hitung(): array
    {
        $k = fn ($v) => LembarRitasi::kunciAngka($v);
        $nama = fn ($n) => preg_replace('/[^a-z]/', '', strtolower((string) $n));
        $dtValid = fn ($m) => (bool) preg_match('/^DT \d{3}$/', (string) $m);
        $katUJ = fn ($x) => (bool) preg_match('/^uang\s*jalan$/i', trim((string) $x->kategori));
        $kekurangan = fn ($x) => stripos((string) $x->keterangan, 'kekurangan') !== false;
        $biayaTruk = ['uang jalan', 'uang tanah', 'uang perangsang', 'perangsang', 'uang makan', 'uang mob', 'sparepart', 'uang material'];

        $uj = DB::table('uj_detail')->where('biaya_transfer', 0)->where('tanggal', '>=', self::SEJAK)->orderBy('tanggal')->orderBy('baris')
            ->get(['id_uj', 'tanggal', 'nama', 'keterangan', 'kategori', 'no_mobil', 'no_do', 'nominal', 'baris']);
        $rit = DB::table('ritasi')->where('tanggal', '>=', self::SEJAK)->orderBy('tanggal')->orderBy('baris')
            ->get(['baris', 'tanggal', 'no_seri', 'no_lambung', 'no_polisi', 'no_do', 'tahap', 'galian']);
        $aset = self::aset()->flip();
        $terakhirRit = DB::table('ritasi')->whereNotNull('no_lambung')->selectRaw('no_lambung, MAX(tanggal) t')->groupBy('no_lambung')->pluck('t', 'no_lambung');
        $ritPerDo = $rit->filter(fn ($r) => $k($r->no_do))->groupBy(fn ($r) => $k($r->no_do));
        $ujPerDo = $uj->filter(fn ($u) => $k($u->no_do))->groupBy(fn ($u) => $k($u->no_do));

        $hasil = [];
        $catat = function (string $kode, $x, string $rincian, bool $dariUj) use (&$hasil) {
            $hasil[] = [
                'kode' => $kode, 'tanggal' => (string) $x->tanggal, 'mobil' => $dariUj ? $x->no_mobil : $x->no_lambung,
                'ref' => $dariUj ? ($x->id_uj ?: 'Kas Seabank baris '.$x->baris) : 'Ritasi baris '.$x->baris.' · Seri '.$x->no_seri,
                'do' => $x->no_do, 'nama' => $dariUj ? $x->nama : trim(explode('/', (string) $x->no_polisi)[1] ?? ''),
                'ket' => $dariUj ? $x->keterangan : trim($x->tahap.' · '.$x->galian, ' ·'), 'kategori' => $dariUj ? $x->kategori : 'Ritasi',
                'nominal' => $dariUj ? (int) $x->nominal : 0, 'rincian' => $rincian,
            ];
        };

        foreach ($uj as $u) {
            if (! $u->no_mobil && in_array(strtolower(trim((string) $u->kategori)), $biayaTruk, true)) {
                $catat('A', $u, 'No Mobil kosong'.($u->no_do ? "; DO {$u->no_do}".(isset($ritPerDo[$k($u->no_do)]) ? ' di ritasi tercatat untuk '.$ritPerDo[$k($u->no_do)]->pluck('no_lambung')->filter()->unique()->implode(', ') : '') : ''), true);
            } elseif ($u->no_mobil && ! $dtValid($u->no_mobil)) {
                $catat('B', $u, "No Mobil tertulis \"{$u->no_mobil}\"", true);
            } elseif ($u->no_mobil && ! isset($aset[$u->no_mobil])) {
                $catat('B', $u, "{$u->no_mobil} tidak terdaftar di Data Aset", true);
            }
            if ($katUJ($u) && ! $kekurangan($u)) {
                if (! $k($u->no_do)) {
                    $catat('C', $u, 'Tanpa No DO — tidak bisa dicocokkan ke ritasi', true);
                } elseif (! isset($ritPerDo[$k($u->no_do)])) {
                    $catat('C', $u, "DO {$u->no_do} tidak ada di ritasi", true);
                }
                if ($u->no_mobil && ($t = $terakhirRit[$u->no_mobil] ?? null) && Carbon::parse($u->tanggal)->gt(Carbon::parse($t)->addDays(30))) {
                    $catat('H', $u, "Rit terakhir {$u->no_mobil}: ".Carbon::parse($t)->translatedFormat('j M Y'), true);
                }
            }
        }
        foreach ($ujPerDo as $g) {
            $bayar = $g->filter(fn ($u) => $katUJ($u) && ! $kekurangan($u))->values();
            foreach ($bayar->slice(1) as $u) {
                $p = $bayar->first();
                $catat('G', $u, "DO {$u->no_do} sudah dibayar di {$p->id_uj} (".Carbon::parse($p->tanggal)->translatedFormat('j M').', '.number_format($p->nominal, 0, ',', '.').')', true);
            }
        }
        foreach ($rit as $r) {
            if (! $r->no_lambung || ! isset($aset[$r->no_lambung])) {
                continue;
            }
            $do = $k($r->no_do);
            if (! $do || preg_match('/\D/', $do)) {
                $catat('D', $r, 'No DO kosong / tidak valid'.($r->no_do ? ': '.mb_strimwidth((string) $r->no_do, 0, 40, '…') : ''), false);

                continue;
            }
            $g = $ujPerDo[$do] ?? null;
            if (! $g) {
                $catat('D', $r, "DO {$r->no_do} tidak ada di Kas UJ", false);

                continue;
            }
            $dtUj = $g->pluck('no_mobil')->filter()->unique();
            $utama = $g->first(fn ($u) => $katUJ($u)) ?? $g->first();
            if ($dtUj->isNotEmpty() && ! $dtUj->contains($r->no_lambung)) {
                $catat('E', $r, "Ritasi {$r->no_lambung}, Kas UJ ".$dtUj->implode('/')." ({$utama->id_uj})", false);
            }
            $drv = trim(explode('/', (string) $r->no_polisi)[1] ?? '');
            if ($drv && $utama->nama && ! str_contains($nama($utama->nama), $nama($drv)) && ! str_contains($nama($drv), $nama($utama->nama))) {
                $catat('F', $r, "Ritasi {$drv}, Kas UJ {$utama->nama} ({$utama->id_uj})", false);
            }
        }

        usort($hasil, fn ($a, $b) => [$b['tanggal'], $a['kode']] <=> [$a['tanggal'], $b['kode']]);

        return $hasil;
    }

    /**
     * Statistik operasional per truk.
     *
     * @return array<string, array{rit_30: int, rit: int, rit_terakhir: ?string, uj_30: int, uj_terakhir: ?string, driver: ?string, galian: ?string}>
     */
    public static function statistik(): array
    {
        $acuan = now()->subDays(30)->toDateString();
        $rit = DB::table('ritasi')->whereNotNull('no_lambung')->where('tanggal', '>=', self::SEJAK)
            ->selectRaw('no_lambung, COUNT(*) n, SUM(tanggal >= ?) n30, MAX(tanggal) t', [$acuan])->groupBy('no_lambung')->get()->keyBy('no_lambung');
        $uj = DB::table('uj_detail')->whereNotNull('no_mobil')->where('biaya_transfer', 0)
            ->selectRaw('no_mobil, SUM(CASE WHEN tanggal >= ? THEN nominal ELSE 0 END) n30, MAX(tanggal) t', [$acuan])->groupBy('no_mobil')->get()->keyBy('no_mobil');
        $driver = DB::table('uj_detail')->whereNotNull('no_mobil')->where('tanggal', '>=', now()->subDays(60)->toDateString())->whereNotNull('nama')
            ->orderBy('tanggal')->get(['no_mobil', 'nama'])->groupBy('no_mobil')->map(fn ($g) => $g->countBy(fn ($x) => ucwords(strtolower(trim($x->nama))))->sortDesc()->keys()->first());
        $galian = DB::table('ritasi')->whereNotNull('no_lambung')->where('tanggal', '>=', now()->subDays(60)->toDateString())->orderBy('tanggal')
            ->get(['no_lambung', 'galian'])->groupBy('no_lambung')->map(fn ($g) => $g->last()->galian);

        $hasil = [];
        foreach ($rit->keys()->merge($uj->keys())->unique() as $dt) {
            $hasil[$dt] = [
                'rit_30' => (int) ($rit[$dt]->n30 ?? 0), 'rit' => (int) ($rit[$dt]->n ?? 0), 'rit_terakhir' => $rit[$dt]->t ?? null,
                'uj_30' => (int) ($uj[$dt]->n30 ?? 0), 'uj_terakhir' => $uj[$dt]->t ?? null, 'driver' => $driver[$dt] ?? null, 'galian' => $galian[$dt] ?? null,
            ];
        }

        return $hasil;
    }
}
