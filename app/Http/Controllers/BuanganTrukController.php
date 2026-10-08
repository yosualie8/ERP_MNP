<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\TrukBuangan;
use App\Support\BuanganTruk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ritasi > Buangan Truck: truk aktif 30 hari terakhir & tujuan buangannya; admin pengurus truk bisa mengubahnya. */
class BuanganTrukController extends Controller
{
    public function index(): View
    {
        return view('ritasi.buangan', ['truk' => BuanganTruk::daftar(), 'saran' => BuanganTruk::saran()]);
    }

    /** Simpan tujuan yang diubah (form satu halaman). Kosong = kembali mengikuti tahap rit terakhir. */
    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate(['tujuan' => ['required', 'array'], 'tujuan.*' => ['nullable', 'string', 'max:100']]);
        $kini = collect(BuanganTruk::daftar())->keyBy('no_lambung');
        $ubah = [];
        foreach ($data['tujuan'] as $dt => $baru) {
            if (! isset($kini[$dt])) {
                continue;
            }
            $baru = trim(preg_replace('/\s+/', ' ', (string) $baru));
            $lama = $kini[$dt];
            if ($baru === '') {
                if ($lama['sumber'] === 'admin') {
                    TrukBuangan::where('no_lambung', $dt)->delete();
                    $ubah[$dt] = [$lama['tujuan'], ($lama['tahap_rit'] ?? '–').' (ikut ritasi)'];
                }

                continue;
            }
            if ($baru === (string) $lama['tujuan']) {
                continue;
            }
            TrukBuangan::updateOrCreate(['no_lambung' => $dt], ['tujuan' => $baru, 'user_id' => $request->user()->id]);
            $ubah[$dt] = [$lama['tujuan'] ?? '–', $baru];
        }
        if (! $ubah) {
            return back()->with('success', 'Tidak ada tujuan buangan yang berubah.');
        }
        KasRiwayat::create(['aksi' => 'truk-buangan', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth('Tujuan buangan: '.collect($ubah)->map(fn ($u, $dt) => "{$dt} {$u[0]} → {$u[1]}")->implode('; '), 0, 490, '…'),
            'isi' => ['ubah' => $ubah], 'user_id' => $request->user()->id]);

        return back()->with('success', count($ubah).' truk diubah tujuan buangannya: '.collect($ubah)->map(fn ($u, $dt) => "{$dt} → {$u[1]}")->implode(', ').'.');
    }
}
