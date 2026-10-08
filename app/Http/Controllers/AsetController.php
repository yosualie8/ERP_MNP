<?php

namespace App\Http\Controllers;

use App\Models\AsetTruk;
use App\Models\KasRiwayat;
use App\Support\NomorMobil;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Data Aset: daftar truk milik MNP (data induk di aplikasi) — hanya data truknya; performa & pencocokan dibuat terpisah nanti. */
class AsetController extends Controller
{
    public function index(Request $request): View
    {
        $status = array_key_exists($request->query('status'), AsetTruk::STATUS) ? $request->query('status') : null;
        $semua = AsetTruk::orderBy('no_lambung')->get();

        return view('aset.index', [
            'truk' => $status ? $semua->where('status', $status)->values() : $semua,
            'semua' => $semua, 'status' => $status, 'bolehUbah' => $request->user()->bolehMenu('aset'),
        ]);
    }

    public function create(): View
    {
        return view('aset.form', ['aset' => new AsetTruk(['status' => 'aktif']), 'jenis' => $this->daftarJenis()]);
    }

    public function edit(AsetTruk $aset): View
    {
        return view('aset.form', ['aset' => $aset, 'jenis' => $this->daftarJenis()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $aset = AsetTruk::create([...$this->baca($request, null), 'user_id' => $request->user()->id]);
        $this->catat($request, 'aset-tambah', "Aset baru {$aset->no_lambung} {$aset->plat}", $aset->toArray());

        return redirect()->route('aset.index')->with('success', "Truk {$aset->no_lambung} ditambahkan ke Data Aset.");
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
        $request->merge([
            'no_lambung' => NomorMobil::rapikan($request->input('no_lambung')),
            'plat' => ($p = trim(preg_replace('/\s+/', ' ', strtoupper((string) $request->input('plat'))))) === '' ? null : $p,
            'no_rangka' => strtoupper(trim((string) $request->input('no_rangka'))) ?: null,
            'no_mesin' => strtoupper(trim((string) $request->input('no_mesin'))) ?: null,
        ]);

        return $request->validate([
            'no_lambung' => ['required', 'regex:/^DT \d{3}$/', Rule::unique('aset_truk', 'no_lambung')->ignore($aset?->id)],
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
            'no_lambung.regex' => 'No Lambung harus berformat "DT 000", mis. DT 026.',
            'no_lambung.unique' => 'No Lambung ini sudah terdaftar di Data Aset.',
        ]);
    }

    private function daftarJenis(): array
    {
        return AsetTruk::whereNotNull('jenis')->distinct()->orderBy('jenis')->pluck('jenis')->merge(['Faw', 'Mercy', 'Giga', 'Hino'])->unique()->values()->all();
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Aset', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
