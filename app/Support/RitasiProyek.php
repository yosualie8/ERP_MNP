<?php

namespace App\Support;

use App\Models\ProyekTahap;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
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
        // belum ada ritasi (proyek baru) → UJ berkata kunci dicari sejak tahap dibuat
        $mulai = Carbon::parse($rit->min('tanggal') ?? $t->created_at ?? now())->subDays(10)->toDateString();
        // Pengalihan UJ (truk pindah proyek sebelum bongkar):
        //  - KELUAR (alih / kembali dari tahap ini): UJ-nya tidak lagi milik tahap ini; transaksi DO itu s/d tanggal pengalihan
        //    (uang makan, sparepart perjalanan lama) tetap milik tahap ini;
        //  - MASUK = saldo sopir (tanpa proyek tujuan, owner 20 Okt 2026): UJ saldo sopir yang DO lamanya ternyata bongkar di tahap ini
        //    jadi biaya tahap ini; pemakaian saldo yang ditanggung tahap ini (SaldoSopir::tahapPakai: Pengajuan UJ ber-DO / berkata
        //    kunci tahap ini, MOB / lain yang dicatat ke tahap ini, tidak kembali dari tahap ini) = biaya; UJ ber-DO dari saldo ikut
        //    dihitung diberi / bongkar / belum. DO lama yang ternyata bongkar di proyek asalnya sendiri = UJ biasa proyek itu.
        //  - BATAL: UJ ditutup tanpa bongkar dengan alasan — tetap biaya, tidak dihitung belum bongkar.
        $bongkarSini = SaldoSopir::perSopir()->flatMap(fn ($s) => $s['uj'])->filter(fn ($x) => $x->tahap_bongkar === $t->id)->keyBy(fn ($x) => $x->p->id_uj);
        $keluar = UjPengalihan::whereIn('jenis', ['alih', 'kembali'])->where('dari_tahap_id', $t->id)->get()->keyBy('id_uj')
            ->reject(fn ($p) => $p->jenis === 'alih' && isset($bongkarSini[$p->id_uj]));
        $alih = $bongkarSini->map(fn ($x) => $x->p)->reject(fn ($p) => $p->dari_tahap_id === $t->id);
        $pakai = UjPengalihan::where('jenis', 'pakai')->orderBy('tanggal')->orderBy('id')->get()->filter(fn ($p) => SaldoSopir::tahapPakai($p) === $t->id)->values();
        $batal = UjPengalihan::where('jenis', 'batal')->whereNotNull('id_uj')->pluck('id_uj')->flip();
        $doKeluar = $doMasuk = [];
        foreach (UjDetail::whereIn('id_uj', $keluar->keys()->merge($alih->keys())->filter()->all() ?: ['-'])->get(['id_uj', 'no_do']) as $u) {
            if (($d = $do($u->no_do)) === '') {
                continue;
            }
            if (isset($keluar[$u->id_uj])) {
                $doKeluar[$d] = max($doKeluar[$d] ?? $keluar[$u->id_uj]->tanggal, $keluar[$u->id_uj]->tanggal);
            }
            if (isset($alih[$u->id_uj])) {
                $doMasuk[$d] = max($doMasuk[$d] ?? $alih[$u->id_uj]->tanggal, $alih[$u->id_uj]->tanggal);
            }
        }
        // Dialihkan SEBAGIAN (mis. Uang MOB PIK→Kendal Rp2,1 jt yang sudah termasuk UJ rit pertama: hanya Rp270rb UJ-nya yang gantung):
        // sisanya tetap biaya tahap ini, dan UJ itu tidak lagi dihitung belum bongkar.
        $sebagian = [];
        $uj = UjDetail::where('tanggal', '>=', $mulai)->orWhereIn('id_uj', $alih->keys()->filter()->all() ?: ['-'])
            ->get(['id_uj', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'no_mobil', 'no_do'])
            ->reject(function ($u) use ($keluar, $alih, &$sebagian) {
                if (isset($alih[$u->id_uj])) {
                    // di tahap tujuan hanya bagian yang dialihkan yang dihitung
                    $u->nominal = min((float) $u->nominal, $alih[$u->id_uj]->nominal);

                    return false;
                }
                if (! isset($keluar[$u->id_uj])) {
                    return false;
                }
                if ($keluar[$u->id_uj]->nominal + 0.5 < (float) $u->nominal) {
                    $u->nominal = (float) $u->nominal - $keluar[$u->id_uj]->nominal;
                    $sebagian[$u->id_uj] = true;

                    return false;
                }

                return true;
            });
        $jalan = $uj->filter(fn ($u) => in_array($u->kategori, ['Uang Jalan', 'Uang MOB'], true)
            && (isset($alih[$u->id_uj])
                // saldo sopir dari proyek lain: hanya bila DO lamanya bongkar di tahap ini
                ? isset($ritDo[$do($u->no_do)])
                // Uang MOB tanpa DO di Ritasi hanya dihitung bila MENUJU proyek ini ("PIK ke Kendal" / "PIK Kendal"); "Kendal PIK" = pindah
                // keluar dari proyek, tidak akan pernah bongkar (owner 17 Okt 2026).
                : (isset($ritDo[$do($u->no_do)]) || ($kata && preg_match("/\\b({$kata})\\b/i", (string) $u->keterangan)
                    && ($u->kategori !== 'Uang MOB' || preg_match("/\\b(ke|menuju)\\s+({$kata})\\b|\\b({$kata})\\W*$/i", trim((string) $u->keterangan)))))));
        $jalanDo = $jalan->map(fn ($u) => $do($u->no_do))->filter()->flip();
        $milik = function ($u) use ($do, $ritDo, $jalanDo, $doLain, $doKeluar, $doMasuk) {
            if (($d = $do($u->no_do)) === '') {
                return false;
            }
            if (isset($doMasuk[$d]) && $u->tanggal && $u->tanggal->lte($doMasuk[$d])) {
                return false; // perjalanan lama di proyek asal
            }
            if (isset($doKeluar[$d]) && $u->tanggal && $u->tanggal->lte($doKeluar[$d])) {
                return true;  // perjalanan lama milik tahap ini walaupun UJ-nya dialihkan
            }

            return isset($ritDo[$d]) || (isset($jalanDo[$d]) && ! isset($doLain[$d]));
        };
        $tanah = $uj->filter(fn ($u) => $u->kategori === 'Uang Tanah' && $milik($u));
        $lain = $uj->filter(fn ($u) => ! in_array($u->kategori, ['Uang Jalan', 'Uang MOB', 'Uang Tanah'], true) && $milik($u));
        // UJ ber-DO baru yang dibayar dari saldo sopir (Pengajuan UJ → Pakai saldo): ikut dihitung diberi / bongkar / belum
        $dariSaldo = $pakai->filter(fn ($p) => $p->no_do && ! isset($jalanDo[$do($p->no_do)]))->map(fn ($p) => (new UjDetail)->forceFill(['id_uj' => 'SALDO-'.$p->id, 'tanggal' => $p->tanggal, 'nama' => $p->nama,
            'keterangan' => 'Dibayar dari saldo sopir'.($p->keterangan ? ' — '.$p->keterangan : ''), 'nominal' => $p->nominal, 'kategori' => 'Uang Jalan', 'no_mobil' => $p->no_mobil, 'no_do' => $p->no_do]));
        $semuaJalan = $jalan->concat($dariSaldo);
        $bongkar = $semuaJalan->filter(fn ($u) => isset($ritDo[$do($u->no_do)]));
        $belum = $semuaJalan->reject(fn ($u) => $u->kategori === 'Uang MOB' || isset($ritDo[$do($u->no_do)]) || isset($sebagian[$u->id_uj]) || isset($batal[$u->id_uj]))->sortBy('tanggal')->values();
        $terpakaiDo = $alih->filter(fn ($a) => $jalan->contains('id_uj', $a->id_uj));

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
            'uj' => ['Uang Jalan + Uang MOB (Kas UJ'.($terpakaiDo->isNotEmpty() ? ', termasuk '.$terpakaiDo->count().' UJ saldo sopir yang DO lamanya bongkar di sini' : '').')', $jalan->count(), (float) $jalan->sum('nominal'), 'rit diberi UJ'],
            'tanah' => ['Uang Tanah (Kas UJ)', $tanah->count(), (float) $tanah->sum('nominal'), 'DO'],
            'uj_lain' => ['Kas UJ lain (sparepart, makan, dll.)', $lain->count(), (float) $lain->sum('nominal'), 'transaksi'],
            'uj_alih' => ['Saldo sopir dipakai (Uang MOB / UJ DO baru / lain)', $pakai->count(), (float) $pakai->sum('nominal'), 'transaksi'],
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
            'uj_diberi' => $semuaJalan->count(), 'uj_bongkar' => $bongkar->count(), 'uj_belum' => $belum->count(), 'uj_sebagian' => count($sebagian),
            'uj_batal' => $semuaJalan->filter(fn ($u) => isset($batal[$u->id_uj]) && ! isset($ritDo[$do($u->no_do)]))->count(),
            'rit_tanpa_uj' => $rit->filter(fn ($r) => ! isset($jalanDo[$do($r->no_do)]))->count(),
            'belum' => $belum,
            'alih_keluar' => ['n' => $keluar->count(), 'rp' => (float) $keluar->sum('nominal'), 'kembali' => (float) $keluar->where('jenis', 'kembali')->sum('nominal')],
            'alih_masuk' => ['n' => $alih->count(), 'rp' => (float) $alih->sum('nominal'), 'terpakai_do' => (float) $terpakaiDo->sum('nominal'), 'n_terpakai_do' => $terpakaiDo->count(),
                'pakai' => (float) $pakai->sum('nominal'),
                'saldo' => 0.0, // saldo sopir tidak lagi milik proyek mana pun (lihat menu Pengalihan UJ)
                'mobil' => $alih->pluck('no_mobil')->unique()->count()],
            'hari' => $hari, 'mulai' => $rit->min('tanggal')?->toDateString(), 'selesai' => $rit->max('tanggal')?->toDateString(),
        ];
    }
}
