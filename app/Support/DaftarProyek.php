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
    public const KUNCI_PENGATURAN = 'daftar_proyek_filter';

    public const PERIODE = ['tahun_ini' => 'Tahun berjalan', 'dua_tahun' => 'Tahun berjalan & tahun lalu', 'semua' => 'Semua tahun'];

    /**
     * Filter bawaan grid Daftar Proyek (menu Pengaturan Daftar Proyek). cost_center kosong = semua.
     *
     * @return array{periode: string, status: string[], tipe: string[], cost_center: string[]}
     */
    public static function pengaturan(): array
    {
        $simpan = json_decode((string) \App\Models\PengaturanApp::ambil(self::KUNCI_PENGATURAN), true) ?: [];

        return [
            'periode' => array_key_exists($simpan['periode'] ?? '', self::PERIODE) ? $simpan['periode'] : 'tahun_ini',
            'status' => $simpan['status'] ?? array_keys(\App\Models\ProyekTahap::STATUS),
            'tipe' => $simpan['tipe'] ?? array_keys(\App\Models\ProyekTahap::TIPE),
            'cost_center' => $simpan['cost_center'] ?? [],
        ];
    }

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
        foreach (Ritasi::selectRaw('tahap, jenis_buangan, count(*) n, sum(harga_jual) h, sum(kubikasi) k, min(tanggal) a, max(tanggal) b')->groupBy('tahap', 'jenis_buangan')->get() as $r) {
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
            // Volume fisik (m³) = kubikasi ritasi urug (ngepok tidak menambah volume) — dasar progres fisik anggaran.
            if (! $ngepok) {
                $tujuan['m3'] = ($tujuan['m3'] ?? 0) + (float) $r->k;
            }
            $tujuan['mulai'] = min($tujuan['mulai'] ?? '9999', substr((string) $r->a, 0, 10));
            $tujuan['selesai'] = max($tujuan['selesai'] ?? '0000', substr((string) $r->b, 0, 10));
            unset($tujuan);
        }

        return ['per_tahap' => $per, 'lepas' => collect($lepas)->sortByDesc('selesai')->values()->all()];
    }

    /**
     * Rincian biaya aktual satu tahap (sama dengan grid Daftar Proyek): [kunci => [jumlah, rupiah]] untuk ritasi & ngepok
     * (Harga Jual Ritasi), solar (liter × tarif BBM), rental (jam HM × tarif rental), operasional Kas Harian.
     *
     * @return array<string, array{0: float|int, 1: int}>
     */
    public static function rincian(\App\Models\ProyekTahap $t, array $ritasi, array $kas, array $bbm): array
    {
        $r = $ritasi['per_tahap'][$t->id] ?? [];
        $k = $kas['per_tahap'][$t->id] ?? [];
        $b = $bbm['per_tahap'][$t->id] ?? [];

        return [
            'rit' => [$r['rit'] ?? 0, $r['rp_rit'] ?? 0],
            'ngepok' => [$r['ngepok'] ?? 0, $r['rp_ngepok'] ?? 0],
            'solar' => [$b['liter'] ?? 0, (int) round(($b['liter'] ?? 0) * ($t->tarif_bbm ?? \App\Models\ProyekTahap::BAWAAN['tarif_bbm']))],
            'rental' => [$b['hm'] ?? 0, (int) round(($b['hm'] ?? 0) * ($t->tarif_rental ?? \App\Models\ProyekTahap::BAWAAN['tarif_rental']))],
            'kas' => [$k['n'] ?? 0, $k['rp'] ?? 0],
        ];
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
            ->selectRaw('c.kode cc, k.ref, a.nama akun, b.tanggal, al.proyek_tahap_id alokasi, count(*) n, sum(b.nominal) s')->groupBy('c.kode', 'k.ref', 'a.nama', 'b.tanggal', 'al.proyek_tahap_id')->get();
        foreach ($baris as $r) {
            $id = $r->alokasi ?: self::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10));
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
     * di lembar lama), akun Non-biaya (talangan, salah transfer) dan Piutang (cashbon karyawan) tidak ikut, begitu pula
     * yang ditandai "abaikan" di kas_alokasi. Kolom alokasi = tahap dari kas_alokasi (mengalahkan Kode GL).
     */
    public static function queryKas(): \Illuminate\Database\Query\Builder
    {
        return DB::table('kas_bon as b')->join('kode_gl as k', 'k.id', '=', 'b.kode_gl_id')->leftJoin('cost_center as c', 'c.id', '=', 'k.cost_center_id')
            ->leftJoin('akun_gl as a', 'a.id', '=', 'k.akun_gl_id')
            ->join('kas_transfer as t', 't.id', '=', 'b.kas_transfer_id')->join('kas_bulan as m', 'm.id', '=', 't.kas_bulan_id')
            ->leftJoin('kas_alokasi as al', 'al.kunci', '=', DB::raw(self::SQL_KUNCI))
            ->where(fn ($q) => $q->whereNull('a.kelompok')->orWhereNotIn('a.kelompok', ['Non-biaya', 'Piutang']))
            ->where(fn ($q) => $q->whereNull('al.abaikan')->orWhere('al.abaikan', false));
    }

    /** Kunci tetap transaksi detail untuk kas_alokasi: ID transaksi, atau "lembar:baris" bila ID kosong/#N/A/rusak (lembar lama). */
    public const SQL_KUNCI = "CASE WHEN b.id_transaksi REGEXP '^[0-9]{6}-.*[^-]$' THEN b.id_transaksi ELSE CONCAT(m.lembar, ':', b.baris) END";

    /**
     * Ringkasan Penggunaan BBM per tahap (dari tabel bbm = cermin lembar Penggunaan BBM, diimpor tiap 5 menit):
     * solar keluar (liter) & jam HM (0–24 jam per baris; salah ketik tidak ikut). Disimpan 30 menit (dibuang tiap impor).
     *
     * @return array{per_tahap: array<int, array{liter: float, hm: float}>, lepas: array<int, array>, waktu: string}
     */
    public static function bbm(bool $segarkan = false): array
    {
        if ($segarkan && ($sheets = GoogleSheets::terhubung())) {
            LembarBbm::impor($sheets);
        }

        return \Illuminate\Support\Facades\Cache::remember('daftar-proyek-bbm', now()->addMinutes(30), function () {
            $per = [];
            $lepas = [];
            foreach (\App\Models\Bbm::whereNotNull('proyek')->get(['tanggal', 'proyek', 'tahap_ids', 'keluar', 'total_hm']) as $x) {
                $p = $x->proyek;
                $liter = (float) $x->keluar;
                $hm = $x->total_hm !== null && $x->total_hm >= 0 && $x->total_hm <= 24 ? (float) $x->total_hm : 0;
                if (! $liter && ! $hm) {
                    continue;
                }
                $tgl = $x->tanggal?->toDateString();
                // "Tahap A + Tahap B" = dibagi rata ke beberapa tahap yang sedang berjalan (proyek diisi otomatis);
                // baris bagi rata tidak menggeser tanggal mulai/akhir tahap.
                $ids = $x->tahap_ids ?? [null];
                if (! in_array(null, $ids, true)) {
                    foreach ($ids as $id) {
                        $per[$id]['liter'] = ($per[$id]['liter'] ?? 0) + $liter / count($ids);
                        $per[$id]['hm'] = ($per[$id]['hm'] ?? 0) + $hm / count($ids);
                        if ($tgl && count($ids) === 1) {
                            $per[$id]['mulai'] = min($per[$id]['mulai'] ?? '9999', $tgl);
                            $per[$id]['selesai'] = max($per[$id]['selesai'] ?? '0000', $tgl);
                        }
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
