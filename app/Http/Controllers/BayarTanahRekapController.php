<?php

namespace App\Http\Controllers;

use App\Models\BayarTanahRekap;
use App\Models\KasRiwayat;
use App\Support\MonitorRitasi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Menu Bayar Tanah → "Buat rekap bayar tanah": DO terpilih + harga tanahnya disimpan sebagai RBT-xxxx (menunggu dibayar).
 * Admin lalu memilih RBT itu di Input UJ → Input bayar tanah; setelah disimpan, RBT tercatat dibayar dengan ID UJ-nya.
 */
class BayarTanahRekapController extends Controller
{
    public function simpan(Request $request): RedirectResponse
    {
        $request->validate(['pilih' => ['required', 'string', 'max:20000'], 'catatan' => ['nullable', 'string', 'max:500']]);
        $isi = [];
        $tanpaHarga = [];
        foreach (explode(',', $request->input('pilih')) as $x) {
            [$do, $h] = array_pad(explode(':', trim($x), 2), 2, '');
            if (! ctype_digit($do)) {
                continue;
            }
            $h = (int) preg_replace('/\D/', '', (string) $h);
            if ($h <= 0) {
                $tanpaHarga[] = $do;
            }
            $isi[$do] = ['do' => $do, 'harga' => $h];
        }
        if (! $isi) {
            return back()->with('error', 'Belum ada DO yang dipilih.');
        }
        if ($tanpaHarga) {
            return back()->with('error', 'Harga tanah belum diisi untuk DO '.implode(', ', $tanpaHarga).'. Isi harganya dulu, lalu buat rekap lagi.');
        }
        $belum = MonitorRitasi::belumBayarTanah()['do']->pluck('do')->map(fn ($d) => (string) $d)->flip();
        $sudah = array_values(array_filter(array_keys($isi), fn ($d) => ! isset($belum[(string) $d])));
        if ($sudah) {
            return back()->with('error', 'DO '.implode(', ', $sudah).' sudah punya transaksi uang tanah (atau tidak ada di Kas UJ). Muat ulang halaman.');
        }
        $terbuka = BayarTanahRekap::doMenunggu();
        $dobel = array_values(array_filter(array_keys($isi), fn ($d) => isset($terbuka[(string) $d])));
        if ($dobel) {
            return back()->with('error', 'DO '.implode(', ', array_map(fn ($d) => "{$d} ({$terbuka[(string) $d]})", $dobel)).' sudah ada di rekap yang belum dibayar.');
        }
        $rekap = BayarTanahRekap::create([
            'status' => 'menunggu', 'jumlah' => count($isi), 'total' => array_sum(array_column($isi, 'harga')), 'isi' => array_values($isi),
            'catatan' => $request->input('catatan'), 'user_id' => $request->user()->id,
        ]);
        KasRiwayat::create(['aksi' => 'rbt-buat', 'lembar' => 'Bayar Tanah', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
            'ringkasan' => "{$rekap->kode()} dibuat: {$rekap->jumlah} DO · ".rp($rekap->total), 'isi' => $rekap->only(['id', 'isi', 'total'])]);

        return back()->with('success', "{$rekap->kode()} tersimpan: {$rekap->jumlah} DO, total ".rp($rekap->total).'. Admin tinggal memilihnya di Input UJ → 🏔️ Input bayar tanah.');
    }

    public function batal(Request $request, BayarTanahRekap $rekap): RedirectResponse
    {
        if ($rekap->status !== 'menunggu') {
            return back()->with('error', "{$rekap->kode()} sudah ".strtolower(BayarTanahRekap::STATUS[$rekap->status]).', tidak bisa dibatalkan.');
        }
        $rekap->update(['status' => 'batal']);
        KasRiwayat::create(['aksi' => 'rbt-batal', 'lembar' => 'Bayar Tanah', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
            'ringkasan' => "{$rekap->kode()} dibatalkan ({$rekap->jumlah} DO · ".rp($rekap->total).')', 'isi' => ['id' => $rekap->id]]);

        return back()->with('success', "{$rekap->kode()} dibatalkan; DO-nya bisa direkap lagi.");
    }
}
