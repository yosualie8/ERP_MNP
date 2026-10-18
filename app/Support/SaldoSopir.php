<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use Illuminate\Support\Collection;

/**
 * Saldo sopir (owner 18 Okt 2026): UJ yang DIALIHKAN (truk pindah proyek sebelum bongkar) = uang perusahaan yang masih dipegang
 * sopir — belum biaya. Saldo berkurang saat terpakai:
 *  - DO lama dipakai: DO UJ itu bongkar (ada di Ritasi, tanggal rit ≥ tanggal UJ) — biaya ikut proyek tempat bongkarnya;
 *  - "pakai": Uang MOB / UJ ber-DO baru lewat Pengajuan UJ (centang Pakai saldo) / lain-lain.
 * Saldo = alih − terpakai lewat DO lama − pakai.
 */
class SaldoSopir
{
    private static ?Collection $cache = null;

    public static function kunciDo(?string $do): ?string
    {
        return LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $do));
    }

    public static function lupakan(): void
    {
        self::$cache = null;
    }

    /**
     * @return Collection<string, array{no_mobil: string, nama: ?string, tahap_id: ?int, alih: float, terpakai_do: float, pakai: float, saldo: float,
     *                                   uj: Collection, pakai_rows: Collection}>
     */
    public static function perMobil(): Collection
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $semua = UjPengalihan::whereIn('jenis', ['alih', 'pakai'])->orderBy('tanggal')->orderBy('id')->get();
        $alih = $semua->where('jenis', 'alih');
        $uj = UjDetail::whereIn('id_uj', $alih->pluck('id_uj')->filter())->get(['id_uj', 'no_do', 'tanggal'])->keyBy('id_uj');
        $doAlih = $uj->map(fn ($u) => self::kunciDo($u->no_do))->filter()->unique();
        // rit pertama per DO (hanya DO yang dialihkan) — DO lama dipakai bila bongkar sesudah UJ-nya diberikan
        $rit = [];
        if ($doAlih->isNotEmpty()) {
            foreach (Ritasi::whereNotNull('no_do')->orderBy('tanggal')->get(['no_do', 'tanggal', 'tahap']) as $r) {
                $k = self::kunciDo($r->no_do);
                if ($k !== null && $doAlih->contains($k)) {
                    $rit[$k][] = $r;
                }
            }
        }

        return self::$cache = $semua->groupBy('no_mobil')->map(function (Collection $g, $mobil) use ($uj, $rit) {
            $baris = $g->where('jenis', 'alih')->map(function ($a) use ($uj, $rit) {
                $u = $uj[$a->id_uj] ?? null;
                $k = self::kunciDo($u?->no_do);
                $bongkar = $k ? collect($rit[$k] ?? [])->first(fn ($r) => $u->tanggal && $r->tanggal->gte($u->tanggal->copy()->startOfDay())) : null;

                return (object) ['p' => $a, 'no_do' => $u?->no_do ? ltrim(trim($u->no_do), "`'") : null, 'kunci' => $k, 'bongkar' => $bongkar];
            })->values();
            $pakai = $g->where('jenis', 'pakai')->values();
            $alihRp = (float) $baris->sum(fn ($x) => $x->p->nominal);
            $terpakai = (float) $baris->filter(fn ($x) => $x->bongkar)->sum(fn ($x) => $x->p->nominal);
            $pakaiRp = (float) $pakai->sum('nominal');

            return ['no_mobil' => (string) $mobil, 'nama' => $g->where('jenis', 'alih')->last()?->nama ?? $g->last()->nama,
                'tahap_id' => $g->where('jenis', 'alih')->last()?->ke_tahap_id ?? $g->last()->ke_tahap_id,
                'alih' => $alihRp, 'terpakai_do' => $terpakai, 'pakai' => $pakaiRp, 'saldo' => round($alihRp - $terpakai - $pakaiRp, 2),
                'uj' => $baris, 'pakai_rows' => $pakai];
        });
    }

    /** Saldo satu mobil; $kecualiDetail = detail pengajuan yang sedang diubah (pemakaiannya dihitung kembali tersedia). */
    public static function sisa(?string $mobil, array $kecualiDetail = []): float
    {
        $m = self::perMobil()[(string) $mobil] ?? null;
        if (! $m) {
            return 0.0;
        }

        return $m['saldo'] + (float) $m['pakai_rows']->whereIn('uj_pengajuan_detail_id', $kecualiDetail ?: [0])->sum('nominal');
    }
}
