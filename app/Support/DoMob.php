<?php

namespace App\Support;

use App\Models\JagoMutasi;
use App\Models\UjDetail;
use App\Models\UjTransaksi;

/**
 * DO perjalanan MOB (owner 14 Nov 2026). Uang MOB dicatat di Kas UJ tanpa No DO (aturan 21), tetapi Eddy menulis DO perjalanannya di
 * catatan transfer Jago, mis. "MOB KENDAL PIK DT 028 bambang DO 17259". Biaya lain di perjalanan itu (sparepart, uang makan) memakai
 * DO tersebut supaya tertelusur ke perjalanannya. DO MOB tidak akan pernah bongkar: tidak dipantau di Monitor Ritasi / dipasangkan ke
 * surat jalan, dan transaksi ber-DO MOB tidak dianggap "DO tanpa Uang Jalan" (aturan 19).
 * Kasus asal: UJ-16761 impack & dongkrak DT 028 10 Nov (catatan bank "DO 17259 ban no 6") diinput tanpa DO.
 */
class DoMob
{
    /** @var array<string, array{do: string, id_uj: string, mobil: ?string, nama: ?string, tanggal: ?\Illuminate\Support\Carbon}>|null */
    private static ?array $cache = null;

    /**
     * DO MOB per kunci DO: DO yang disebut di catatan transfer Jago kantong UJ yang tertaut ke transaksi Kas UJ ber-Uang MOB.
     *
     * @return array<string, array{do: string, id_uj: string, mobil: ?string, nama: ?string, tanggal: ?\Illuminate\Support\Carbon}>
     */
    public static function semua(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $mob = UjDetail::where('kategori', 'Uang MOB')->whereNotNull('uj_transaksi_id')->get(['uj_transaksi_id', 'id_uj', 'no_mobil', 'nama', 'tanggal'])
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
                    $hasil[$k] ??= ['do' => $asli, 'id_uj' => $d->id_uj, 'mobil' => $d->no_mobil, 'nama' => $d->nama, 'tanggal' => $d->tanggal];
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
