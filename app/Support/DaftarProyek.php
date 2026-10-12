<?php

namespace App\Support;

use App\Models\ProyekAlias;
use App\Models\Ritasi;
use Illuminate\Support\Facades\DB;

/**
 * Pemetaan data operasional ke tahap Daftar Proyek lewat alias (nama lama/variasi tulisan per sumber), plus ringkasan
 * per tahap (ritasi, Kas Harian) dan daftar nama yang belum terpetakan.
 */
class DaftarProyek
{
    /** @var array<string, array<string, int>> sumber → kunci → id tahap */
    private static array $peta = [];

    public static function tahapId(string $sumber, ?string $nama): ?int
    {
        self::$peta[$sumber] ??= ProyekAlias::where('sumber', $sumber)->pluck('proyek_tahap_id', 'kunci')->all();

        return self::$peta[$sumber][ProyekAlias::kunci($nama)] ?? null;
    }

    /**
     * Tahap untuk satu transaksi detail Kas Harian: kode tahap (cost center + T-nomor), lalu cost center saja.
     * Aturan owner: Biaya BBM PM dibagi menurut tanggal (Tahap 1 < 11 Jul 2026 ≤ Tahap 2 ≤ 5 Agu 2026 < Infra).
     */
    public static function tahapKas(?string $cc, ?string $ref, ?string $akun, string $tanggal): ?int
    {
        if ($cc === 'PM' && $akun === 'Biaya BBM') {
            $nama = $tanggal < '2026-07-11' ? 'Pantai Mutiara 2 - Tahap 1' : ($tanggal <= '2026-08-05' ? 'Pantai Mutiara 2 - Tahap 2' : 'Pantai Mutiara 2 - Infra');

            return self::tahapId('ritasi', $nama);
        }
        if ($cc && $ref && ($id = self::tahapId('kas', $cc.' '.$ref))) {
            return $id;
        }

        return self::tahapId('kas_cc', $cc ?: '-');
    }

    /**
     * Ringkasan Ritasi per tahap + nama tahap Ritasi yang belum terpetakan.
     *
     * @return array{per_tahap: array<int, array>, lepas: array<int, array>}
     */
    public static function ritasi(): array
    {
        $per = [];
        $lepas = [];
        foreach (Ritasi::selectRaw('tahap, jenis_buangan, count(*) n, sum(harga_jual) h, min(tanggal) a, max(tanggal) b')->groupBy('tahap', 'jenis_buangan')->get() as $r) {
            $id = self::tahapId('ritasi', $r->tahap);
            $ngepok = strcasecmp((string) $r->jenis_buangan, 'Ngepok') === 0;
            if ($id) {
                $tujuan = &$per[$id];
            } else {
                $tujuan = &$lepas[ProyekAlias::kunci($r->tahap)];
                $tujuan['nama'] = trim(preg_replace('/\s+/', ' ', (string) $r->tahap)) ?: '(kosong)';
            }
            $tujuan[$ngepok ? 'ngepok' : 'rit'] = ($tujuan[$ngepok ? 'ngepok' : 'rit'] ?? 0) + $r->n;
            // Biaya ritasi/ngepok = "Harga Jual" per rit di lembar Ritasi (sama dengan Cost Project).
            $tujuan[$ngepok ? 'rp_ngepok' : 'rp_rit'] = ($tujuan[$ngepok ? 'rp_ngepok' : 'rp_rit'] ?? 0) + (int) $r->h;
            $tujuan['mulai'] = min($tujuan['mulai'] ?? '9999', substr((string) $r->a, 0, 10));
            $tujuan['selesai'] = max($tujuan['selesai'] ?? '0000', substr((string) $r->b, 0, 10));
            unset($tujuan);
        }

        return ['per_tahap' => $per, 'lepas' => collect($lepas)->sortByDesc('selesai')->values()->all()];
    }

