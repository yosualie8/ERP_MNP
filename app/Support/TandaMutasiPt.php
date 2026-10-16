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
 * langsung dari BCA → DIHITUNG ke cost center = TAHAP DETAIL (wajib); tahap pos biaya umum → dibagi lewat Alokasi Biaya Umum.
 * Penandaan mulai 1 Jan 2026; otomatis: CR, transfer ke rekening internal/reimburse, cocok Riwayat Reimburse → tidak;
 * sisanya "belum dicek". Penandaan manual mengalahkan otomatis.
 */
class TandaMutasiPt
{
    public const MULAI = '2026-01-01';

    public const STATUS = ['dihitung' => 'Dihitung', 'tidak' => 'Tidak dihitung', 'belum' => 'Belum dicek'];

    /** Penerima reimburse / rekening internal — transfer ke sini otomatis tidak dihitung. */
    public const PENERIMA_INTERNAL = '/BARNABAS MANAEN|YOSUA DWI ?PERMANA|MULTI TRANSPORTASI/i';

    /**
     * Aturan otomatis TIDAK dihitung (owner 16 Okt 2026): pendanaan / perpindahan uang & biaya yang sudah tertutup Harga Jual
     * ritasi, tarif rental atau merupakan aset. TIDAK ada aturan otomatis "dihitung" — itu selalu manual. Tarikan tetap dicek.
     */
    public const ATURAN_TIDAK = [
        '/HANS ANDERSON/i' => 'ke Hans Anderson (pendanaan)',
        '/PINJAMAN|TIME ?LOAN/i' => 'pelunasan pinjaman',
        '/MULTI NIAGA PUTRA/i' => 'ke rekening MNP sendiri',
        '/\bBFI\b|LEASI|FINANCE/i' => 'leasing unit (tertutup tarif)',
        '/UNITED TRACTOR|\bDP\b.*\b(UNIT|D\d{2}|PC ?\d{3})\b/i' => 'DP / beli unit (aset)',
        '/SPARE ?PART|OVERHAUL|\bBAN\b/i' => 'sparepart / overhaul / ban (tertutup tarif)',
        '/\bHPP\b.*\bRIT/i' => 'HPP ritasi (dari Harga Jual ritasi)',
    ];

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
        foreach (self::ATURAN_TIDAK as $pola => $alasan) {
            if (preg_match($pola, (string) $m->keterangan)) {
                return ['tidak', $alasan];
            }
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
            // tanda tanpa status (hanya keterangan tambahan) = status tetap ikut otomatis
            [$status, $alasan] = $t?->status ? [$t->status, 'ditandai manual'] : self::otomatis($m);

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
        // Cost center = TAHAP DETAIL (wajib, owner 16 Okt 2026). Tahap pos biaya umum → dibagi lewat Alokasi Biaya Umum.
        $pos = ProyekTahap::whereNotNull('pos_overhead')->pluck('id')->flip();

        return self::baris()->filter(fn ($b) => $b->status === 'dihitung' && $b->m->dbcr === 'DB' && $b->tanda?->proyek_tahap_id)->map(fn ($b) => (object) [
            'tahap_id' => isset($pos[$b->tanda->proyek_tahap_id]) ? null : $b->tanda->proyek_tahap_id,
            'pos_id' => isset($pos[$b->tanda->proyek_tahap_id]) ? $b->tanda->proyek_tahap_id : null,
            'cost_center' => $b->tanda->cost_center, 'catatan' => $b->tanda->catatan, 'ym' => $b->m->tanggal->format('Y-m'), 'rp' => (float) $b->m->jumlah, 'm' => $b->m,
        ])->values();
    }
}
