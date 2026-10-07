<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tebak galian sebuah rit dari keterangan Kas UJ DO-nya ("Uang tanah Sajira", "UJ 1 Rit Sajira ke PIK", "… Putar arah ke Gunung Guruh").
 * Nama tempat di UJ diterjemahkan ke nama galian di lembar Ritasi lewat 5 rit terbaru yang memakai tempat itu (diutamakan tahap
 * yang sama), karena penulisan berbeda ("Sajira" → "Sajirah") dan galian bisa berganti nama. Diukur 7 Okt 2026: ±90% tepat
 * (uji bergulir 10 Sep–7 Okt); sisanya UJ & ritasi memang mencatat tempat berbeda — admin tetap bisa mengganti.
 */
class TebakGalian
{
    private const TERBARU = 5;

    private static function bersih(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', '', strtolower($s))));
    }

    /** @return array{0: string, 1: int}|null nama tempat & kekuatan (3 putar arah, 2 uang tanah, 1 rute UJ) */
    public static function tempatDari(string $keterangan): ?array
    {
        $t = strtolower($keterangan);
        if (preg_match('/putar arah ke ([a-z ]+?)(?:[,.;]|$| kekurangan| awal)/', $t, $m)) {
            return [self::bersih($m[1]), 3];
        }
        if (preg_match('/uang tanah ([a-z ]+?)(?:[,.;(]|$| tgl| tanggal| kekurangan)/', $t, $m)) {
            return [self::bersih($m[1]), 2];
        }
        if (preg_match('/rit ([a-z ]+?) ke ([a-z ]+?)(?:[,.;(]|$| tgl| tanggal| kekurangan)/', $t, $m)) {
            $a = self::bersih($m[1]);

            return [str_contains($a, 'pik') ? self::bersih($m[2]) : $a, 1];
        }

        return null;
    }

    /** Tempat terkuat dari beberapa keterangan UJ satu DO (yang terakhir menang bila sama kuat). */
    public static function tempat(iterable $keterangan): ?string
    {
        $pilih = null;
        foreach ($keterangan as $k) {
            $x = self::tempatDari((string) $k);
            if ($x && $x[0] !== '' && (! $pilih || $x[1] >= $pilih[1])) {
                $pilih = $x;
            }
        }

        return $pilih[0] ?? null;
    }

    /**
     * Peta tempat → galian terbaru (dan tahap|tempat → galian), dari rit yang DO-nya ada di Kas UJ. Disimpan 30 menit.
     *
     * @return array{tempat: array<string, string[]>, galian: string[]}
     */
    private static function peta(): array
    {
        return Cache::remember('tebak-galian', 1800, function () {
            $kunci = fn ($v) => LembarRitasi::kunciAngka($v);
            $tempatDo = DB::table('uj_detail')->whereNotNull('no_do')->where('biaya_transfer', 0)->whereIn('kategori', ['Uang Jalan', 'Uang Tanah'])
                ->where('tanggal', '>=', now()->subMonths(18))->orderBy('baris')->get(['no_do', 'keterangan'])
                ->groupBy(fn ($d) => $kunci($d->no_do))->map(fn ($g) => self::tempat($g->pluck('keterangan')))->filter();
            $peta = [];
            foreach (DB::table('ritasi')->whereNotNull('no_do')->whereNotNull('galian')->where('tanggal', '>=', now()->subMonths(18))
                ->orderBy('tanggal')->orderBy('baris')->get(['no_do', 'galian', 'tahap']) as $r) {
                if ($p = $tempatDo[$kunci($r->no_do)] ?? null) {
                    $peta[$p][] = $r->galian;
                    $peta[mb_strtolower((string) $r->tahap).'|'.$p][] = $r->galian;
                }
            }

            return [
                'tempat' => array_map(fn ($d) => array_slice($d, -self::TERBARU), $peta),
                'galian' => DB::table('ritasi')->where('tanggal', '>=', now()->subYear())->whereNotNull('galian')->distinct()->pluck('galian')->all(),
            ];
        });
    }

    public static function galian(?string $tempat, ?string $tahap): ?string
    {
        if (! $tempat) {
            return null;
        }
        $peta = self::peta();
        $riwayat = $peta['tempat'][mb_strtolower((string) $tahap).'|'.$tempat] ?? $peta['tempat'][$tempat] ?? null;
        if ($riwayat) {
            return collect($riwayat)->countBy()->sortDesc()->keys()->first();
        }
        // Tempat baru: cocokkan ke nama galian yang mirip ("citeureup" → "Citareup"), kalau tidak ada pakai nama tempatnya.
        $pilih = null;
        $skor = 0;
        foreach ($peta['galian'] as $g) {
            similar_text(self::bersih($g), $tempat, $persen);
            if ($persen > $skor) {
                [$pilih, $skor] = [$g, $persen];
            }
        }

        return $skor >= 75 ? $pilih : ucwords($tempat);
    }
}
