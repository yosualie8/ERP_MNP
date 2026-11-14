<?php

namespace App\Support;

use App\Models\JagoMutasi;
use App\Models\UjDetail;
use App\Models\UjTransaksi;

/**
 * Nomor buku DO yang ditulis Eddy di catatan transfer Uang MOB (owner 14 Nov 2026), mis. "MOB KENDAL PIK DT 028 bambang DO 17259".
 * Uang MOB sendiri memakai nomor MOB-xxxxx (NomorMob); nomor buku ini hanya dipetakan ke Uang MOB-nya ('do_mob') supaya transaksi yang
 * terlanjur memakai nomor buku itu disarankan memakai nomor MOB, dan nomor buku itu tidak dipantau bongkarnya / dipasangkan ke surat jalan.
 * Kasus asal: UJ-16761 impack & dongkrak DT 028 10 Nov (catatan bank "DO 17259 ban no 6") diinput tanpa DO.
 */
class DoMob
{
    /** @var array<string, array{do: string, do_mob: ?string, id_uj: string, mobil: ?string, nama: ?string, tanggal: ?\Illuminate\Support\Carbon}>|null */
    private static ?array $cache = null;

    /**
     * DO MOB per kunci DO: DO yang disebut di catatan transfer Jago kantong UJ yang tertaut ke transaksi Kas UJ ber-Uang MOB.
     *
     * @return array<string, array{do: string, do_mob: ?string, id_uj: string, mobil: ?string, nama: ?string, tanggal: ?\Illuminate\Support\Carbon}>
     */
    public static function semua(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $mob = UjDetail::where('kategori', 'Uang MOB')->whereNotNull('uj_transaksi_id')->get(['uj_transaksi_id', 'id_uj', 'no_mobil', 'nama', 'tanggal', 'no_do'])
            ->keyBy('uj_transaksi_id');
        if ($mob->isEmpty()) {
            return self::$cache = [];
        }
        $noUj = UjTransaksi::whereIn('id', $mob->keys())->pluck('id', 'no_uj');
        $hasil = [];
        JagoMutasi::where('kantong', 'uj')->where('dipakai', 'like', 'uj:%')->whereNotNull('catatan')
            ->get(['dipakai', 'catatan'])
            ->each(function ($m) use ($noUj, $mob, &$hasil) {
                $id = $noUj[(int) substr($m->dipakai, 3)] ?? null;
                if (! $id || ! ($d = $mob[$id] ?? null)) {
                    return;
                }
                foreach (CocokCatatanJago::doDari((string) $m->catatan) as $k => $asli) {
                    $hasil[$k] ??= ['do' => $asli, 'do_mob' => NomorMob::adalah($d->no_do) ? $d->no_do : null, 'id_uj' => $d->id_uj, 'mobil' => $d->no_mobil, 'nama' => $d->nama, 'tanggal' => $d->tanggal];
                }
            });

        return self::$cache = $hasil;
    }

    /** DO MOB untuk satu No DO (null bila bukan DO MOB). */
    public static function untuk(?string $do): ?array
    {
        $k = SaldoSopir::kunciDo($do);

        return $k === null ? null : self::semua()[$k] ?? null;
    }

    public static function lupakan(): void
    {
        self::$cache = null;
    }
}
