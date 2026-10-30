<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Saldo sopir (owner 20 Okt 2026): UJ yang belum bongkar dan DIJADIKAN SALDO SOPIR = uang perusahaan yang masih dipegang sopir —
 * belum biaya proyek mana pun (masa tunggu). Saldo dihitung PER SOPIR (nama baku huruf besar), bukan per truk: sopir sering pindah
 * truk. Proyek tidak dipilih saat dialihkan; biaya baru ditentukan saat saldo berkurang:
 *  - DO lama ternyata bongkar (ada di Ritasi, tanggal rit ≥ tanggal UJ) → biaya proyek tempat DO itu bongkar;
 *  - dipotong di Pengajuan UJ (jumlah per transaksi ditentukan admin) → biaya proyek UJ itu (DO bongkar di mana / kata kunci keterangan, sama seperti UJ biasa);
 *  - dipakai manual (Uang MOB / lain) → proyek dipilih saat dicatat;
 *  - dikembalikan sopir → bukan biaya; tidak kembali → dibebankan ke proyek asal.
 * Saldo = dijadikan saldo + kasbon (Kas Harian) − terpakai lewat DO lama − berkurang (pakai). Umur = hari sejak UJ tertua yang saldonya belum terpakai (FIFO).
 * Owner 27 Okt 2026: saldo & kasbon = satu buku UTANG SOPIR (uang PT di tangan sopir) — ➕ nambah utang / ➖ potong utang di menu Pengalihan UJ.
 * Owner 30 Okt 2026 (menu Hutang Supir): utang sopir = saldo di luar DO (buku ini) + UJ belum bongkar — setiap uang jalan yang diberikan = utang,
 * ritnya bongkar = pengurang. Buku di-settle per akhir SETTLE ke rekap admin (Eddy); riwayat() mulai dari saldo awal itu.
 */
class SaldoSopir
{
    /** Umur saldo (hari): lewat KUNING = kuning, lewat MERAH = merah (perlu ditagih / diputuskan). */
    public const KUNING = 7;

    public const MERAH = 14;

    /** Akhir hari settle buku Hutang Supir (owner 30 Okt 2026: saldo per 29 Okt 24.00 = rekap Excel Eddy "JOGJA KENDAL"). */
    public const SETTLE = '2026-10-29';

    private static ?Collection $cache = null;

    private static ?array $ritDo = null;

    private static array $tahapPakai = [];

