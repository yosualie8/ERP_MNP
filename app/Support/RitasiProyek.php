<?php

namespace App\Support;

use App\Models\ProyekTahap;
use App\Models\Ritasi;
use App\Models\UjDetail;
use Illuminate\Support\Carbon;

/**
 * Tahap tipe RITASI (owner 17 Okt 2026): tidak ada anggaran HPP / omset. Dibayar per rit atau per m³ bongkaran × harga;
 * omset & biaya realisasi mengikuti data.
 *  - Omset = m³ bongkaran (Ritasi, tanpa ngepok) × harga/m³, atau rit bongkaran × harga/rit.
 *  - UJ (Kas UJ): Uang Jalan + Uang MOB (MOB sudah termasuk UJ rit pertama, DO tercatat) yang DO-nya ada di Ritasi tahap ini
 *    = sudah bongkar; UJ ber-kata kunci tahap (kata_uj) yang DO-nya belum ada di Ritasi = belum bongkar.
 *  - Biaya = Uang Jalan + Uang Tanah + Kas UJ lain (DO yang sama) + solar & rental (Data BBM & HM) + Kas Harian & BCA
 *    yang dihitung + biaya umum teralokasi. "Harga Jual" ritasi (tarif internal truk proyek cut & fill) TIDAK dihitung.
 */
class RitasiProyek
{
    private static array $cache = [];

    /** Omset realisasi saja (dasar pembagian biaya umum — tanpa memanggil Overhead supaya tidak berputar). */
    public static function omset(ProyekTahap $t): float
    {
        $nama = Ritasi::distinct()->pluck('tahap')->filter(fn ($n) => DaftarProyek::tahapId('ritasi', $n) === $t->id)->values();
        $urug = Ritasi::whereIn('tahap', $nama)->whereRaw("LOWER(COALESCE(jenis_buangan, '')) <> 'ngepok'");

        return $t->dasar_bayar === 'rit' ? $urug->count() * (float) $t->harga_rit : (float) $urug->sum('kubikasi') * (float) $t->harga_m3;
    }

