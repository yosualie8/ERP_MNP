<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjTransaksi;

/**
 * Bayar Tanah → Input UJ: DO yang dipilih di menu Bayar Tanah dijadikan isian form Input UJ (satu transfer, satu detail per DO),
 * mengikuti cara admin selama ini: keterangan "Uang tanah {galian}", kategori Uang Tanah, nominal = tarif terakhir galian itu,
 * rekening/bank penerima = pembayaran uang tanah terakhir ke galian itu. Admin tetap memeriksa lalu menyimpan.
 */
class BayarTanah
{
    private const HARI_RIWAYAT = 120;

    /**
     * @param  string[]  $daftarDo
     * @return array{detail: array<int, array>, master: array{nama: ?string, bank: ?string, rekening: ?string}, galian: string[], catatan: string[]}
     */
    public static function untukForm(array $daftarDo): array
    {
        $belum = MonitorRitasi::belumBayarTanah()['do']->keyBy('do');
        $riwayat = self::riwayat();
        $detail = [];
        $catatan = [];
        $galianDipakai = [];
        $kunciAda = array_keys($riwayat);
        foreach (array_unique(array_map('strval', $daftarDo)) as $do) {
            $d = $belum[$do] ?? null;
            if (! $d) {
                $catatan[] = "DO {$do} sudah punya transaksi uang tanah (atau tidak ada di Kas UJ) — dilewati.";

                continue;
            }
            $tempat = self::tempatDo($d, $kunciAda);
            $r = $tempat ? ($riwayat[$tempat] ?? null) : null;
            $uj = collect($d['detail'])->first(fn ($x) => ValidasiUj::jenisKategori($x->kategori) === 'uj') ?? collect($d['detail'])->first();
            $detail[] = [
                'nama' => $uj?->nama, 'keterangan' => $r['keterangan'] ?? ('Uang tanah '.($tempat ? ucwords($tempat) : '')),
                'nominal' => $r['nominal'] ?? '', 'kategori' => 'Uang Tanah',
                'jenis_kendaraan' => $uj?->jenis_kendaraan, 'no_mobil' => $uj?->no_mobil, 'no_do' => $do,
            ];
            if (! $r) {
                $catatan[] = "DO {$do}: ".($tempat ? "belum ada riwayat uang tanah galian \"{$tempat}\"" : 'galian tidak diketahui').' — isi keterangan & nominalnya sendiri.';
            }
            $galianDipakai[$tempat ?? '?'] = $r;
        }
        $galian = array_keys($galianDipakai);
        if (count($galian) > 1) {
            $catatan[] = 'DO yang dipilih berasal dari '.count($galian).' galian ('.implode(', ', array_map(fn ($g) => $g === '?' ? 'tidak diketahui' : ucwords($g), $galian))
                .'). Biasanya tiap galian ditransfer ke rekening berbeda — pertimbangkan membayar per galian.';
        }
        $pertama = collect($galianDipakai)->filter()->first();

        return [
            'detail' => $detail,
            'master' => ['nama' => $detail[0]['nama'] ?? null, 'bank' => $pertama['bank'] ?? null, 'rekening' => $pertama['rekening'] ?? null],
            'galian' => $galian,
            'catatan' => $catatan,
        ];
    }

    /** Tempat (galian) DO: dari keterangan UJ-nya, nama tempat yang dikenal di keterangan, atau galian rit-nya. */
    private static function tempatDo(array $d, array $kunciAda): ?string
    {
        if ($d['tujuan'] && in_array($d['tujuan'], $kunciAda, true)) {
            return $d['tujuan'];
        }
        $teks = mb_strtolower(collect($d['detail'])->pluck('keterangan')->implode(' '));
        foreach ($kunciAda as $k) {
            if (strlen($k) >= 3 && str_contains($teks, $k)) {
                return $k;
            }
        }
        static $galianRit;
        $galianRit ??= Ritasi::whereNotNull('galian')->whereNotNull('no_do')->get(['no_do', 'galian'])
            ->mapWithKeys(fn ($x) => [LembarRitasi::kunciAngka($x->no_do) => $x->galian])->all();
        if ($g = $galianRit[LembarRitasi::kunciAngka($d['do'])] ?? null) {
            $g = mb_strtolower(trim($g));
            foreach ($kunciAda as $k) {
                if (str_contains($g, $k) || str_contains($k, $g)) {
                    return $k;
                }
            }
        }

        return $d['tujuan'] ?: null;
    }

    /**
     * Pembayaran uang tanah terakhir per tempat: nominal & keterangan detail terbaru, bank & rekening penerimanya.
     *
     * @return array<string, array{nominal: int, keterangan: string, bank: ?string, rekening: ?string}>
     */
    public static function riwayat(): array
    {
        $hasil = [];
        $baris = UjDetail::with('transaksi')->where('biaya_transfer', false)->where('tanggal', '>=', now()->subDays(self::HARI_RIWAYAT))
            ->orderByDesc('tanggal')->orderByDesc('baris')->get(['uj_transaksi_id', 'tanggal', 'baris', 'keterangan', 'kategori', 'nominal']);
        foreach ($baris as $d) {
            if (ValidasiUj::jenisKategori($d->kategori) !== 'ut' || preg_match('/tambahan|putar arah|alihan/i', (string) $d->keterangan)) {
                continue;
            }
            $tempat = self::tempatUangTanah((string) $d->keterangan);
            if (! $tempat || isset($hasil[$tempat])) {
                continue;
            }
            $hasil[$tempat] = ['nominal' => (int) $d->nominal, 'keterangan' => trim(preg_replace('/\s+/', ' ', $d->keterangan)),
                'bank' => $d->transaksi?->bank, 'rekening' => $d->transaksi?->rekening];
        }

        return $hasil;
    }

    /** "Uang tanah Sidomuncul", "Uang bayar tanah cileles", "Bayar tanah Subang ke PIK 2" → "sidomuncul" / "cileles" / "subang". */
    private static function tempatUangTanah(string $ket): ?string
    {
        $t = TebakGalian::tempatDari($ket);
        if ($t && $t[1] === 2) {
            return $t[0];
        }

        return preg_match('/tanah\s+([a-z ]+?)(?:\s+ke\s|[,.;(]|$)/i', $ket, $m) ? (trim(preg_replace('/\s+/', ' ', strtolower($m[1]))) ?: null) : null;
    }
}
