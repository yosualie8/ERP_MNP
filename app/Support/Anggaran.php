<?php

namespace App\Support;

use App\Models\KomponenAnggaran;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use Illuminate\Support\Collection;

/**
 * Anggaran (rencana) HPP & omset per tahap. Nilai baris: volume × harga, atau (komisi/overhead) persen × total omset.
 * Progres = realisasi HPP (biaya langsung + biaya umum teralokasi) ÷ HPP rencana (owner 15 Okt 2026 — bukan dari kubikasi/tagihan).
 */
class Anggaran
{
    /** Sumber realisasi tiap komponen (dipakai membandingkan rencana vs aktual). */
    public const SUMBER = [
        'omset' => ['kubikasi' => 'Kubikasi Ritasi (m³)', 'rit' => 'Jumlah rit Ritasi', 'manual' => 'Manual'],
        'hpp' => [
            'ritasi_m3' => 'Ritasi (kubikasi m³ & Harga Jual)', 'rit' => 'Ritasi (Harga Jual per rit)', 'ngepok' => 'Ngepok (Ritasi)', 'solar' => 'Solar (Data BBM)', 'rental' => 'Jam HM (Data BBM)',
            'kas_gaji' => 'Kas Harian — Gaji & Tunjangan', 'kas_operasional' => 'Kas Harian — biaya operasional', 'kas_hpp' => 'Kas Harian — akun HPP',
            'komisi' => 'Komisi (% omset)', 'overhead' => 'Overhead (bagian biaya umum)', 'manual' => 'Manual',
        ],
    ];

    public const CARA = ['volume' => 'Volume × harga satuan', 'persen' => '% dari total omset'];

    /** Komponen "tunda realisasi" (mis. komisi lapangan) dianggap terealisasi bila tanggal bayarnya di tahap sudah diisi. */
    public const TANGGAL_REALISASI = ['komisi_lapangan' => 'bayar_lapangan', 'komisi_yosua' => 'bayar_yosua', 'komisi_verlix' => 'bayar_verlix'];

    public static function ditunda($komponen, ?ProyekTahap $t): bool
    {
        return (bool) $komponen->tunda_realisasi && ! (($f = self::TANGGAL_REALISASI[$komponen->kode] ?? null) && $t?->{$f});
    }

    /**
     * Nilai tiap baris + total. $baris: iterable berisi komponen (KomponenAnggaran), volume, harga, persen.
     * Baris komponen "tunda realisasi" yang belum direalisasikan tetap dihitung nilainya tetapi TIDAK masuk total HPP.
     *
     * @return array{omset: float, hpp: float, margin: float, persen_margin: ?float, nilai: array<int, float>, tunda: array<int, true>}
     */
    public static function hitung(iterable $baris, ?ProyekTahap $t = null): array
    {
        $tunda = [];
        $baris = collect($baris)->values();
        $nilai = [];
        $omset = 0.0;
        foreach ($baris as $i => $b) {
            if ($b->komponen->jenis === 'omset') {
                $nilai[$i] = round(($b->volume ?? 0) * ($b->harga ?? 0), 2);
                $omset += $nilai[$i];
            }
        }
        $hpp = 0.0;
        foreach ($baris as $i => $b) {
            if ($b->komponen->jenis === 'hpp') {
                $nilai[$i] = round($b->komponen->cara === 'persen' ? ($b->persen ?? 0) / 100 * $omset : ($b->volume ?? 0) * ($b->harga ?? 0), 2);
                if (self::ditunda($b->komponen, $t)) {
                    $tunda[$i] = true;
                    continue;
                }
                $hpp += $nilai[$i];
            }
        }
        ksort($nilai);

        return ['omset' => $omset, 'hpp' => $hpp, 'margin' => $omset - $hpp, 'persen_margin' => $omset > 0 ? ($omset - $hpp) / $omset * 100 : null, 'nilai' => $nilai, 'tunda' => $tunda];
    }


    /** Rata-rata kubikasi per rit urug 6 bulan terakhir (untuk memperkirakan jumlah rit dari volume m³). */
    public static function rataKubikasi(): float
    {
        return (float) (Ritasi::where('tanggal', '>=', now()->subMonths(6))->whereRaw("LOWER(COALESCE(jenis_buangan, '')) <> 'ngepok'")
            ->where('kubikasi', '>', 0)->avg('kubikasi') ?: 20);
    }

    private static ?array $kasDihitung = null;