    public static function kunciDo(?string $do): ?string
    {
        return LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $do));
    }

    /** Nama sopir baku: sopir di master (termasuk nama lainnya, mis. WSAL) → nama di Data Karyawan (Wisal); selain itu huruf besar, spasi tunggal. */
    public static function kunci(?string $nama): ?string
    {
        $v = DaftarSopir::kunci($nama);

        return $v === null ? null : (DaftarSopir::cari($v)['nama'] ?? $v);
    }

    public static function lupakan(): void
    {
        DaftarSopir::lupakan();
        self::$cache = null;
        self::$ritDo = null;
        self::$tahapPakai = [];
    }

    /** Rit per kunci DO (urut tanggal): [kunci => [Ritasi...]] — hanya kolom no_do, tanggal, tahap, galian. */
    private static function ritDo(): array
    {
        if (self::$ritDo === null) {
            self::$ritDo = [];
            foreach (Ritasi::whereNotNull('no_do')->orderBy('tanggal')->get(['no_do', 'tanggal', 'tahap', 'galian']) as $r) {
                if (($k = self::kunciDo($r->no_do)) !== null) {
                    self::$ritDo[$k][] = $r;
                }
            }
        }

        return self::$ritDo;
    }

    /**
     * @return Collection<string, array{sopir: string, mobil: string[], alih: float, n_alih: int, terpakai_do: float, dipakai: float, kembali: float,
     *                                   hangus: float, pakai: float, saldo: float, umur: ?int, status: string, sejak: ?\Illuminate\Support\Carbon,
     *                                   uj: Collection, pakai_rows: Collection}>
     */
    public static function perSopir(): Collection
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $semua = UjPengalihan::with(['dari', 'ke'])->whereIn('jenis', UjPengalihan::BUKU)->orderBy('tanggal')->orderBy('id')->get();
        $uj = UjDetail::whereIn('id_uj', $semua->where('jenis', 'alih')->pluck('id_uj')->filter())->get(['id_uj', 'no_do', 'tanggal', 'keterangan', 'kategori'])->keyBy('id_uj');
        $rit = self::ritDo();
        $hariIni = now()->startOfDay();

        $hasil = $semua->groupBy(fn ($p) => self::kunci($p->sopir ?: $p->nama) ?? '(TANPA NAMA)')->map(function (Collection $g, $sopir) use ($uj, $rit, $hariIni) {
            $baris = $g->where('jenis', 'alih')->map(function ($a) use ($uj, $rit) {
                $u = $uj[$a->id_uj] ?? null;
                $k = self::kunciDo($u?->no_do);
                $bongkar = $k ? collect($rit[$k] ?? [])->first(fn ($r) => $u->tanggal && $r->tanggal->gte($u->tanggal->copy()->startOfDay())) : null;

                return (object) ['p' => $a, 'u' => $u, 'no_do' => $u?->no_do ? ltrim(trim($u->no_do), "`'") : null, 'kunci' => $k, 'bongkar' => $bongkar,
                    'tahap_bongkar' => $bongkar ? DaftarProyek::tahapId('ritasi', $bongkar->tahap) : null];
            })->values();
            $pakai = $g->where('jenis', 'pakai')->values();
            $kasbon = $g->where('jenis', 'kasbon')->values();
            $settle = $g->where('jenis', 'settle')->values();
            $alih = (float) $baris->sum(fn ($x) => $x->p->nominal);
            $kasbonRp = (float) $kasbon->sum('nominal');
            $settleRp = (float) $settle->sum('nominal');
            $terpakai = (float) $baris->filter(fn ($x) => $x->bongkar)->sum(fn ($x) => $x->p->nominal);
            $per = fn (array $jenis) => (float) $pakai->whereIn('peruntukan', $jenis)->sum('nominal');
            $pakaiRp = (float) $pakai->sum('nominal');
            $saldo = round($alih + $kasbonRp + $settleRp - $terpakai - $pakaiRp, 2);

            // Umur (FIFO): pemakaian menghabiskan utang tertua dulu (UJ jadi saldo & kasbon); yang tertua masih bersisa = awal masa tunggu.
            // Kasbon memang dicicil lama → bila yang tersisa kasbon, status "kasbon" (tanpa warna umur).
            $sejak = null;
            $sisaKasbon = false;
            if ($saldo > 0.5) {
                $sisaPakai = $pakaiRp - (float) $settle->where('nominal', '<', 0)->sum('nominal');
                $antre = $baris->reject(fn ($x) => $x->bongkar)->map(fn ($x) => $x->p)->concat($kasbon)->concat($settle->where('nominal', '>', 0))
                    ->sortBy(fn ($p) => $p->tanggal->format('Ymd').sprintf('%08d', $p->id));
                foreach ($antre as $p) {
                    if ($sisaPakai + 0.5 >= $p->nominal) {
                        $sisaPakai -= $p->nominal;

                        continue;
                    }
                    $sisaKasbon = $p->jenis === 'kasbon';
                    $sejak = $sisaKasbon ? null : $p->tanggal;
                    break;
                }
            }
            $umur = $sejak ? (int) $sejak->copy()->startOfDay()->diffInDays($hariIni) : null;

            return ['sopir' => (string) $sopir, 'mobil' => $g->pluck('no_mobil')->filter(fn ($m) => $m && $m !== '-')->reverse()->unique()->values()->all(),
                'alih' => $alih, 'n_alih' => $baris->count(), 'kasbon' => $kasbonRp, 'settle' => $settleRp, 'terpakai_do' => $terpakai,
                'dipakai' => $per(['uj', 'mob', 'lain']), 'kembali' => $per(['kembali', 'gaji', 'sebelum']), 'hangus' => $per(['hangus']), 'pakai' => $pakaiRp, 'saldo' => $saldo,
                'umur' => $umur, 'sejak' => $sejak,
                'status' => $saldo <= 0.5 ? 'habis' : ($sisaKasbon ? 'kasbon' : ($umur > self::MERAH ? 'merah' : ($umur > self::KUNING ? 'kuning' : 'baru'))),
                'uj' => $baris, 'kasbon_rows' => $kasbon, 'settle_rows' => $settle, 'pakai_rows' => $pakai, 'terakhir' => $g->max('tanggal')];
        });

        return self::$cache = $hasil;
    }

    /** Isi kosong perSopir() untuk sopir yang belum punya catatan di buku. */
    public static function kosong(string $sopir): array
    {
        return ['sopir' => $sopir, 'mobil' => array_values(array_filter([DaftarSopir::cari($sopir)['mobil'] ?? null])), 'alih' => 0.0, 'n_alih' => 0, 'kasbon' => 0.0,
            'settle' => 0.0, 'terpakai_do' => 0.0, 'dipakai' => 0.0, 'kembali' => 0.0, 'hangus' => 0.0, 'pakai' => 0.0, 'saldo' => 0.0, 'umur' => null, 'sejak' => null,
            'status' => 'habis', 'uj' => collect(), 'kasbon_rows' => collect(), 'settle_rows' => collect(), 'pakai_rows' => collect(), 'terakhir' => null];
    }

    /**
     * UJ belum bongkar per sopir (owner 30 Okt 2026: uang jalan yang sudah diberikan = utang sopir sampai ritnya bongkar): baris Uang Jalan DO
     * yang belum ada di Ritasi — termasuk uang jalan DO yang dipotong dari utang sopir — di luar data sebelum KontrolUj::SEJAK.
     *
     * @return Collection<string, Collection<int, object>> [SOPIR => baris {do, tgl, id_uj, nama, mobil, ket, rp, umur, status, tahap}]
     */
    public static function ujBelum(): Collection
    {
        $hasil = [];
        foreach (KontrolUj::terbuka() as $d) {
            if ($d['lama']) {
                continue;
            }
            foreach ($d['detail'] as $u) {
                if (in_array($u->kategori, KontrolUj::KATEGORI, true)) {
                    $hasil[self::kunci($u->nama) ?? '(TANPA NAMA)'][] = (object) ['do' => $d['do'], 'tgl' => $u->tanggal, 'id_uj' => $u->id_uj, 'nama' => $u->nama,
                        'mobil' => $u->no_mobil, 'ket' => $u->keterangan, 'rp' => (float) $u->nominal, 'umur' => $d['umur'], 'status' => $d['status'], 'tahap' => $d['tahap']];
                }
            }
        }

        return collect($hasil)->map(fn ($b) => collect($b)->sortBy(fn ($x) => ($x->tgl?->format('Ymd') ?? '').$x->do)->values());
    }

    /**
     * Utang per sopir (owner 30 Okt 2026) = saldo di luar DO (perSopir) + UJ belum bongkar.
     *
     * @return Collection<string, array{sopir: string, saldo: array, belum: Collection, belum_rp: float, total: float}>
     */
    public static function hutang(): Collection
    {
        $saldo = self::perSopir();
        $belum = self::ujBelum();

        return $saldo->keys()->merge($belum->keys())->unique()->values()->mapWithKeys(function ($k) use ($saldo, $belum) {
            $b = $belum[$k] ?? collect();
            $s = $saldo[$k] ?? self::kosong($k);

            return [$k => ['sopir' => $k, 'saldo' => $s, 'belum' => $b, 'belum_rp' => (float) $b->sum('rp'), 'total' => round($s['saldo'] + (float) $b->sum('rp'), 2)]];
        });
    }

    /**
     * Riwayat utang satu sopir sejak settle (owner 30 Okt 2026), urut tanggal dengan sisa berjalan:
     *  ➕ uang jalan diberikan (Kas UJ), kasbon, penyesuaian;  ➖ rit bongkar (senilai uang jalan DO itu: Kas UJ + potongan utang ber-DO sama),
     *  potongan utang (MOB, gaji, dikembalikan, tidak kembali …), UJ dikembalikan / ditutup tanpa bongkar.
     * Uang jalan DO yang dipotong dari utang dan UJ yang dijadikan saldo hanya berpindah (saldo ↔ DO), tidak mengubah utang.
     * Saldo awal = utang sekarang − semua mutasi sesudah SETTLE. Rute (owner 30 Okt 2026) = galian → tempat bongkar: dari rit DO itu bila sudah
     * bongkar, selain itu ditebak dari keterangan Kas UJ-nya (TebakGalian; proyek dari kata kunci UJ).
     *
     * @return array{awal: float, akhir: float, baris: Collection<int, array{tgl, jenis, id, do, rute: ?string, ket, masuk: ?float, keluar: ?float, sisa: float}>}
     */
    public static function riwayat(string $sopir): array
    {
        $batas = Carbon::parse(self::SETTLE)->endOfDay();
        $milik = fn ($nama) => self::kunci($nama) === $sopir;
        $baris = collect();

        // DO milik sopir: uang jalan Kas UJ atas namanya + potongan utang ber-DO (uang jalan DO baru dari utang).
        $kas = UjDetail::where('kategori', 'Uang Jalan')->where('biaya_transfer', false)->where('tanggal', '>=', KontrolUj::SEJAK)
            ->get(['id_uj', 'tanggal', 'nama', 'nominal', 'no_do', 'keterangan'])->filter(fn ($u) => $milik($u->nama))->values();
        $buku = UjPengalihan::whereIn('jenis', [...UjPengalihan::BUKU, 'kembali', 'batal'])->get()->filter(fn ($p) => $milik($p->sopir ?: $p->nama))->values();
        $dariUtang = $buku->where('jenis', 'pakai')->where('peruntukan', 'uj')->filter(fn ($p) => self::kunciDo($p->no_do) !== null);
        $tutup = $buku->whereIn('jenis', ['kembali', 'batal'])->whereNotNull('id_uj')->keyBy('id_uj');
        $do = fn ($v) => ltrim(trim((string) $v), "`'");

        $rit = self::ritDo();
        $kata = KontrolUj::tahapKata();
        $ketDo = $kas->groupBy(fn ($u) => (string) self::kunciDo($u->no_do))->map(fn ($g) => $g->pluck('keterangan')->filter()->all());
        $ritPertama = function (?string $k, $tgl) use ($rit) {
            $daftar = collect($k !== null ? $rit[$k] ?? [] : []);

            return $tgl ? ($daftar->first(fn ($r) => $r->tanggal->gte($tgl->copy()->startOfDay())) ?? $daftar->first()) : $daftar->first();
        };
        $rute = function (?string $no, $tgl = null) use ($ketDo, $ritPertama, $kata) {
            if (($k = self::kunciDo($no)) === null) {
                return null;
            }
            $r = $ritPertama($k, $tgl);
            $ket = $ketDo[$k] ?? [];
            $tempat = $r?->galian ? null : TebakGalian::tempat($ket);
            $galian = $r?->galian ?: ($tempat ? (TebakGalian::galian($tempat, null) ?? ucwords($tempat)) : null);
            $teks = implode(' | ', $ket);
            $buang = $r?->tahap ?: ($kata->first(fn ($x) => preg_match("/\\b({$x['pola']})\\b/i", $teks))['t']->nama ?? null);

            return $galian && $buang ? "{$galian} → {$buang}" : ($galian ? "dari {$galian}" : ($buang ? "ke {$buang}" : null));
        };

        foreach ($kas->filter(fn ($u) => $u->tanggal && $u->tanggal->gt($batas)) as $u) {
            $baris[] = ['tgl' => $u->tanggal, 'jenis' => 'Uang jalan diberikan', 'id' => $u->id_uj, 'do' => $do($u->no_do), 'rute' => $rute($u->no_do, $u->tanggal),
                'ket' => $u->keterangan, 'masuk' => (float) $u->nominal, 'keluar' => null];
        }
        // Rit bongkar sesudah settle = nilai uang jalan DO itu (UJ yang dikembalikan / ditutup tanpa bongkar tidak dihitung).
        $perDo = [];
        $tambah = function (?string $no, $tgl, float $rp) use (&$perDo, $do) {
            if (($k = self::kunciDo($no)) === null || ! $tgl) {
                return;
            }
            $perDo[$k] = ['rp' => ($perDo[$k]['rp'] ?? 0) + $rp, 'tgl' => isset($perDo[$k]) ? min($perDo[$k]['tgl'], $tgl) : $tgl, 'do' => $do($no)];
        };
        foreach ($kas as $u) {
            if (! isset($tutup[$u->id_uj])) {
                $tambah($u->no_do, $u->tanggal, (float) $u->nominal);
            }
        }
        foreach ($dariUtang as $p) {
            $tambah($p->no_do, $p->tanggal, (float) $p->nominal);
        }
        foreach ($perDo as $k => $d) {
            $r = $ritPertama((string) $k, $d['tgl']);
            if ($r && $r->tanggal->gt($batas)) {
                $baris[] = ['tgl' => $r->tanggal, 'jenis' => 'Rit bongkar', 'id' => null, 'do' => $d['do'], 'rute' => $rute($d['do'], $d['tgl']), 'ket' => $r->tahap, 'masuk' => null, 'keluar' => $d['rp']];
            }
        }
        foreach ($buku->filter(fn ($p) => $p->tanggal->gt($batas)) as $p) {
            if (in_array($p->jenis, ['kasbon', 'settle'], true)) {
                $baris[] = ['tgl' => $p->tanggal, 'jenis' => UjPengalihan::JENIS[$p->jenis], 'id' => $p->kas_no_id ? 'NO ID '.$p->kas_no_id : null, 'do' => null, 'rute' => null, 'ket' => $p->keterangan,
                    'masuk' => $p->nominal > 0 ? (float) $p->nominal : null, 'keluar' => $p->nominal < 0 ? -(float) $p->nominal : null];
            } elseif ($p->jenis === 'pakai' && $p->peruntukan !== 'uj') {
                $baris[] = ['tgl' => $p->tanggal, 'jenis' => UjPengalihan::PERUNTUKAN[$p->peruntukan] ?? 'Dipakai', 'id' => $p->kas_no_id ? 'NO ID '.$p->kas_no_id : null,
                    'do' => $p->no_do, 'rute' => $rute($p->no_do), 'ket' => $p->keterangan, 'masuk' => null, 'keluar' => (float) $p->nominal];
            } elseif (in_array($p->jenis, ['kembali', 'batal'], true)) {
                $baris[] = ['tgl' => $p->tanggal, 'jenis' => UjPengalihan::JENIS[$p->jenis], 'id' => $p->id_uj, 'do' => $p->no_do, 'rute' => $rute($p->no_do), 'ket' => $p->keterangan,
                    'masuk' => null, 'keluar' => (float) $p->nominal];
            }
        }
        $akhir = (float) (self::hutang()[$sopir]['total'] ?? 0);
        $awal = round($akhir - $baris->sum(fn ($b) => ($b['masuk'] ?? 0) - ($b['keluar'] ?? 0)), 2);
        $jalan = $awal;
        $baris = $baris->sortBy(fn ($b) => $b['tgl']->format('Ymd').($b['masuk'] ? '0' : '1'))->values()->map(function ($b) use (&$jalan) {
            $jalan += ($b['masuk'] ?? 0) - ($b['keluar'] ?? 0);

            return [...$b, 'sisa' => round($jalan, 2)];
        });

        return ['awal' => $awal, 'akhir' => $akhir, 'baris' => $baris];
    }

    /** Saldo satu sopir; $kecualiDetail = detail pengajuan yang sedang diubah (pemakaiannya dihitung kembali tersedia). */
    public static function sisa(?string $sopir, array $kecualiDetail = []): float
    {
        $s = self::perSopir()[self::kunci($sopir) ?? ''] ?? null;
        if (! $s) {
            return 0.0;
        }

        // hanya potongan dari detail pengajuan yang sedang diubah (perbandingan ketat: pemakaian manual ber-detail kosong tidak ikut)
        $kecuali = array_map('intval', $kecualiDetail);

        return $s['saldo'] + (float) $s['pakai_rows']->filter(fn ($p) => $p->uj_pengajuan_detail_id && in_array((int) $p->uj_pengajuan_detail_id, $kecuali, true))->sum('nominal');
    }

    /** DO lama (UJ yang dijadikan saldo, belum bongkar) per sopir: [SOPIR => [kunci DO => id_uj]] — saldonya terpakai sendiri saat DO itu bongkar. */
    public static function doLama(): array
    {
        return self::perSopir()->map(fn ($s) => $s['uj']->reject(fn ($x) => $x->bongkar || ! $x->kunci)->mapWithKeys(fn ($x) => [$x->kunci => $x->p->id_uj])->all())
            ->filter()->all();
    }

    /**
     * Proyek (tahap) yang menanggung satu pemakaian saldo: proyek yang dipilih saat dicatat; tidak kembali → proyek asal; dikembalikan → bukan biaya;
     * lewat Pengajuan UJ → tempat DO-nya bongkar, atau kata kunci proyek di keterangan (sama seperti UJ biasa).
     */
    public static function tahapPakai(UjPengalihan $p): ?int
    {
        if (array_key_exists($p->id, self::$tahapPakai)) {
            return self::$tahapPakai[$p->id];
        }
        $tahap = null;
        if ($p->jenis === 'pakai' && ! in_array($p->peruntukan, UjPengalihan::BUKAN_BIAYA, true)) {
            $tahap = $p->ke_tahap_id ?: ($p->peruntukan === 'hangus' ? $p->dari_tahap_id : null);
            if (! $tahap && ($k = self::kunciDo($p->no_do))) {
                // rit DO itu paling cepat 3 hari sebelum pengajuan (nomor DO bisa terpakai ulang di proyek lain jauh sebelumnya)
                $r = collect(self::ritDo()[$k] ?? [])->first(fn ($r) => $r->tanggal->gte($p->tanggal->copy()->subDays(3)));
                $tahap = $r ? DaftarProyek::tahapId('ritasi', $r->tahap) : null;
            }
            if (! $tahap) {
                $ket = (string) $p->keterangan;
                $tahap = KontrolUj::tahapKata()->first(fn ($x) => preg_match("/\\b({$x['pola']})\\b/i", $ket))['t']->id ?? null;
            }
        }

        return self::$tahapPakai[$p->id] = $tahap;
    }
}
