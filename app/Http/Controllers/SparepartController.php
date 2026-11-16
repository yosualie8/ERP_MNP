<?php

namespace App\Http\Controllers;

use App\Models\SparepartBeli;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Database Sparepart (owner 16 Nov 2026, menu Plant & Sparepart): kepala mekanik mencari sparepart, harga terakhir & termurah,
 * dan histori harganya. Sumber: tabel sparepart_beli (riwayat pembelian Kas Harian + Kas UJ dengan nama item baku).
 */
class SparepartController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'item';
        $semua = SparepartBeli::query()->orderBy('tanggal')->orderBy('id')->toBase()->get();
        $info = ['baris' => $semua->count(), 'awal' => $semua->first()?->tanggal, 'akhir' => $semua->last()?->tanggal, 'ditarik' => $semua->max('ditarik')];

        $urut = array_flip(SparepartBeli::URUT_KATEGORI);
        $item = $semua->reject(fn ($b) => in_array($b->kategori, SparepartBeli::BUKAN_ITEM, true))
            ->groupBy(fn ($b) => $b->nama_item.'|'.$b->satuan)
            ->map(function ($bs) {
                $akhir = $bs->last();
                $murah = $bs->sortBy('harga_satuan')->first();
                $perUnit = $bs->groupBy(fn ($b) => $b->tipe_unit ?: '(unit tidak disebut)')->sortByDesc(fn ($x) => $x->count());
                // supplier hanya yang diverifikasi Admin Plant (lainnya kosong) → supplier terakhir = pembelian terakhir yang suppliernya diketahui
                $supplier = $bs->whereNotNull('supplier')->countBy('supplier')->sortDesc();

                return (object) [
                    'kategori' => $akhir->kategori, 'nama' => $akhir->nama_item, 'satuan' => $akhir->satuan,
                    'akhir' => $akhir, 'murah' => $murah, 'akhir_sup' => $bs->whereNotNull('supplier')->last(), 'tinggi' => (float) $bs->max('harga_satuan'), 'rata' => (float) $bs->avg('harga_satuan'),
                    'n' => $bs->count(), 'qty' => (float) $bs->sum('qty'), 'total' => (float) $bs->sum('total'),
                    // harga terakhir per tipe unit (hanya bila ada lebih dari satu tipe atau tipenya disebut)
                    'per_unit' => $perUnit->count() > 1 || ! $perUnit->has('(unit tidak disebut)')
                        ? $perUnit->map(fn ($x, $tu) => ['tu' => $tu, 'harga' => (float) $x->last()->harga_satuan, 'n' => $x->count()])->values() : collect(),
                    'golongan' => $bs->pluck('golongan')->filter()->unique()->values(),
                    'spek' => $bs->pluck('spesifikasi')->filter()->countBy()->sortDesc()->keys()->take(8),
                    'supplier' => $supplier->take(4),
                    // kata-kata keterangan asli supaya pencarian juga menemukan istilah lapangan ("vbelt", "saringan solar")
                    'cari' => $bs->pluck('keterangan')->filter()->map(fn ($k) => mb_strtolower($k))->unique()->take(40)->implode(' | '),
                ];
            })
            ->sortBy(fn ($x) => sprintf('%02d %s', $urut[$x->kategori] ?? 99, mb_strtolower($x->nama)))
            ->values();

        // Riwayat: bawaan 6 bulan terakhir (semua baris = ±5 MB HTML, berat di HP); histori lengkap per item tetap lewat dialog.
        $periode = in_array($request->query('periode'), ['12', 'semua'], true) ? $request->query('periode') : '6';
        $sejak = $periode === 'semua' ? '' : now()->subMonths((int) $periode)->toDateString();

        return view('sparepart.index', [
            'tab' => $tab, 'info' => $info, 'item' => $item, 'periode' => $periode,
            'riwayat' => $tab === 'riwayat' ? $semua->filter(fn ($b) => $b->tanggal >= $sejak)->reverse()->values() : collect(),
            'jumlahPaket' => $semua->where('kategori', SparepartBeli::PAKET)->count(),
        ]);
    }

    /** Histori harga satu item (+ paket yang memuat item itu) untuk dialog di halaman Database Sparepart. */
    public function riwayat(Request $request): JsonResponse
    {
        $data = $request->validate(['nama' => ['required', 'string', 'max:200'], 'satuan' => ['nullable', 'string', 'max:20']]);
        $nama = $data['nama'];
        $baris = SparepartBeli::query()
            ->where(fn ($q) => $q->where('nama_item', $nama)->when($data['satuan'] ?? null, fn ($q, $s) => $q->where('satuan', $s), fn ($q) => $q->whereNull('satuan')))
            ->orWhere(fn ($q) => $q->where('kategori', SparepartBeli::PAKET)->where('nama_item', 'like', '%'.addcslashes($nama, '%_\\').'%'))
            ->orderByDesc('tanggal')->orderByDesc('id')->get()
            // paket: nama item harus salah satu bagiannya persis ("Filter Oli" tidak ikut "Filter Oli Gardan")
            ->filter(fn ($b) => $b->kategori !== SparepartBeli::PAKET || in_array($nama, explode(' + ', $b->nama_item), true));

        return response()->json($baris->map(fn ($b) => [
            'tanggal' => $b->tanggal->toDateString(), 'tgl' => $b->tanggal->translatedFormat('j M Y'), 'paket' => $b->kategori === SparepartBeli::PAKET,
            'nama' => $b->nama_item, 'spek' => $b->spesifikasi, 'golongan' => $b->golongan, 'tipe_unit' => $b->tipe_unit, 'no_unit' => $b->no_unit,
            'qty' => $b->qty, 'satuan' => $b->satuan, 'harga' => $b->harga_satuan, 'total' => $b->total, 'supplier' => $b->supplier, 'pic' => $b->pic,
            'keterangan' => $b->keterangan, 'sumber' => $b->sumber, 'ref' => $b->ref,
        ])->values());
    }

    public static function tgl(?string $t): string
    {
        return $t ? Carbon::parse($t)->translatedFormat('j M Y') : '';
    }
}
