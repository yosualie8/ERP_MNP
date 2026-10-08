<?php

namespace App\Http\Controllers;

use App\Models\AkunGl;
use App\Models\CostCenter;
use App\Models\KasBulan;
use App\Models\KasTransfer;
use App\Models\KasFoto;
use App\Models\KodeGl;
use App\Support\StatusReimburse;
use App\Support\TulisKasSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class KasController extends Controller
{
    /** Buku rekening per bulan: transfer + rincian bon, saldo berjalan seperti lembar bulanan. */
    public function index(Request $request): View
    {
        $daftarBulan = KasBulan::orderBy('bulan')->get();
        $bulan = $daftarBulan->firstWhere('lembar', $request->query('lembar')) ?? $daftarBulan->last();
        $q = trim((string) $request->query('q'));
        $tanggal = $request->integer('tgl') ?: null;

        $transfer = collect();
        $daftarTanggal = collect();
        if ($bulan) {
            $daftarTanggal = KasTransfer::where('kas_bulan_id', $bulan->id)->distinct()->orderBy('tanggal')->pluck('tanggal');
            $transfer = KasTransfer::where('kas_bulan_id', $bulan->id)
                ->with(['bon.kodeGl.akun', 'bon.kodeGl.costCenter'])
                ->when($tanggal, fn ($query) => $query->whereDay('tanggal', $tanggal))
                ->when($q !== '', function ($query) use ($q) {
                    $like = '%'.$q.'%';
                    $query->where(fn ($w) => $w->where('keterangan', 'like', $like)
                        ->orWhere('nama_tujuan', 'like', $like)
                        ->orWhereHas('bon', fn ($b) => $b->where('keterangan', 'like', $like)
                            ->orWhere('pic', 'like', $like)
                            ->orWhere('id_transaksi', 'like', $like)
                            ->orWhereHas('kodeGl', fn ($k) => $k->where('kode_asli', 'like', $like))));
                })
                // Terbaru di atas: baris paling bawah di sheet = yang terakhir diinput.
                ->orderByDesc('baris')
                ->get();
        }

        // Transfer yang tepat di bawahnya ada baris "Biaya Transfer Keluar" miliknya (ditanyakan saat menghapus).
        $punyaBiaya = [];
        if ($bulan) {
            $semua = KasTransfer::where('kas_bulan_id', $bulan->id)->withMax('bon', 'baris')->withCount('bon')
                ->orderBy('baris')->get(['id', 'baris', 'kredit', 'keterangan'])->values();
            foreach ($semua as $i => $t) {
                $berikut = $semua[$i + 1] ?? null;
                if ($berikut && $berikut->kredit > 0 && $berikut->kredit <= TulisKasSheet::BIAYA_TRANSFER_MAKS &&$berikut->bon_count <= 1
                    && stripos((string) $berikut->keterangan, 'biaya transfer') !== false
                    && stripos((string) $t->keterangan, 'biaya transfer') === false
                    && $berikut->baris === max($t->baris, (int) $t->bon_max_baris) + 1) {
                    $punyaBiaya[$t->id] = true;
                }
            }
        }

        // Status reimburse per detail (lembar "Sudah Reimburse") dan per transfer: sudah semua / sebagian / belum.
        $sudah = StatusReimburse::untuk($transfer->flatMap(fn ($t) => $t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t)))->all());
        $statusBon = [];
        $statusTransfer = [];
        foreach ($transfer as $t) {
            if ($t->bon->isEmpty()) {
                $statusTransfer[$t->id] = ['kode' => $t->debet ? 'masuk' : 'kosong'];

                continue;
            }
            $tgl = [];
            foreach ($t->bon as $b) {
                $id = StatusReimburse::idBon($b, $t);
                $statusBon[$b->id] = array_key_exists($id, $sudah) ? ['sudah' => true, 'tanggal' => $sudah[$id]] : ['sudah' => false];
                if ($statusBon[$b->id]['sudah']) {
                    $tgl[] = $sudah[$id];
                }
            }
            $n = count($tgl);
            $statusTransfer[$t->id] = [
                'kode' => $n === $t->bon->count() ? 'sudah' : ($n ? 'sebagian' : 'belum'),
                'tanggal' => collect($tgl)->filter()->max(), 'sudah' => $n, 'jumlah' => $t->bon->count(),
                'nilai_belum' => (int) $t->bon->reject(fn ($b) => $statusBon[$b->id]['sudah'])->sum('nominal'),
            ];
        }
        $ringkasStatus = [
            'belum' => collect($statusTransfer)->whereIn('kode', ['belum', 'sebagian'])->count(),
            'nilai_belum' => (int) collect($statusTransfer)->sum(fn ($s) => $s['nilai_belum'] ?? 0),
            'sudah' => collect($statusTransfer)->where('kode', 'sudah')->count(),
        ];
        $filterStatus = in_array($request->query('status'), ['belum', 'sudah'], true) ? $request->query('status') : null;
        if ($filterStatus) {
            $transfer = $transfer->filter(fn ($t) => $filterStatus === 'sudah'
                ? $statusTransfer[$t->id]['kode'] === 'sudah'
                : in_array($statusTransfer[$t->id]['kode'], ['belum', 'sebagian'], true))->values();
        }

        $jumlahFoto = KasFoto::kas()->whereIn('no_id', $transfer->pluck('no_id')->filter())
            ->selectRaw('no_id, COUNT(*) as n')->groupBy('no_id')->pluck('n', 'no_id');

        return view('kas.index', compact('daftarBulan', 'bulan', 'transfer', 'q', 'tanggal', 'daftarTanggal', 'punyaBiaya', 'jumlahFoto',
            'statusBon', 'statusTransfer', 'ringkasStatus', 'filterStatus') + [
                'bolehInput' => $request->user()->bolehMenu('input-kas'), 'antreanSheet' => StatusReimburse::antreanSheet(),
            ]);
    }

    /** Pengeluaran (jumlah bon) per akun × bulan, bisa disaring per cost center. */
    public function rekap(Request $request): View
    {
        $daftarBulan = KasBulan::orderBy('bulan')->get(['id', 'lembar', 'bulan']);
        $costCenter = CostCenter::orderBy('kode')->get();
        $ccDipilih = $request->query('cc');

        $baris = DB::table('kas_bon as b')
            ->join('kas_transfer as t', 't.id', '=', 'b.kas_transfer_id')
            ->leftJoin('kode_gl as k', 'k.id', '=', 'b.kode_gl_id')
            ->leftJoin('akun_gl as a', 'a.id', '=', 'k.akun_gl_id')
            ->leftJoin('cost_center as c', 'c.id', '=', 'k.cost_center_id')
            ->when($ccDipilih === '-', fn ($q) => $q->whereNull('k.cost_center_id'))
            ->when($ccDipilih && $ccDipilih !== '-', fn ($q) => $q->where('c.kode', $ccDipilih))
            ->groupBy('a.kelompok', 'a.nama', 't.kas_bulan_id')
            ->select('a.kelompok', 'a.nama', 't.kas_bulan_id', DB::raw('SUM(b.nominal) as total'), DB::raw('COUNT(*) as jumlah'))
            ->get();

        // kelompok => akun => [kas_bulan_id => total]
        $matriks = [];
        foreach ($baris as $r) {
            $kelompok = $r->kelompok ?? 'Tanpa Kode GL';
            $akun = $r->nama ?? '(Kode GL kosong)';
            $matriks[$kelompok][$akun][$r->kas_bulan_id] = (int) $r->total;
        }
        $urutan = array_flip([...AkunGl::KELOMPOK, 'Tanpa Kode GL']);
        uksort($matriks, fn ($a, $b) => ($urutan[$a] ?? 99) <=> ($urutan[$b] ?? 99));
        foreach ($matriks as &$akun) {
            uasort($akun, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        }
        unset($akun);

        return view('kas.rekap', compact('daftarBulan', 'costCenter', 'ccDipilih', 'matriks'));
    }

    /** Semua Kode GL yang pernah ditulis di sheet beserta hasil urainya. */
    public function kodeGl(Request $request): View
    {
        $saring = $request->query('saring');
        $kode = KodeGl::with(['akun', 'costCenter'])
            ->withCount('bon')
            ->withSum('bon', 'nominal')
            ->when($saring === 'tanpa-cc', fn ($q) => $q->whereNull('cost_center_id'))
            ->get()
            ->sortBy([['akun.kelompok', 'asc'], ['akun.nama', 'asc'], ['costCenter.kode', 'asc'], ['bon_sum_nominal', 'desc']]);

        $tanpaKode = DB::table('kas_bon')->whereNull('kode_gl_id')->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(nominal),0) as total')->first();

        return view('kas.kode-gl', compact('kode', 'saring', 'tanpaKode'));
    }
}