    public static function hitung(ProyekTahap $t, ?array $bbm = null, ?float $overhead = null): array
    {
        if (isset(self::$cache[$t->id])) {
            return self::$cache[$t->id];
        }
        $do = fn ($v) => ltrim(trim((string) $v), '0');
        $nama = Ritasi::distinct()->pluck('tahap')->filter(fn ($n) => DaftarProyek::tahapId('ritasi', $n) === $t->id)->values();
        $rit = Ritasi::whereIn('tahap', $nama)->orderBy('tanggal')->orderBy('baris')->get(['tanggal', 'no_do', 'kubikasi', 'jenis_buangan', 'no_seri']);
        $urug = $rit->reject(fn ($r) => strcasecmp((string) $r->jenis_buangan, 'Ngepok') === 0);
        $ritDo = $rit->map(fn ($r) => $do($r->no_do))->filter()->flip();

        // DO yang juga tercatat di Ritasi tahap lain (nomor DO bertabrakan antar proyek, mis. 15519 KIK & ASG T116): UJ ber-kata kunci tetap
        // dihitung (keterangannya jelas), tetapi uang tanah / Kas UJ lain ber-DO itu TIDAK ditarik supaya biaya proyek lain tidak ikut.
        $doLain = Ritasi::whereNotIn('tahap', $nama)->whereNotNull('no_do')->pluck('no_do')->map($do)->filter()->flip();
        $kata = collect(preg_split('/\s*,\s*/', (string) $t->kata_uj))->filter()->map(fn ($k) => preg_quote($k, '/'))->implode('|');
        $mulai = $rit->min('tanggal') ? Carbon::parse($rit->min('tanggal'))->subDays(10)->toDateString() : null;
        $uj = $mulai ? UjDetail::where('tanggal', '>=', $mulai)->get(['id_uj', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'no_mobil', 'no_do']) : collect();
        $jalan = $uj->filter(fn ($u) => in_array($u->kategori, ['Uang Jalan', 'Uang MOB'], true)
            // Uang MOB tanpa DO di Ritasi hanya dihitung bila MENUJU proyek ini ("PIK ke Kendal" / "PIK Kendal"); "Kendal PIK" = pindah
            // keluar dari proyek, tidak akan pernah bongkar (owner 17 Okt 2026).
            && (isset($ritDo[$do($u->no_do)]) || ($kata && preg_match("/\\b({$kata})\\b/i", (string) $u->keterangan)
                && ($u->kategori !== 'Uang MOB' || preg_match("/\\b(ke|menuju)\\s+({$kata})\\b|\\b({$kata})\\W*$/i", trim((string) $u->keterangan))))));
        $jalanDo = $jalan->map(fn ($u) => $do($u->no_do))->filter()->flip();
        $milik = fn ($u) => ($d = $do($u->no_do)) !== '' && (isset($ritDo[$d]) || (isset($jalanDo[$d]) && ! isset($doLain[$d])));
        $tanah = $uj->filter(fn ($u) => $u->kategori === 'Uang Tanah' && $milik($u));
        $lain = $uj->filter(fn ($u) => ! in_array($u->kategori, ['Uang Jalan', 'Uang MOB', 'Uang Tanah'], true) && $milik($u));
        $bongkar = $jalan->filter(fn ($u) => isset($ritDo[$do($u->no_do)]));

        $m3 = (float) $urug->sum('kubikasi');
        $dasar = $t->dasar_bayar === 'rit' ? 'rit' : 'm3';
        $harga = (float) ($dasar === 'rit' ? $t->harga_rit : $t->harga_m3);
        $omset = $dasar === 'rit' ? $urug->count() * $harga : $m3 * $harga;

        $bbm ??= DaftarProyek::bbm();
        $b = $bbm['per_tahap'][$t->id] ?? [];
        $solar = ($b['liter'] ?? 0) * ($t->tarif_bbm ?? ProyekTahap::BAWAAN['tarif_bbm']);
        $rental = ($b['hm'] ?? 0) * ($t->tarif_rental ?? ProyekTahap::BAWAAN['tarif_rental']);
        $overhead ??= Overhead::teralokasi()[$t->id] ?? 0;
        $kas = Anggaran::kasDihitung()[$t->id] ?? 0;

        $biaya = [
            'uj' => ['Uang Jalan + Uang MOB (Kas UJ)', $jalan->count(), (float) $jalan->sum('nominal'), 'rit diberi UJ'],
            'tanah' => ['Uang Tanah (Kas UJ)', $tanah->count(), (float) $tanah->sum('nominal'), 'DO'],
            'uj_lain' => ['Kas UJ lain (sparepart, makan, dll.)', $lain->count(), (float) $lain->sum('nominal'), 'transaksi'],
            'solar' => ['Solar (Data BBM)', round($b['liter'] ?? 0, 1), $solar, 'liter'],
            'rental' => ['Rental alat (jam HM)', round($b['hm'] ?? 0, 1), $rental, 'jam'],
            'kas' => ['Kas Harian & BCA (dihitung)', null, (float) $kas, null],
            'overhead' => ['Biaya umum teralokasi', null, (float) $overhead, null],
        ];
        $total = array_sum(array_column($biaya, 2));

        // per tanggal: rit & m³ bongkar, omset; UJ diberi & uang tanah
        $hari = [];
        foreach ($urug as $r) {
            $k = $r->tanggal->toDateString();
            $hari[$k]['rit'] = ($hari[$k]['rit'] ?? 0) + 1;
            $hari[$k]['m3'] = ($hari[$k]['m3'] ?? 0) + (float) $r->kubikasi;
        }
        foreach ($jalan as $u) {
            $k = $u->tanggal->toDateString();
            $hari[$k]['uj'] = ($hari[$k]['uj'] ?? 0) + 1;
            $hari[$k]['rp_uj'] = ($hari[$k]['rp_uj'] ?? 0) + $u->nominal;
        }
        foreach ($tanah as $u) {
            $k = $u->tanggal->toDateString();
            $hari[$k]['rp_tanah'] = ($hari[$k]['rp_tanah'] ?? 0) + $u->nominal;
        }
        ksort($hari);
        foreach ($hari as $k => $h) {
            $hari[$k]['omset'] = $dasar === 'rit' ? ($h['rit'] ?? 0) * $harga : ($h['m3'] ?? 0) * $harga;
        }

        return self::$cache[$t->id] = [
            'dasar' => $dasar, 'harga' => $harga, 'rit' => $urug->count(), 'ngepok' => $rit->count() - $urug->count(), 'm3' => $m3,
            'ber_ls' => $rit->filter(fn ($r) => str_starts_with((string) $r->no_seri, 'LS-'))->count(),
            'omset' => $omset, 'biaya' => $biaya, 'total' => $total, 'margin' => $omset - $total, 'persen_margin' => $omset > 0 ? ($omset - $total) / $omset * 100 : null,
            'uj_diberi' => $jalan->count(), 'uj_bongkar' => $bongkar->count(), 'uj_belum' => $jalan->count() - $bongkar->count(),
            'rit_tanpa_uj' => $rit->filter(fn ($r) => ! isset($jalanDo[$do($r->no_do)]))->count(),
            'belum' => $jalan->reject(fn ($u) => isset($ritDo[$do($u->no_do)]))->sortBy('tanggal')->values(),
            'hari' => $hari, 'mulai' => $rit->min('tanggal')?->toDateString(), 'selesai' => $rit->max('tanggal')?->toDateString(),
        ];
    }
}
