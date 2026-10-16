<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\MutasiPt;
use App\Models\MutasiPtTanda;
use App\Models\ProyekTahap;
use App\Support\ImporMutasiPt;
use App\Support\TandaMutasiPt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Mutasi Rekening PT (BCA): lihat per bulan / cari, dan tandai tiap transaksi (sejak 2026) Dihitung / Tidak dihitung
 * sebagai biaya + Cost Center (+ tahap opsional). Data dari file Drive (mnp:impor-mutasi-pt).
 */
class MutasiPtController extends Controller
{
    public function index(Request $request): View
    {
        $bulanAda = MutasiPt::selectRaw("DATE_FORMAT(tanggal, '%Y-%m') ym, count(*) n")->groupBy('ym')->orderByDesc('ym')->pluck('n', 'ym');
        $q = trim((string) $request->query('q'));
        $status = array_key_exists((string) $request->query('status'), TandaMutasiPt::STATUS) ? $request->query('status') : null;
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? $request->query('bulan') : $bulanAda->keys()->first();
        $tanda = TandaMutasiPt::baris()->keyBy('kunci');
        // status dipilih → semua bulan sejak 2026 dengan status itu; selain itu per bulan / hasil cari.
        $baris = MutasiPt::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => collect(preg_split('/\s+/', $q))->each(fn ($kata) => $x->where('keterangan', 'like', '%'.$kata.'%'))))
            ->when($status, fn ($w) => $w->where('tanggal', '>=', TandaMutasiPt::MULAI))
            ->when($q === '' && ! $status && $bulan, fn ($w) => $w->whereRaw("DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulan]))
            ->orderBy('tanggal')->orderBy('baris')->limit(5000)->get()
            ->map(fn ($m) => (object) ['m' => $m, 't' => $tanda[TandaMutasiPt::kunci($m)] ?? null])
            ->when($status, fn ($c) => $c->filter(fn ($x) => $x->t?->status === $status))->values();
        $hitung = TandaMutasiPt::baris()->groupBy('status')->map->count();

        return view('mutasi-pt.index', [
            'baris' => $baris, 'bulan' => $bulan, 'bulanAda' => $bulanAda, 'q' => $q, 'status' => $status, 'hitung' => $hitung,
            'rpDihitung' => TandaMutasiPt::dihitung()->sum('rp'),
            'impor' => Cache::get(ImporMutasiPt::KUNCI),
            'saldoAkhir' => MutasiPt::orderByDesc('tanggal')->orderByDesc('baris')->first(),
            // Pilihan Cost Center = semua tahap detail: yang berjalan dulu, lalu pos biaya umum, lalu lainnya.
            'tahap' => ProyekTahap::with('proyek')->get()->sortBy(fn ($t) => [$t->status === 'progress' && ! $t->pos_overhead ? 0 : ($t->pos_overhead ? 1 : 2), $t->nama])->values(),
            'bolehTandai' => $request->user()->bolehMenu('mutasi-pt'),
        ]);
    }

    /** Simpan penandaan satu transaksi (dari halaman, tanpa muat ulang). */
    public function tandai(Request $request): JsonResponse
    {
        $d = $request->validate([
            'kunci' => ['required', 'string', 'size:40'],
            'status' => ['required', Rule::in(array_keys(TandaMutasiPt::STATUS))],
            'tahap_id' => ['nullable', 'integer', 'exists:proyek_tahap,id'],
        ]);
        $b = TandaMutasiPt::baris()->firstWhere('kunci', $d['kunci']);
        if (! $b) {
            return response()->json(['galat' => 'Transaksi tidak ditemukan (mungkin file Drive berubah) — muat ulang halaman.'], 422);
        }
        if ($d['status'] === 'dihitung') {
            if ($b->m->dbcr !== 'DB') {
                return response()->json(['galat' => 'Hanya uang keluar (DB) yang bisa dihitung sebagai biaya.'], 422);
            }
            if (empty($d['tahap_id'])) {
                return response()->json(['galat' => 'Isi Cost Center (tahap detail) dulu — wajib untuk transaksi yang dihitung.'], 422);
            }
        }
        $tahap = ! empty($d['tahap_id']) ? ProyekTahap::find($d['tahap_id']) : null;
        $lama = $b->tanda?->only(['status', 'cost_center', 'proyek_tahap_id']);
        // Cost center = tahap detail; kolom cost_center menyimpan kode cost center tahap itu sebagai keterangan.
        $t = MutasiPtTanda::updateOrCreate(['kunci' => $d['kunci']], [
            'status' => $d['status'], 'proyek_tahap_id' => $d['status'] === 'dihitung' ? $tahap?->id : null,
            'cost_center' => $d['status'] === 'dihitung' ? $tahap?->costCenter() : null, 'user_id' => $request->user()->id,
        ]);
        KasRiwayat::create(['aksi' => 'mutasi-pt-tanda', 'lembar' => 'Mutasi PT', 'baris_awal' => $b->m->baris, 'baris_akhir' => $b->m->baris,
            'ringkasan' => mb_strimwidth("BCA PT {$b->m->tanggal->format('d-m-Y')} Rp ".number_format($b->m->jumlah, 0, ',', '.').': '.TandaMutasiPt::STATUS[$t->status]
                .($tahap && $t->status === 'dihitung' ? " · {$tahap->nama}" : '').' — '.$b->m->keterangan, 0, 490, '…'),
            'isi' => ['kunci' => $t->kunci, 'lama' => $lama, 'baru' => $t->only(['status', 'cost_center', 'proyek_tahap_id'])], 'user_id' => $request->user()->id]);

        return response()->json(['ok' => true, 'status' => $t->status, 'label' => TandaMutasiPt::STATUS[$t->status]]);
    }

    public function perbarui(): RedirectResponse
    {
        try {
            $h = ImporMutasiPt::jalankan(true);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal memperbarui dari Drive: '.$e->getMessage());
        }

        return back()->with('success', "Mutasi Rekening PT diperbarui dari Drive: {$h['baris']} baris.");
    }
}
