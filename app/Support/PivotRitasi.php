<?php

namespace App\Support;

use App\Models\ProyekTahap;
use App\Models\Ritasi;

/**
 * Pivot ritasi satu tahap (owner 19 Nov 2026, menu Anggaran Proyek): baris = pemilik truk, kolom = jenis kendaraan,
 * isi = jumlah rit dan rit × Harga Jual (kolom "Harga Jual" per rit di lembar Ritasi). Ngepok ikut dihitung (juga dibayar per rit).
 */
class PivotRitasi
{
    /**
     * @return array{pemilik: list<string>, jenis: list<string>, n: array<string, array<string, int>>, rp: array<string, array<string, float>>,
     *     n_pemilik: array<string, int>, rp_pemilik: array<string, float>, n_jenis: array<string, int>, rp_jenis: array<string, float>,
     *     n_total: int, rp_total: float, ngepok: int}
     */
    public static function hitung(ProyekTahap $t): array
    {
        $nama = Ritasi::distinct()->pluck('tahap')->filter(fn ($n) => DaftarProyek::tahapId('ritasi', $n) === $t->id)->values();
        $n = $rp = $nPemilik = $rpPemilik = $nJenis = $rpJenis = [];
        $ngepok = 0;
        $peta = ['p' => [], 'j' => []];
        $rows = $nama->isEmpty() ? collect() : Ritasi::whereIn('tahap', $nama)
            ->selectRaw('pemilik, jenis_kendaraan, jenis_buangan, count(*) n, sum(harga_jual) h')
            ->groupBy('pemilik', 'jenis_kendaraan', 'jenis_buangan')->get();
        foreach ($rows as $r) {
            // "Faw" / "FAW " dianggap sama; nama tampil = ejaan pertama yang ditemui
            $p = self::label($r->pemilik, $peta['p']);
            $j = self::label($r->jenis_kendaraan, $peta['j']);
            $n[$p][$j] = ($n[$p][$j] ?? 0) + (int) $r->n;
            $rp[$p][$j] = ($rp[$p][$j] ?? 0) + (float) $r->h;
            $nPemilik[$p] = ($nPemilik[$p] ?? 0) + (int) $r->n;
            $rpPemilik[$p] = ($rpPemilik[$p] ?? 0) + (float) $r->h;
            $nJenis[$j] = ($nJenis[$j] ?? 0) + (int) $r->n;
            $rpJenis[$j] = ($rpJenis[$j] ?? 0) + (float) $r->h;
            if (strcasecmp(trim((string) $r->jenis_buangan), 'Ngepok') === 0) {
                $ngepok += (int) $r->n;
            }
        }
        arsort($nPemilik);
        arsort($nJenis);

        return ['pemilik' => array_keys($nPemilik), 'jenis' => array_keys($nJenis), 'n' => $n, 'rp' => $rp,
            'n_pemilik' => $nPemilik, 'rp_pemilik' => $rpPemilik, 'n_jenis' => $nJenis, 'rp_jenis' => $rpJenis,
            'n_total' => array_sum($nPemilik), 'rp_total' => array_sum($rpPemilik), 'ngepok' => $ngepok];
    }

    private static function label(?string $v, array &$peta): string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));
        if ($v === '') {
            return '(kosong)';
        }

        return $peta[mb_strtolower($v)] ??= $v;
    }
}
