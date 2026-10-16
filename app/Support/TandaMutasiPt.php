<?php

namespace App\Support;

use App\Models\KasReimburse;
use App\Models\MutasiPt;
use App\Models\MutasiPtTanda;
use App\Models\ProyekTahap;
use App\Models\UjReimburse;
use Illuminate\Support\Collection;

/**
 * Status biaya mutasi Rekening PT (BCA) — owner 16 Okt 2026. Alur: pengeluaran lewat Kas Harian Jago lalu di-reimburse
 * dari BCA (reimburse TIDAK dihitung); uang customer masuk ke BCA (tidak dihitung); transaksi besar / berpajak dibayar
 * langsung dari BCA → DIHITUNG ke cost center (tahap opsional; tanpa tahap → pos biaya umum cost center itu).
 * Penandaan mulai 1 Jan 2026; otomatis: CR, transfer ke rekening internal/reimburse, cocok Riwayat Reimburse → tidak;
 * sisanya "belum dicek". Penandaan manual mengalahkan otomatis.
 */
class TandaMutasiPt
{
    public const MULAI = '2026-01-01';

    public const STATUS = ['dihitung' => 'Dihitung', 'tidak' => 'Tidak dihitung', 'belum' => 'Belum dicek'];

    /** Penerima reimburse / rekening internal — transfer ke sini otomatis tidak dihitung. */
    public const PENERIMA_INTERNAL = '/BARNABAS MANAEN|YOSUA DWI ?PERMANA|MULTI TRANSPORTASI/i';

    public static function kunci(MutasiPt $m): string
    {
        return sha1(implode('|', [$m->tanggal?->toDateString(), $m->dbcr, number_format((float) $m->jumlah, 2, '.', ''), number_format((float) $m->saldo, 2, '.', ''), $m->keterangan]));
    }

    private static ?array $reimburse = null;

    /** [status, alasan] otomatis untuk baris tanpa penandaan manual. */
    public static function otomatis(MutasiPt $m): array
    {
        if ($m->tanggal?->toDateString() < self::MULAI) {
            return ['luar', 'sebelum '.self::MULAI];
        }
        if ($m->dbcr === 'CR') {
            return ['tidak', 'uang masuk'];
        }
        if (preg_match(self::PENERIMA_INTERNAL, (string) $m->keterangan)) {
            return ['tidak', 'reimburse / rekening internal'];
        }
        self::$reimburse ??= KasReimburse::get(['tanggal', 'total'])->concat(UjReimburse::get(['tanggal', 'total']))
            ->map(fn ($r) => [$r->tanggal?->toDateString(), (float) $r->total])->all();
        foreach (self::$reimburse as [$tgl, $total]) {
            if ($tgl && abs($total - $m->jumlah) < 1 && abs(strtotime($tgl) - strtotime($m->tanggal->toDateString())) <= 3 * 86400) {
                return ['tidak', 'cocok Riwayat Reimburse'];
            }
        }

        return ['belum', null];
    }

    private static ?Collection $semua = null;

    /**
     * Semua baris sejak MULAI dengan status efektif: [m, kunci, status, alasan, manual (MutasiPtTanda|null)].
     *
     * @return Collection<int, object>
     */
    public static function baris(): Collection
    {
        if (self::$semua !== null) {
            return self::$semua;
        }
        $tanda = MutasiPtTanda::all()->keyBy('kunci');

        return self::$semua = MutasiPt::where('tanggal', '>=', self::MULAI)->orderBy('tanggal')->orderBy('baris')->get()->map(function ($m) use ($tanda) {
            $k = self::kunci($m);
            $t = $tanda[$k] ?? null;
            [$status, $alasan] = $t ? [$t->status, 'ditandai manual'] : self::otomatis($m);

            return (object) ['m' => $m, 'kunci' => $k, 'status' => $status, 'alasan' => $alasan, 'tanda' => $t];
        });
    }

    /**
     * Baris yang DIHITUNG sebagai biaya: [tahap_id|null, pos_id|null, cost_center, ym, rp, m].
     * Tanpa tahap → pos biaya umum cost center itu (cakupan induk lebih dulu); tidak ada pos → pos_id null (tidak terbagi).
     *
     * @return Collection<int, object>
     */
    public static function dihitung(): Collection
    {
        $pos = ProyekTahap::whereNotNull('pos_overhead')->whereNotNull('cost_center')->get()
            ->sortBy(fn ($p) => $p->pos_overhead === 'induk' ? 0 : 1)->groupBy('cost_center')->map->first();

        return self::baris()->filter(fn ($b) => $b->status === 'dihitung' && $b->m->dbcr === 'DB')->map(fn ($b) => (object) [
            'tahap_id' => $b->tanda?->proyek_tahap_id, 'pos_id' => $b->tanda?->proyek_tahap_id ? null : ($pos[$b->tanda?->cost_center]->id ?? null),
            'cost_center' => $b->tanda?->cost_center, 'ym' => $b->m->tanggal->format('Y-m'), 'rp' => (float) $b->m->jumlah, 'm' => $b->m,
        ])->values();
    }
}