    /**
     * Ringkasan Kas Harian (transaksi detail) per tahap + kode yang belum terpetakan.
     *
     * @return array{per_tahap: array<int, array{n: int, rp: int}>, lepas: array<int, array>}
     */
    public static function kas(): array
    {
        $per = [];
        $lepas = [];
        $baris = self::queryKas()
            ->selectRaw('c.kode cc, k.ref, a.nama akun, b.tanggal, count(*) n, sum(b.nominal) s')->groupBy('c.kode', 'k.ref', 'a.nama', 'b.tanggal')->get();
        foreach ($baris as $r) {
            $id = self::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10));
            if ($id) {
                $per[$id]['n'] = ($per[$id]['n'] ?? 0) + $r->n;
                $per[$id]['rp'] = ($per[$id]['rp'] ?? 0) + (int) $r->s;
                $t = substr((string) $r->tanggal, 0, 10);
                $per[$id]['mulai'] = min($per[$id]['mulai'] ?? '9999', $t);
                $per[$id]['selesai'] = max($per[$id]['selesai'] ?? '0000', $t);
            } else {
                $k = trim(($r->cc ?? '-').' '.$r->ref);
                $lepas[$k]['nama'] = $k;
                $lepas[$k]['n'] = ($lepas[$k]['n'] ?? 0) + $r->n;
                $lepas[$k]['rp'] = ($lepas[$k]['rp'] ?? 0) + (int) $r->s;
            }
        }

        return ['per_tahap' => $per, 'lepas' => array_values($lepas)];
    }

    /**
     * Transaksi detail Kas Harian yang dihitung sebagai biaya proyek: tanpa Kode GL (bayar hutang, pindah kantong, dll.
     * di lembar lama) dan akun Non-biaya (talangan, salah transfer, pindah kantong) tidak ikut.
     */
    public static function queryKas(): \Illuminate\Database\Query\Builder
    {
        return DB::table('kas_bon as b')->join('kode_gl as k', 'k.id', '=', 'b.kode_gl_id')->leftJoin('cost_center as c', 'c.id', '=', 'k.cost_center_id')
            ->leftJoin('akun_gl as a', 'a.id', '=', 'k.akun_gl_id')
            ->where(fn ($q) => $q->whereNull('a.kelompok')->orWhere('a.kelompok', '<>', 'Non-biaya'));
    }

    /**
     * Ringkasan Penggunaan BBM per tahap (sementara dibaca dari lembar sheet, sampai menu BBM ada di aplikasi):
     * solar keluar (liter) & jam HM (0–24 jam per baris; salah ketik tidak ikut). Disimpan 30 menit.
     *
     * @return array{per_tahap: array<int, array{liter: float, hm: float}>, lepas: array<int, array>, waktu: string}
     */
    public static function bbm(bool $segarkan = false): array
    {
        if ($segarkan) {
            \Illuminate\Support\Facades\Cache::forget('daftar-proyek-bbm');
        }

        // Sheet belum terhubung: kosong & tidak disimpan, supaya begitu terhubung langsung terbaca.
        if (! $sheets = GoogleSheets::terhubung()) {
            return ['per_tahap' => [], 'lepas' => [], 'waktu' => ''];
        }

        return \Illuminate\Support\Facades\Cache::remember('daftar-proyek-bbm', now()->addMinutes(30), function () use ($sheets) {
            $per = [];
            $lepas = [];
            $r = "'Penggunaan BBM'!A2:O";
            foreach ($sheets->nilaiMentah(config('mnp.sheet_ritasi'), [$r])[$r] as $x) {
                $p = trim(preg_replace('/\s+/', ' ', (string) ($x[14] ?? '')));
                $liter = is_numeric($x[6] ?? null) ? (float) $x[6] : 0;
                $hm = is_numeric($x[4] ?? null) && $x[4] >= 0 && $x[4] <= 24 ? (float) $x[4] : 0;
                if ($p === '' || (! $liter && ! $hm)) {
                    continue;
                }
                $tgl = is_numeric($x[0] ?? null) ? date('Y-m-d', (int) round(($x[0] - 25569) * 86400)) : null;
                if ($id = self::tahapId('bbm', $p)) {
                    $per[$id]['liter'] = ($per[$id]['liter'] ?? 0) + $liter;
                    $per[$id]['hm'] = ($per[$id]['hm'] ?? 0) + $hm;
                    if ($tgl) {
                        $per[$id]['mulai'] = min($per[$id]['mulai'] ?? '9999', $tgl);
                        $per[$id]['selesai'] = max($per[$id]['selesai'] ?? '0000', $tgl);
                    }
                } else {
                    $k = ProyekAlias::kunci($p);
                    $lepas[$k]['nama'] = $p;
                    $lepas[$k]['n'] = ($lepas[$k]['n'] ?? 0) + 1;
                    $lepas[$k]['liter'] = ($lepas[$k]['liter'] ?? 0) + $liter;
                }
            }

            return ['per_tahap' => $per, 'lepas' => array_values($lepas), 'waktu' => now()->toDateTimeString()];
        });
    }
}
