<?php

namespace App\Http\Controllers;

use App\Models\AsetTruk;
use App\Models\KasRiwayat;
use App\Support\NomorMobil;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Data Aset: semua aset MNP (dump truck, alat berat, LV, engkel, …) — data induk di aplikasi. */
class AsetController extends Controller
{
    public function index(Request $request): View
    {
        $status = array_key_exists($request->query('status'), AsetTruk::STATUS) ? $request->query('status') : null;
        $kategori = array_key_exists($request->query('kategori'), AsetTruk::KATEGORI) ? $request->query('kategori') : null;
        $semua = AsetTruk::orderBy('kategori')->orderBy('no_lambung')->get();

        return view('aset.index', [
            'truk' => $semua->when($status, fn ($c) => $c->where('status', $status))->when($kategori, fn ($c) => $c->where('kategori', $kategori))->values(),
            'semua' => $semua, 'status' => $status, 'kategori' => $kategori, 'bolehUbah' => $request->user()->bolehMenu('aset'),
        ]);
    }

    public function create(): View
    {
        return view('aset.form', ['aset' => new AsetTruk(['status' => 'aktif', 'kategori' => request('kategori', 'dump_truck'), 'kepemilikan' => 'milik']), 'jenis' => $this->daftarJenis()]);
    }

    public function edit(AsetTruk $aset): View
    {
        return view('aset.form', ['aset' => $aset, 'jenis' => $this->daftarJenis()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $aset = AsetTruk::create([...$this->baca($request, null), 'user_id' => $request->user()->id]);
        $this->catat($request, 'aset-tambah', "Aset baru {$aset->no_lambung} {$aset->plat}", $aset->toArray());

        return redirect()->route('aset.index')->with('success', "{$aset->labelKategori()} {$aset->no_lambung} ditambahkan ke Data Aset.");
    }

    public function update(Request $request, AsetTruk $aset): RedirectResponse
    {
        $lama = $aset->toArray();
        $aset->update([...$this->baca($request, $aset), 'user_id' => $request->user()->id]);
        $berubah = collect($aset->getChanges())->except(['updated_at', 'user_id'])->keys()->implode(', ');
        $this->catat($request, 'aset-ubah', "Aset {$aset->no_lambung} diubah: ".($berubah ?: 'tanpa perubahan'), ['sebelum' => $lama, 'sesudah' => $aset->fresh()->toArray()]);

        return redirect()->route('aset.index')->with('success', "Data {$aset->no_lambung} disimpan.");
    }

    private function baca(Request $request, ?AsetTruk $aset): array
    {
        // Nomor DT dibakukan "DT 000" ("dt34" → DT 034); nama lain bebas (spasi dirapikan), mis. Selfloader, Excavator Kobelco 07.
        $kode = trim(preg_replace('/\s+/', ' ', (string) $request->input('no_lambung')));
        $request->merge([
            'no_lambung' => preg_match('/^dt\s*[o0-9]{1,3}$/i', $kode) ? NomorMobil::rapikan($kode) : ($kode === '' ? null : $kode),
            'vendor' => ($v = trim((string) $request->input('vendor'))) === '' ? null : $v,
            'plat' => ($p = trim(preg_replace('/\s+/', ' ', strtoupper((string) $request->input('plat'))))) === '' ? null : $p,
            'no_rangka' => strtoupper(trim((string) $request->input('no_rangka'))) ?: null,
            'no_mesin' => strtoupper(trim((string) $request->input('no_mesin'))) ?: null,
        ]);

        return $request->validate([
            'kategori' => ['required', Rule::in(array_keys(AsetTruk::KATEGORI))],
            'no_lambung' => ['required', 'string', 'max:60', Rule::unique('aset_truk', 'no_lambung')->ignore($aset?->id),
                ...(preg_match('/^dt\b/i', $kode) ? ['regex:/^DT \d{3}$/'] : [])],
            'kepemilikan' => ['required', Rule::in(array_keys(AsetTruk::KEPEMILIKAN))],
            'vendor' => ['nullable', 'required_if:kepemilikan,rental', 'string', 'max:100'],
            'plat' => ['nullable', 'string', 'max:20'],
            'jenis' => ['nullable', 'string', 'max:40'],
            'tahun' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'no_rangka' => ['nullable', 'string', 'max:60'],
            'no_mesin' => ['nullable', 'string', 'max:60'],
            'stnk_berlaku' => ['nullable', 'date'],
            'kir_berlaku' => ['nullable', 'date'],
            'status' => ['required', Rule::in(array_keys(AsetTruk::STATUS))],
            'driver_tetap' => ['nullable', 'string', 'max:60'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ], [
            'no_lambung.regex' => 'No Lambung dump truck harus berformat "DT 000", mis. DT 026.',
            'no_lambung.unique' => 'Kode / nama aset ini sudah terdaftar di Data Aset.',
            'no_lambung.required' => 'Kode / nama aset belum diisi.',
            'vendor.required_if' => 'Aset rental: isi nama vendornya.',
        ]);
    }

    private function daftarJenis(): array
    {
        return AsetTruk::whereNotNull('jenis')->distinct()->orderBy('jenis')->pluck('jenis')->merge(['Faw', 'Mercy', 'Giga', 'Hino', 'Komatsu PC 200', 'Kobelco', 'SANY'])->unique()->values()->all();
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Aset', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
