<?php

namespace App\Http\Controllers;

use App\Models\BayarTanahRekap;
use App\Models\KasRiwayat;
use App\Models\RitasiMenunggu;
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
        // Rit tanpa DO (menunggu uang jalan) dipilih dengan kode "SJ<id>" — dibayar lewat No Surat Jalan (owner 11 Nov 2026).
        $menunggu = RitasiMenunggu::aktif()->keyBy(fn ($r) => $r->kode());
        $sebut = fn (string $k) => isset($menunggu[$k]) ? 'SJ '.$menunggu[$k]->no_seri : (RitasiMenunggu::idDariKode($k) ? "rit tanpa DO {$k}" : "DO {$k}");
        foreach (explode(',', $request->input('pilih')) as $x) {
            [$do, $h] = array_pad(explode(':', trim($x), 2), 2, '');
            $sj = RitasiMenunggu::idDariKode($do);
            if (! ctype_digit($do) && $sj === null) {
                continue;
            }
            $h = (int) preg_replace('/\D/', '', (string) $h);
            if ($h <= 0) {
                $tanpaHarga[] = $sebut($do);
            }
            $m = $menunggu[$do] ?? null;
            $isi[$do] = $sj === null ? ['do' => $do, 'harga' => $h]
                : ['do' => null, 'sj' => $sj, 'no_seri' => $m?->no_seri, 'no_mobil' => $m?->no_mobil, 'harga' => $h];
        }
        if (! $isi) {
            return back()->with('error', 'Belum ada DO yang dipilih.');
        }
        if ($tanpaHarga) {
            return back()->with('error', 'Harga tanah belum diisi untuk '.implode(', ', $tanpaHarga).'. Isi harganya dulu, lalu buat rekap lagi.');
        }
        $belum = MonitorRitasi::belumBayarTanah()['do']->pluck('do')->map(fn ($d) => (string) $d)->flip();
        $sudah = array_values(array_filter(array_keys($isi), fn ($d) => RitasiMenunggu::idDariKode((string) $d) !== null
            ? (! isset($menunggu[(string) $d]) || $menunggu[(string) $d]->dibayar) : ! isset($belum[(string) $d])));
        if ($sudah) {
            return back()->with('error', implode(', ', array_map(fn ($d) => $sebut((string) $d), $sudah)).' sudah punya transaksi uang tanah (atau sudah tidak menunggu). Muat ulang halaman.');
        }
        $terbuka = BayarTanahRekap::doMenunggu();
        $dobel = array_values(array_filter(array_keys($isi), fn ($d) => isset($terbuka[(string) $d])));
        if ($dobel) {
            return back()->with('error', implode(', ', array_map(fn ($d) => $sebut((string) $d)." ({$terbuka[(string) $d]})", $dobel)).' sudah ada di rekap yang belum dibayar.');
        }
        $rekap = BayarTanahRekap::create([
            'status' => 'menunggu', 'jumlah' => count($isi), 'total' => array_sum(array_column($isi, 'harga')), 'isi' => array_values($isi),
            'catatan' => $request->input('catatan'), 'user_id' => $request->user()->id,
        ]);
        KasRiwayat::create(['aksi' => 'rbt-buat', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
            'ringkasan' => "{$rekap->kode()} dibuat: {$rekap->jumlah} DO · ".rp($rekap->total), 'isi' => $rekap->only(['id', 'isi', 'total'])]);

        return back()->with('success', "{$rekap->kode()} tersimpan: {$rekap->jumlah} DO, total ".rp($rekap->total).'. Admin tinggal memilihnya di Input UJ → 🏔️ Input bayar tanah.');
    }

    /** Hapus rit tanpa DO (menunggu uang jalan) dari daftar — bila surat jalannya salah / tidak jadi. Yang sudah dibayar / direkap ditolak. */
    public function hapusMenunggu(Request $request, RitasiMenunggu $menunggu): RedirectResponse
    {
        $m = RitasiMenunggu::aktif()->firstWhere('id', $menunggu->id);
        if ($m?->dibayar) {
            return back()->with('error', "SJ {$menunggu->no_seri} sudah dibayar uang tanahnya ({$m->dibayar}) — tidak bisa dihapus.");
        }
        if ($rbt = BayarTanahRekap::doMenunggu()[$menunggu->kode()] ?? null) {
            return back()->with('error', "SJ {$menunggu->no_seri} ada di {$rbt} yang belum dibayar — batalkan rekapnya dulu.");
        }
        $menunggu->delete();
        KasRiwayat::create(['aksi' => 'rit-menunggu-hapus', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
            'ringkasan' => "Rit tanpa DO SJ {$menunggu->no_seri} ({$menunggu->no_mobil}, ".$menunggu->tanggal->translatedFormat('j M').') dihapus dari daftar menunggu uang jalan',
            'isi' => $menunggu->toArray()]);

        return back()->with('success', "SJ {$menunggu->no_seri} dihapus dari daftar rit menunggu uang jalan.");
    }

    public function batal(Request $request, BayarTanahRekap $rekap): RedirectResponse
    {
        if ($rekap->status !== 'menunggu') {
            return back()->with('error', "{$rekap->kode()} sudah ".strtolower(BayarTanahRekap::STATUS[$rekap->status]).', tidak bisa dibatalkan.');
        }
        $rekap->update(['status' => 'batal']);
        KasRiwayat::create(['aksi' => 'rbt-batal', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
            'ringkasan' => "{$rekap->kode()} dibatalkan ({$rekap->jumlah} DO · ".rp($rekap->total).')', 'isi' => ['id' => $rekap->id]]);

        return back()->with('success', "{$rekap->kode()} dibatalkan; DO-nya bisa direkap lagi.");
    }
}
