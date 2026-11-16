<?php

namespace App\Support;

use App\Models\SparepartBeli;
use App\Models\SparepartItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Daftar harga per item dari riwayat pembelian sparepart (halaman Database Sparepart & tool MCP cari_sparepart / histori_harga_sparepart). */
class DaftarSparepart
{
    /**
     * Merk part yang dikenali di kolom spesifikasi pembelian (usulan kolom Merk bila Admin Plant belum mengisi). Sengaja TIDAK memuat merk unit
     * (Komatsu, Isuzu, Hino, juga "Kobe" = singkatan Kobelco di data ini — muncul di selang, kaca, plat, jasa service) dan kata umum (Mobil, Total).
     */
    public const MERK = ['TEKIRO', 'DEXTONE', 'REXCO', 'SAKURA', 'DONALDSON', 'FLEETGUARD', 'VIC', 'ASPIRA', 'YUASA', 'INCOE', 'DENSO', 'NGK', 'BOSCH',
        'SHELL', 'MEDITRAN', 'CASTROL', 'FEDERAL', 'BRIDGESTONE', 'DUNLOP', 'MICHELIN', 'ACHILLES', 'KYB', 'NACHI', 'SKF', 'NTN', 'KOYO', 'FAG',
        'TIMKEN', 'OSRAM', 'PHILIPS', 'STANLEY', 'KENMASTER', 'KRISBOW', 'WIPRO', 'TOP1', 'PRESTONE'];

    /** Kata di keterangan pembelian yang menandakan barang asli / tiruan (usulan kolom Ori/KW). */
    private const ORI = '/\b(ori|original|orisinil|asli|genuine)\b/i';

    private const KW = '/\b(kw|kw1|kw2|kw3|copy|imitasi|tiruan)\b/i';

    /**
     * Ringkasan per item × satuan (tanpa paket / tidak dirinci / belum dikenali), urut kategori lalu nama.
     *
     * @param  Collection<int, object>  $semua  baris sparepart_beli urut tanggal naik
     */
    public static function item(Collection $semua): Collection
    {
        $urut = array_flip(SparepartBeli::URUT_KATEGORI);
        $atribut = SparepartItem::all()->keyBy(fn ($x) => SparepartItem::kunci($x->nama_item, $x->satuan));

        return $semua->reject(fn ($b) => in_array($b->kategori, SparepartBeli::BUKAN_ITEM, true))
            ->groupBy(fn ($b) => $b->nama_item.'|'.$b->satuan)
            ->map(function ($bs) use ($atribut) {
                $akhir = $bs->last();
                $isian = $atribut[SparepartItem::kunci($akhir->nama_item, $akhir->satuan)] ?? null;
                $usulMerk = self::usulMerk($bs);
                $usulKualitas = self::usulKualitas($bs);
                $perUnit = $bs->groupBy(fn ($b) => $b->tipe_unit ?: '(unit tidak disebut)')->sortByDesc(fn ($x) => $x->count());

                return (object) [
                    'kategori' => $akhir->kategori, 'nama' => $akhir->nama_item, 'satuan' => $akhir->satuan,
                    'akhir' => $akhir, 'murah' => $bs->sortBy('harga_satuan')->first(), 'tinggi' => (float) $bs->max('harga_satuan'), 'rata' => (float) $bs->avg('harga_satuan'),
                    // supplier hanya yang diverifikasi Admin Plant (lainnya kosong) → supplier terakhir = pembelian terakhir yang suppliernya diketahui
                    'akhir_sup' => $bs->whereNotNull('supplier')->last(),
                    // Merk & Ori/KW: isian Admin Plant (sparepart_item); kosong → usulan dari spesifikasi / keterangan pembelian (ditandai usulan)
                    'merk' => $isian?->merk ?? $usulMerk, 'merk_usul' => ! $isian?->merk && $usulMerk,
                    'kualitas' => $isian?->kualitas ?? $usulKualitas, 'kualitas_usul' => ! $isian?->kualitas && $usulKualitas,
                    'catatan' => $isian?->catatan,
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

    /** Merk tersering yang disebut di spesifikasi pembelian item ini (null bila tidak ada). */
    public static function usulMerk(Collection $bs): ?string
    {
        $pola = '/\b('.implode('|', self::MERK).')\b/i';
        $hit = $bs->flatMap(fn ($b) => preg_match_all($pola, (string) $b->spesifikasi, $m) ? array_unique(array_map('strtoupper', $m[1])) : []);

        return $hit->isEmpty() ? null : $hit->countBy()->sortDesc()->keys()->first();
    }

    /** 'ori' / 'kw' bila keterangan pembelian terakhir yang menyebutnya berkata demikian. */
    public static function usulKualitas(Collection $bs): ?string
    {
        foreach ($bs->reverse() as $b) {
            $t = $b->spesifikasi.' '.$b->keterangan;
            if (preg_match(self::KW, $t)) {
                return 'kw';
            }
            if (preg_match(self::ORI, $t)) {
                return 'ori';
            }
        }

        return null;
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
