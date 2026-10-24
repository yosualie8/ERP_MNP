<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Ringkasan satu Pengajuan UJ (owner 24 Okt 2026): berapa rit & ke mana tujuannya. Rit = No DO yang tidak dibatalkan.
 * Galian & tujuan dibaca dari keterangan uang jalan ("Uang Jalan RKS JOGJA BAWEN" → RKS → Jogja Bawen, "Uang jalan sajira PIK"
 * → Sajira → PIK); baris uang tanah / lainnya ikut tujuan uang jalan DO yang sama.
 */
class RingkasPengajuan
{
    /** @return array{galian: ?string, tujuan: string} */
    public static function rute(?string $keterangan): array
    {
        $sisa = trim(preg_replace('/\s+/', ' ', preg_replace('/^\s*(uang\s+jalan|uj)\b/i', '', (string) $keterangan)));
        if ($sisa === '') {
            return ['galian' => null, 'tujuan' => 'Tanpa keterangan'];
        }
        static $galian = null;
        $galian ??= collect(TebakGalian::galianDikenal())->sortByDesc(fn ($g) => mb_strlen($g))->values();
        foreach ($galian as $g) {
            // nama galian di awal sisa keterangan (paling panjang dulu: "Gunung Guruh" sebelum "Gunung")
            if (preg_match('/^'.preg_quote($g, '/').'h?\b\s*(.*)$/iu', $sisa, $m)) {
                return ['galian' => $g, 'tujuan' => self::rapi($m[1]) ?: '?'];
            }
        }

        return ['galian' => null, 'tujuan' => self::rapi($sisa)];
    }

    private static function rapi(string $t): string
    {
        // Singkatan pendek tetap huruf besar (PIK, KIK, PM), selain itu huruf awal besar.
        return trim(collect(explode(' ', trim($t)))->filter()->map(fn ($w) => mb_strlen($w) <= 3 ? mb_strtoupper($w) : mb_convert_case(mb_strtolower($w), MB_CASE_TITLE))->implode(' '));
    }

    /**
     * @return array{rute: array<int, array>, rit: int, dt: int, batal: int}
     */
    public static function dari(Collection $detail): array
    {
        $kunciDo = fn ($d) => LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $d->no_do)) ?: null;
        // Rute per DO dari baris uang jalannya.
        $ruteDo = [];
        foreach ($detail as $d) {
            if (ValidasiUj::jenisKategori($d->kategori) === ValidasiUj::jenisKategori('Uang Jalan') && ($k = $kunciDo($d)) && ! isset($ruteDo[$k])) {
                $ruteDo[$k] = self::rute($d->keterangan);
            }
        }
        $kelompok = [];
        foreach ($detail as $d) {
            $k = $kunciDo($d);
            $r = ($k ? $ruteDo[$k] ?? null : null) ?? ['galian' => null, 'tujuan' => 'Tanpa uang jalan di pengajuan ini'];
            $id = ($r['galian'] ?? '').'|'.$r['tujuan'];
            $kelompok[$id] ??= ['galian' => $r['galian'], 'tujuan' => $r['tujuan'], 'do' => [], 'do_batal' => [], 'dt' => [], 'uj' => [], 'ut' => [], 'lain' => [], 'rp' => 0];
            $g = &$kelompok[$id];
            $jenis = ValidasiUj::jenisKategori($d->kategori);
            $kat = $jenis === ValidasiUj::jenisKategori('Uang Jalan') ? 'uj' : ($jenis === ValidasiUj::jenisKategori('Uang Tanah') ? 'ut' : 'lain');
            $g[$kat][$d->status] = ($g[$kat][$d->status] ?? 0) + 1;
            if ($d->status === 'batal') {
                if ($k) $g['do_batal'][$k] = true;
            } else {
                if ($k) $g['do'][$k] = true;
                if ($d->no_mobil) $g['dt'][NomorMobil::rapikan($d->no_mobil) ?: $d->no_mobil] = true;
                $g['rp'] += (int) $d->nominal - (int) $d->saldo;
            }
            unset($g);
        }
        $rute = collect($kelompok)->map(fn ($g) => [
            'galian' => $g['galian'], 'tujuan' => $g['tujuan'], 'rit' => count($g['do']), 'dt' => count($g['dt']),
            // DO yang seluruh barisnya dibatalkan
            'batal' => count(array_diff_key($g['do_batal'], $g['do'])),
            'uj' => $g['uj'], 'ut' => $g['ut'], 'lain' => $g['lain'], 'rp' => $g['rp'],
        ])->sortByDesc('rit')->values()->all();

        return ['rute' => $rute, 'rit' => array_sum(array_column($rute, 'rit')), 'batal' => array_sum(array_column($rute, 'batal')),
            'dt' => $detail->where('status', '!=', 'batal')->pluck('no_mobil')->filter()->map(fn ($m) => NomorMobil::rapikan($m) ?: $m)->unique()->count()];
    }

    /** "23 dibayar · 10 menunggu" untuk hitungan status baris. */
    public static function teksStatus(array $n): string
    {
        $label = ['terealisasi' => 'dibayar', 'dialihkan' => 'dialihkan', 'menunggu' => 'menunggu', 'batal' => 'batal'];

        return collect($label)->filter(fn ($l, $s) => ! empty($n[$s]))->map(fn ($l, $s) => $n[$s].' '.$l)->implode(' · ') ?: '–';
    }
}
