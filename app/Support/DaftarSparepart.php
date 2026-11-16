<?php

namespace App\Support;

use App\Models\SparepartBeli;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Daftar harga per item dari riwayat pembelian sparepart (halaman Database Sparepart & tool MCP cari_sparepart / histori_harga_sparepart). */
class DaftarSparepart
{
    /**
     * Ringkasan per item × satuan (tanpa paket / tidak dirinci / belum dikenali), urut kategori lalu nama.
     *
     * @param  Collection<int, object>  $semua  baris sparepart_beli urut tanggal naik
     */
    public static function item(Collection $semua): Collection
    {
        $urut = array_flip(SparepartBeli::URUT_KATEGORI);

        return $semua->reject(fn ($b) => in_array($b->kategori, SparepartBeli::BUKAN_ITEM, true))
            ->groupBy(fn ($b) => $b->nama_item.'|'.$b->satuan)
            ->map(function ($bs) {
                $akhir = $bs->last();
                $perUnit = $bs->groupBy(fn ($b) => $b->tipe_unit ?: '(unit tidak disebut)')->sortByDesc(fn ($x) => $x->count());

                return (object) [
                    'kategori' => $akhir->kategori, 'nama' => $akhir->nama_item, 'satuan' => $akhir->satuan,
                    'akhir' => $akhir, 'murah' => $bs->sortBy('harga_satuan')->first(), 'tinggi' => (float) $bs->max('harga_satuan'), 'rata' => (float) $bs->avg('harga_satuan'),
                    // supplier hanya yang diverifikasi Admin Plant (lainnya kosong) → supplier terakhir = pembelian terakhir yang suppliernya diketahui
                    'akhir_sup' => $bs->whereNotNull('supplier')->last(),
                    'n' => $bs->count(), 'qty' => (float) $bs->sum('qty'), 'total' => (float) $bs->sum('total'),
                    // harga terakhir per tipe unit (hanya bila ada lebih dari satu tipe atau tipenya disebut)
                    'per_unit' => $perUnit->count() > 1 || ! $perUnit->has('(unit tidak disebut)')
                        ? $perUnit->map(fn ($x, $tu) => ['tu' => $tu, 'harga' => (float) $x->last()->harga_satuan, 'n' => $x->count()])->values() : collect(),
                    'golongan' => $bs->pluck('golongan')->filter()->unique()->values(),
                    'spek' => $bs->pluck('spesifikasi')->filter()->countBy()->sortDesc()->keys()->take(8),
                    'supplier' => $bs->whereNotNull('supplier')->countBy('supplier')->sortDesc()->take(4),
                    // kata-kata keterangan asli supaya pencarian juga menemukan istilah lapangan ("vbelt", "saringan solar")
                    'cari' => $bs->pluck('keterangan')->filter()->map(fn ($k) => mb_strtolower($k))->unique()->take(40)->implode(' | '),
                ];
            })
            ->sortBy(fn ($x) => sprintf('%02d %s', $urut[$x->kategori] ?? 99, mb_strtolower($x->nama)))
            ->values();
    }

    /** Saring: semua kata harus ada di nama item / keterangan asli / spesifikasi / unit / supplier; "DT 26" = "DT 026". */
    public static function saringKata(Builder $q, ?string $kata): Builder
    {
        foreach (preg_split('/\s+/', trim(self::rapikanUnit((string) $kata))) ?: [] as $k) {
            if ($k === '') {
                continue;
            }
            $pola = '%'.addcslashes(str_replace('_', ' ', $k), '%_\\').'%';
            $q->where(fn ($w) => $w->where('nama_item', 'like', $pola)->orWhere('keterangan', 'like', $pola)->orWhere('spesifikasi', 'like', $pola)
                ->orWhere('tipe_unit', 'like', $pola)->orWhere('no_unit', 'like', $pola)->orWhere('golongan', 'like', $pola)->orWhere('supplier', 'like', $pola)
                ->orWhere('kategori', 'like', $pola));
        }

        return $q;
    }

    /** "dt26" / "DT-026" → "DT_026" (satu kata, dicari sebagai "DT 026"). */
    private static function rapikanUnit(string $v): string
    {
        return preg_replace_callback('/\b(dt|lv|dz)\s*-?\s*0*(\d{1,3})\b/i', fn ($m) => strtoupper($m[1]).'_'.str_pad($m[2], 3, '0', STR_PAD_LEFT), $v);
    }
}
