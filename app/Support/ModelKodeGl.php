<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Model kecil untuk menebak Kode GL dari keterangan transaksi detail (+ PIC), dipakai di browser
 * (public/js/tebak-kode-gl.js) supaya tebakan muncul seketika tanpa menunggu server.
 * Naive Bayes per kata: kode yang sering muncul bersama kata-kata itu di transaksi sebelumnya menang.
 */
class ModelKodeGl
{
    /** Kata yang dipotong dari keterangan: huruf kecil, tanpa tanda baca; angka murni dibuang (jumlah/harga). */
    public static function kata(string $teks): array
    {
        $teks = mb_strtolower($teks);
        $teks = preg_replace('/[^a-z0-9]+/u', ' ', $teks);
        $kata = array_filter(explode(' ', $teks), fn ($k) => strlen($k) >= 2 && ! ctype_digit($k));

        return array_values(array_unique($kata));
    }

    public static function fitur(string $keterangan, ?string $pic): array
    {
        $f = self::kata($keterangan);
        if ($pic = trim(mb_strtolower((string) $pic))) {
            $f[] = 'pic:'.preg_replace('/\s+/', '_', $pic);
        }

        return $f;
    }

    /** Baris latih: [keterangan, pic, kode baku, tanggal] dari transaksi detail yang Kode GL-nya ditulis di sheet. */
    public static function dataLatih(?string $sejak = null, ?string $sebelum = null): array
    {
        return DB::table('kas_bon as b')
            ->join('kode_gl as k', 'k.id', '=', 'b.kode_gl_id')
            ->join('akun_gl as a', 'a.id', '=', 'k.akun_gl_id')
            ->leftJoin('cost_center as c', 'c.id', '=', 'k.cost_center_id')
            ->where('b.kode_gl_ditebak', false)
            ->whereNotNull('b.keterangan')
            ->when($sejak, fn ($q) => $q->where('b.tanggal', '>=', $sejak))
            ->when($sebelum, fn ($q) => $q->where('b.tanggal', '<', $sebelum))
            ->get(['b.keterangan', 'b.pic', 'a.nama as akun', 'c.kode as cc', 'k.ref', 'b.tanggal'])
            ->map(fn ($r) => [$r->keterangan, $r->pic, trim(implode(' ', array_filter([$r->akun, $r->cc, $r->ref]))), $r->tanggal])
            ->all();
    }

    /**
     * @param  array<int, array{0: string, 1: ?string, 2: string}>  $data
     * @return array{kode: string[], prior: int[], total: int[], v: int, kata: array<string, array<int, array{0: int, 1: int}>>}
     */
    public static function latih(array $data, int $minKata = 2, int $maksKodePerKata = 12): array
    {
        $kodeIdx = [];
        $prior = [];
        $total = [];
        $hitung = [];
        foreach ($data as [$ket, $pic, $kode]) {
            $i = $kodeIdx[$kode] ??= count($kodeIdx);
            $prior[$i] = ($prior[$i] ?? 0) + 1;
            foreach (self::fitur($ket, $pic) as $f) {
                $hitung[$f][$i] = ($hitung[$f][$i] ?? 0) + 1;
                $total[$i] = ($total[$i] ?? 0) + 1;
            }
        }
        $kata = [];
        foreach ($hitung as $f => $perKode) {
            if (array_sum($perKode) < $minKata) {
                continue;
            }
            arsort($perKode);
            $kata[$f] = array_map(fn ($k, $n) => [$k, $n], array_keys(array_slice($perKode, 0, $maksKodePerKata, true)), array_slice($perKode, 0, $maksKodePerKata, true));
        }
        $n = count($kodeIdx);

        return [
            'kode' => array_keys($kodeIdx),
            'prior' => array_map(fn ($i) => $prior[$i] ?? 0, range(0, $n - 1)),
            'total' => array_map(fn ($i) => $total[$i] ?? 0, range(0, $n - 1)),
            'v' => count($kata),
            'kata' => $kata,
        ];
    }

    /**
     * Sama persis dengan tebak() di public/js/tebak-kode-gl.js (dipakai untuk mengukur ketepatan).
     *
     * @return array<int, array{0: string, 1: float}> [kode, peluang] urut dari yang paling mungkin
     */
    public static function tebak(array $m, string $keterangan, ?string $pic, int $jumlah = 3): array
    {
        $fitur = array_values(array_filter(self::fitur($keterangan, $pic), fn ($f) => isset($m['kata'][$f])));
        if (! $fitur) {
            return [];
        }
        $a = 0.5;
        $semua = array_sum($m['prior']);
        $skor = [];
        foreach ($m['kode'] as $i => $_) {
            $skor[$i] = log(($m['prior'][$i] + 1) / ($semua + count($m['kode'])));
        }
        foreach ($fitur as $f) {
            $c = [];
            foreach ($m['kata'][$f] as [$k, $n]) {
                $c[$k] = $n;
            }
            foreach ($skor as $i => $s) {
                $skor[$i] += log((($c[$i] ?? 0) + $a) / ($m['total'][$i] + $a * $m['v']));
            }
        }
        $maks = max($skor);
        $p = array_map(fn ($s) => exp($s - $maks), $skor);
        $jml = array_sum($p);
        arsort($p);
        $hasil = [];
        foreach (array_slice($p, 0, $jumlah, true) as $i => $v) {
            $hasil[] = [self::sesuaikanTahap($m['kode'][$i], $keterangan), $v / $jml];
        }

        return $hasil;
    }

    /** Bila keterangan menyebut tahap ("… ASG T117") dan kode tebakan bertahap, pakai tahap dari keterangan. */
    public static function sesuaikanTahap(string $kode, string $keterangan): string
    {
        if (preg_match('/\bT(\d{1,3})\b/i', $keterangan, $m) && preg_match('/ T\d+$/', $kode)) {
            return preg_replace('/ T\d+$/', ' T'.$m[1], $kode);
        }

        return $kode;
    }
}
