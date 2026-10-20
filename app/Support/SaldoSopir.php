<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use Illuminate\Support\Collection;

/**
 * Saldo sopir (owner 20 Okt 2026): UJ yang belum bongkar dan DIJADIKAN SALDO SOPIR = uang perusahaan yang masih dipegang sopir —
 * belum biaya proyek mana pun (masa tunggu). Saldo dihitung PER SOPIR (nama baku huruf besar), bukan per truk: sopir sering pindah
 * truk. Proyek tidak dipilih saat dialihkan; biaya baru ditentukan saat saldo berkurang:
 *  - DO lama ternyata bongkar (ada di Ritasi, tanggal rit ≥ tanggal UJ) → biaya proyek tempat DO itu bongkar;
 *  - dipotong di Pengajuan UJ (otomatis) → biaya proyek UJ itu (DO bongkar di mana / kata kunci keterangan, sama seperti UJ biasa);
 *  - dipakai manual (Uang MOB / lain) → proyek dipilih saat dicatat;
 *  - dikembalikan sopir → bukan biaya; tidak kembali → dibebankan ke proyek asal.
 * Saldo = dijadikan saldo − terpakai lewat DO lama − berkurang (pakai). Umur = hari sejak UJ tertua yang saldonya belum terpakai (FIFO).
 */
class SaldoSopir
{
    /** Umur saldo (hari): lewat KUNING = kuning, lewat MERAH = merah (perlu ditagih / diputuskan). */
    public const KUNING = 7;

    public const MERAH = 14;

    private static ?Collection $cache = null;

    private static ?array $ritDo = null;

    private static array $tahapPakai = [];

    public static function kunciDo(?string $do): ?string
    {
        return LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $do));
    }

    /** Nama sopir baku: huruf besar, spasi tunggal ("Lupi " → "LUPI"); nama lain sopir di master (WSAL) → nama bakunya (WISAL). */
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

    /** Rit per kunci DO (urut tanggal): [kunci => [Ritasi...]] — hanya kolom no_do, tanggal, tahap. */
    private static function ritDo(): array
    {
        if (self::$ritDo === null) {
            self::$ritDo = [];
            foreach (Ritasi::whereNotNull('no_do')->orderBy('tanggal')->get(['no_do', 'tanggal', 'tahap']) as $r) {
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
        $semua = UjPengalihan::with(['dari', 'ke'])->whereIn('jenis', ['alih', 'pakai'])->orderBy('tanggal')->orderBy('id')->get();
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
            $alih = (float) $baris->sum(fn ($x) => $x->p->nominal);
            $terpakai = (float) $baris->filter(fn ($x) => $x->bongkar)->sum(fn ($x) => $x->p->nominal);
            $per = fn (array $jenis) => (float) $pakai->whereIn('peruntukan', $jenis)->sum('nominal');
            $pakaiRp = (float) $pakai->sum('nominal');
            $saldo = round($alih - $terpakai - $pakaiRp, 2);

            // Umur (FIFO): pemakaian menghabiskan UJ tertua dulu; UJ tertua yang masih bersisa = awal masa tunggu.
            $sejak = null;
            if ($saldo > 0.5) {
                $sisaPakai = $pakaiRp;
                foreach ($baris->reject(fn ($x) => $x->bongkar)->sortBy(fn ($x) => $x->p->tanggal->format('Ymd').sprintf('%08d', $x->p->id)) as $x) {
                    if ($sisaPakai + 0.5 >= $x->p->nominal) {
                        $sisaPakai -= $x->p->nominal;

                        continue;
                    }
                    $sejak = $x->p->tanggal;
                    break;
                }
            }
            $umur = $sejak ? (int) $sejak->copy()->startOfDay()->diffInDays($hariIni) : null;

            return ['sopir' => (string) $sopir, 'mobil' => $g->pluck('no_mobil')->filter(fn ($m) => $m && $m !== '-')->reverse()->unique()->values()->all(),
                'alih' => $alih, 'n_alih' => $baris->count(), 'terpakai_do' => $terpakai,
                'dipakai' => $per(['uj', 'mob', 'lain']), 'kembali' => $per(['kembali']), 'hangus' => $per(['hangus']), 'pakai' => $pakaiRp, 'saldo' => $saldo,
                'umur' => $umur, 'sejak' => $sejak,
                'status' => $saldo <= 0.5 ? 'habis' : ($umur > self::MERAH ? 'merah' : ($umur > self::KUNING ? 'kuning' : 'baru')),
                'uj' => $baris, 'pakai_rows' => $pakai, 'terakhir' => $g->max('tanggal')];
        });

        return self::$cache = $hasil;
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
