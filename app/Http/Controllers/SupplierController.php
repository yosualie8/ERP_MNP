<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\Rekening;
use App\Models\SparepartBeli;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Master Supplier (owner 16 Nov 2026, menu Plant & Sparepart): data induk supplier sparepart — kontak, telepon, alamat, barang, catatan.
 * Rekening tidak diketik di sini: dibaca dari Daftar Rekening (nama pemilik = nama supplier). Pembelian dari Database Sparepart
 * (sparepart_beli.supplier, hanya supplier terverifikasi Admin Plant).
 */
class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $semuaStatus = $request->boolean('semua');
        $supplier = Supplier::orderBy('nama')->when(! $semuaStatus, fn ($q) => $q->where('aktif', true))->get();
        $kunci = fn ($v) => mb_strtolower(trim((string) $v));

        $beli = SparepartBeli::whereNotNull('supplier')->toBase()->get(['supplier', 'tanggal', 'total', 'nama_item', 'kategori'])->groupBy(fn ($b) => $kunci($b->supplier));
        $rekening = Rekening::whereIn('nama', $supplier->pluck('nama'))->get()->groupBy(fn ($r) => $kunci($r->nama));

        $baris = $supplier->map(function (Supplier $s) use ($beli, $rekening, $kunci) {
            $b = $beli[$kunci($s->nama)] ?? collect();
            $item = $b->reject(fn ($x) => in_array($x->kategori, SparepartBeli::BUKAN_ITEM, true))->countBy('nama_item')->sortDesc();

            return (object) ['s' => $s, 'n' => $b->count(), 'total' => (float) $b->sum('total'), 'terakhir' => $b->max('tanggal'),
                'item' => $item->keys()->take(4), 'item_lain' => max(0, $item->count() - 4),
                'kategori' => $b->reject(fn ($x) => in_array($x->kategori, SparepartBeli::BUKAN_ITEM, true))->countBy('kategori')->sortDesc()->keys()->take(3),
                'rekening' => $rekening[$kunci($s->nama)] ?? collect()];
        });

        return view('supplier.index', [
            'baris' => $baris, 'semuaStatus' => $semuaStatus, 'jumlahNonaktif' => Supplier::where('aktif', false)->count(),
            'bolehUbah' => $request->user()->bolehMenu('supplier'), 'bolehRekening' => $request->user()->bolehMenu('daftar-rekening'),
            'bolehSparepart' => $request->user()->bolehMenu('sparepart'),
        ]);
    }

    public function create(): View
    {
        return view('supplier.form', ['supplier' => new Supplier(['aktif' => true]), 'saran' => $this->saranNama()]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('supplier.form', ['supplier' => $supplier, 'saran' => $this->saranNama()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $s = Supplier::create([...$this->baca($request, null), 'user_id' => $request->user()->id]);
        $this->catat($request, 'supplier-tambah', "Supplier baru {$s->nama}", $s->toArray());

        return redirect()->route('supplier.index')->with('success', "Supplier {$s->nama} ditambahkan.");
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $lama = $supplier->toArray();
        $supplier->update([...$this->baca($request, $supplier), 'user_id' => $request->user()->id]);
        $berubah = collect($supplier->getChanges())->except(['updated_at', 'user_id'])->keys()->implode(', ');
        $this->catat($request, 'supplier-ubah', "Supplier {$supplier->nama} diubah: ".($berubah ?: 'tanpa perubahan'), ['sebelum' => $lama, 'sesudah' => $supplier->fresh()->toArray()]);

        return redirect()->route('supplier.index')->with('success', "Data supplier {$supplier->nama} disimpan.");
    }

    private function baca(Request $request, ?Supplier $s): array
    {
        $rapi = fn ($k) => ($v = trim(preg_replace('/\s+/', ' ', (string) $request->input($k)))) === '' ? null : $v;
        $request->merge(['nama' => $rapi('nama'), 'kontak' => $rapi('kontak'), 'telepon' => $rapi('telepon'), 'alamat' => $rapi('alamat'),
            'barang' => $rapi('barang'), 'catatan' => trim((string) $request->input('catatan')) ?: null, 'aktif' => $request->boolean('aktif')]);

        return $request->validate([
            'nama' => ['required', 'string', 'max:150', Rule::unique('supplier', 'nama')->ignore($s?->id)],
            'kontak' => ['nullable', 'string', 'max:100'],
            'telepon' => ['nullable', 'string', 'max:40'],
            'alamat' => ['nullable', 'string', 'max:300'],
            'barang' => ['nullable', 'string', 'max:200'],
            'catatan' => ['nullable', 'string', 'max:500'],
            'aktif' => ['boolean'],
        ], [
            'nama.required' => 'Nama supplier belum diisi.',
            'nama.unique' => 'Supplier dengan nama ini sudah ada di Master Supplier.',
        ]);
    }

    /** Nama untuk autocomplete: supplier di data pembelian + pemilik rekening yang sudah dikenal (supaya nama sama persis & tersambung). */
    private function saranNama(): array
    {
        return SparepartBeli::whereNotNull('supplier')->distinct()->pluck('supplier')
            ->merge(Rekening::whereNotNull('nama')->distinct()->pluck('nama'))->unique()->sort()->values()->all();
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Supplier', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