    /** Kas per tahap yang DIHITUNG sebagai realisasi biaya langsung (tanpa akun yang diabaikan — lihat Overhead::diabaikan). */
    public static function kasDihitung(): array
    {
        if (self::$kasDihitung !== null) {
            return self::$kasDihitung;
        }
        $hasil = [];
        foreach (DaftarProyek::queryKas()->selectRaw('c.kode cc, k.ref, a.nama akun, a.kelompok kel, b.tanggal, al.proyek_tahap_id alokasi, sum(b.nominal) s')
            ->groupBy('c.kode', 'k.ref', 'a.nama', 'a.kelompok', 'b.tanggal', 'al.proyek_tahap_id')->get() as $r) {
            if (Overhead::diabaikan($r->kel, $r->akun)) {
                continue;
            }
            if ($id = $r->alokasi ?: DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10))) {
                $hasil[$id] = ($hasil[$id] ?? 0) + (float) $r->s;
            }
        }

        return self::$kasDihitung = $hasil;
    }

    /**
     * Rincian realisasi biaya langsung untuk anggaran: sama dengan grid Daftar Proyek, tetapi Kas Harian tanpa akun yang
     * diabaikan (HPP ritasi, BBM, sparepart & keperluan DT — sudah tertutup Harga Jual ritasi, Data BBM & tarif rental).
     */
    public static function rincianRealisasi(ProyekTahap $t, array $ritasi, array $kas, array $bbm): array
    {
        $c = DaftarProyek::rincian($t, $ritasi, $kas, $bbm);
        $c['kas'][1] = (int) round(self::kasDihitung()[$t->id] ?? 0);

        return $c;
    }

    /** Volume rencana tanah (m³) = jumlah volume baris HPP bersumber Ritasi m³ — dasar progres fisik proyek cut & fill. */
    public static function volumeTanah(iterable $baris): float
    {
        return (float) collect($baris)->filter(fn ($b) => $b->komponen->jenis === 'hpp' && $b->komponen->sumber === 'ritasi_m3')->sum('volume');
    }

    /**
     * Rekomendasi komponen dari tahap sejenis (proyek induk & tipe sama, selesai dalam 12 bulan terakhir, ≥ 300 m³):
     * MEDIAN rasio per m³ kubikasi bongkaran — solar (liter), HM (jam), ngepok (rit), gaji & operasional Kas (Rp) —
     * plus biaya umum per m³ dari pos biaya umum yang mencakup tahap ini (12 bulan ÷ kubikasi yang ditanggungnya).
     *
     * @return array{n: int, sampel: array<int, string>, rasio: array<string, float>, harga_ngepok: ?float, overhead_m3: ?float}|null
     */
    public static function rekomendasi(ProyekTahap $t): ?array
    {
        $ritasi = DaftarProyek::ritasi();
        $bbm = DaftarProyek::bbm();
        $batas = now()->subMonths(12)->toDateString();
        $sampel = ProyekTahap::where('proyek_id', $t->proyek_id)->where('tipe', $t->tipe)->where('status', 'selesai')->whereKeyNot($t->id)->whereNull('pos_overhead')->get()
            ->filter(fn ($s) => ($ritasi['per_tahap'][$s->id]['m3'] ?? 0) >= 300 && ($ritasi['per_tahap'][$s->id]['selesai'] ?? '') >= $batas)->values();
        if ($sampel->count() < 3) {
            return null;
        }
        $kas = self::kasKelompok($sampel->pluck('id')->all());
        $per = $sampel->map(function ($s) use ($ritasi, $bbm, $kas) {
            $r = $ritasi['per_tahap'][$s->id];
            $b = $bbm['per_tahap'][$s->id] ?? [];

            return ['liter' => ($b['liter'] ?? 0) / $r['m3'], 'hm' => ($b['hm'] ?? 0) / $r['m3'], 'ngepok' => ($r['ngepok'] ?? 0) / $r['m3'],
                'gaji' => ($kas[$s->id]['gaji'] ?? 0) / $r['m3'], 'ops' => ($kas[$s->id]['ops'] ?? 0) / $r['m3']];
        });
        $median = function (Collection $v) {
            $v = $v->sort()->values();
            $n = $v->count();

            return $n ? ($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2) : 0.0;
        };
        $ngepok = collect($ritasi['per_tahap'])->filter(fn ($r) => ($r['ngepok'] ?? 0) > 0);

        return [
            'n' => $sampel->count(), 'sampel' => $sampel->pluck('nama')->all(),
            'rasio' => collect(['liter', 'hm', 'ngepok', 'gaji', 'ops'])->mapWithKeys(fn ($k) => [$k => round($median($per->pluck($k)), 4)])->all(),
            'harga_ngepok' => $ngepok->sum('ngepok') ? round($ngepok->sum('rp_ngepok') / $ngepok->sum('ngepok'), -2) : null,
            'overhead_m3' => self::overheadPerM3($t),
        ];
    }

    /** Kas per tahap per kelompok untuk rasio rekomendasi: gaji (Gaji & Tunjangan) & ops — tanpa akun yang diabaikan & tanpa komisi. */
    public static function kasKelompok(array $ids): array
    {
        $hasil = [];
        foreach (DaftarProyek::queryKas()->selectRaw('c.kode cc, k.ref, a.nama akun, a.kelompok kel, b.tanggal, al.proyek_tahap_id alokasi, sum(b.nominal) s')
            ->groupBy('c.kode', 'k.ref', 'a.nama', 'a.kelompok', 'b.tanggal', 'al.proyek_tahap_id')->get() as $r) {
            $id = $r->alokasi ?: DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10));
            // Komisi tidak masuk rasio gaji/ops karena punya komponen % sendiri di anggaran.
            if (! in_array($id, $ids, true) || Overhead::diabaikan($r->kel, $r->akun) || preg_match('/omis/i', (string) $r->akun)) {
                continue;
            }
            $g = $r->kel === 'Gaji & Tunjangan' ? 'gaji' : 'ops';
            $hasil[$id][$g] = ($hasil[$id][$g] ?? 0) + (float) $r->s;
        }

        return $hasil;
    }

    /** Biaya umum per m³ untuk tahap ini: Σ biaya 12 bulan tiap pos yang mencakupnya ÷ kubikasi bongkaran yang ditanggung pos itu. */
    public static function overheadPerM3(ProyekTahap $t): ?float
    {
        $dari = now()->subMonths(12)->format('Y-m');
        $kas = Overhead::data()['kas'];
        $m3 = [];
        foreach (Ritasi::where('tanggal', '>=', $dari.'-01')->whereRaw("LOWER(COALESCE(jenis_buangan, '')) <> 'ngepok'")->selectRaw('tahap, sum(kubikasi) k')->groupBy('tahap')->get() as $r) {
            if ($id = DaftarProyek::tahapId('ritasi', $r->tahap)) {
                $m3[$id] = ($m3[$id] ?? 0) + (float) $r->k;
            }
        }
        $proyekOf = ProyekTahap::whereIn('id', array_keys($m3))->pluck('proyek_id', 'id');
        $total = 0.0;
        foreach (ProyekTahap::whereNotNull('pos_overhead')->get() as $pos) {
            if ($pos->pos_overhead === 'induk' && $pos->proyek_id !== $t->proyek_id) {
                continue;
            }
            $biaya = array_sum(array_filter($kas[$pos->id] ?? [], fn ($v, $ym) => $ym >= $dari, ARRAY_FILTER_USE_BOTH));
            $dasar = collect($m3)->filter(fn ($v, $id) => $pos->pos_overhead === 'semua' || ($proyekOf[$id] ?? null) === $pos->proyek_id)->sum();
            if ($biaya && $dasar) {
                $total += $biaya / $dasar;
            }
        }

        return $total ? round($total, -1) : null;
    }

    /** Perkiraan harga tanah & angkutan per m³ = Σ Harga Jual ÷ Σ kubikasi ritasi urug 6 bulan terakhir (dibulatkan ke ratusan). */
    public static function hargaTanahM3(): ?float
    {
        $r = Ritasi::where('tanggal', '>=', now()->subMonths(6))->whereRaw("LOWER(COALESCE(jenis_buangan, '')) <> 'ngepok'")
            ->where('kubikasi', '>', 0)->where('harga_jual', '>', 0)->selectRaw('sum(harga_jual) h, sum(kubikasi) k')->first();

        return $r && $r->k > 0 ? round($r->h / $r->k, -2) : null;
    }

    /**
     * Baris awal dari template tipe tahap (komponen aktif yang tipe bawaannya memuat tipe ini), diisi dari data tahap
     * (volume, harga/m³, tarif BBM & rental, komisi). Belum disimpan — ditinjau dulu di form.
     *
     * @return Collection<int, object>
     */
    public static function template(ProyekTahap $t): Collection
    {
        $rata = self::rataKubikasi();
        $m3 = $t->volume ?: ($t->omset && $t->harga_m3 ? $t->omset / $t->harga_m3 : null);
        $rit = $m3 ? (int) ceil($m3 / $rata) : null;

        return KomponenAnggaran::where('aktif', true)->orderBy('jenis', 'desc')->orderBy('urutan')->get()
            ->filter(fn ($k) => in_array($t->tipe, $k->tipe_bawaan ?? [], true))
            ->values()
            ->map(function ($k, $i) use ($t, $m3, $rit) {
                [$volume, $harga, $persen] = match ($k->kode) {
                    'urugan_m3' => [$m3, $t->harga_m3 ?? $k->nilai_bawaan, null],
                    'tanah_rit' => [$rit, $k->nilai_bawaan, null],
                    // luas & volume: tanah bersatuan m³ = volume urugan (owner 15 Okt 2026)
                    'tanah_m3' => [$m3, $k->nilai_bawaan ?? self::hargaTanahM3(), null],
                    'solar' => [null, $t->tarif_bbm ?? $k->nilai_bawaan, null],
                    'rental' => [null, $t->tarif_rental ?? $k->nilai_bawaan, null],
                    'komisi_lapangan', 'komisi_yosua', 'komisi_verlix' => [null, null, $t->{$k->kode} ?? $k->nilai_bawaan],
                    default => $k->cara === 'persen' ? [null, null, $k->nilai_bawaan] : [null, $k->nilai_bawaan, null],
                };

                return (object) ['komponen_id' => $k->id, 'komponen' => $k, 'uraian' => null, 'volume' => $volume, 'harga' => $harga, 'persen' => $persen, 'urutan' => $i];
            });
    }
}
