<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\KubikasiKendaraan;
use App\Models\Ritasi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master kubikasi standar per jenis kendaraan. Hanya nilai awal kolom Kubikasi saat input ritasi; mengubahnya tidak
 * mengubah kubikasi rit yang sudah tercatat.
 */
class KubikasiKendaraanController extends Controller
{
    public function index(): View
    {
        $master = KubikasiKendaraan::orderBy('jenis')->get();
        $pakai = Ritasi::selectRaw('jenis_kendaraan j, count(*) n')->groupBy('jenis_kendaraan')->pluck('n', 'j');
        $ada = $master->map(fn ($m) => mb_strtolower($m->jenis))->all();

        return view('ritasi.kubikasi', [
            'master' => $master,
            'pakai' => $pakai->mapWithKeys(fn ($n, $j) => [mb_strtolower((string) $j) => $n]),
            // Jenis yang ada di data Ritasi tetapi belum punya kubikasi standar.
            'belum' => $pakai->keys()->filter(fn ($j) => $j && ! in_array(mb_strtolower($j), $ada, true))->values(),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $angka = fn ($v) => ($v = str_replace(',', '.', trim((string) $v))) === '' ? null : $v;
        $baris = collect((array) $request->input('baris', []))
            ->map(fn ($b) => ['jenis' => trim(preg_replace('/\s+/', ' ', (string) ($b['jenis'] ?? ''))), 'kubikasi' => $angka($b['kubikasi'] ?? ''), 'hapus' => ! empty($b['hapus'])])
            ->filter(fn ($b) => $b['jenis'] !== '' || $b['kubikasi'] !== null);
        $request->merge(['baris' => $baris->values()->all()]);
        $request->validate([
            'baris.*.jenis' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'baris.*.kubikasi' => ['required', 'numeric', 'between:0,999'],
        ], [
            'baris.*.jenis.required' => 'Jenis kendaraan baris :position belum diisi.',
            'baris.*.jenis.distinct' => 'Jenis kendaraan ":input" tertulis dua kali.',
            'baris.*.kubikasi.required' => 'Kubikasi baris :position belum diisi.',
            'baris.*.kubikasi.numeric' => 'Kubikasi baris :position harus angka (contoh 22 atau 7,5).',
        ]);

        $sebelum = KubikasiKendaraan::orderBy('jenis')->pluck('kubikasi', 'jenis')->all();
        foreach ($baris as $b) {
            $ada = KubikasiKendaraan::whereRaw('LOWER(jenis) = ?', [mb_strtolower($b['jenis'])])->first();
            if ($b['hapus']) {
                $ada?->delete();
            } elseif ($ada) {
                $ada->update(['jenis' => $b['jenis'], 'kubikasi' => $b['kubikasi'], 'user_id' => $request->user()->id]);
            } else {
                KubikasiKendaraan::create(['jenis' => $b['jenis'], 'kubikasi' => $b['kubikasi'], 'user_id' => $request->user()->id]);
            }
        }
        $sesudah = KubikasiKendaraan::orderBy('jenis')->pluck('kubikasi', 'jenis')->all();
        if ($sebelum != $sesudah) {
            KasRiwayat::create(['aksi' => 'kubikasi-ubah', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
                'ringkasan' => 'Kubikasi standar kendaraan diubah', 'isi' => ['sebelum' => $sebelum, 'sesudah' => $sesudah]]);
        }

        return redirect()->route('kubikasi.index')->with('success', 'Kubikasi standar disimpan.');
    }
}
