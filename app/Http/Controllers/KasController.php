<?php

namespace App\Http\Controllers;

use App\Jobs\BuatExcelKas;
use App\Models\AkunGl;
use App\Models\CostCenter;
use App\Models\KasBulan;
use App\Models\KasTransfer;
use App\Models\KasFoto;
use App\Models\KodeGl;
use App\Support\Periode;
use App\Support\StatusReimburse;
use App\Support\TebakKodeGl;
use App\Support\TulisKasSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KasController extends Controller
{
    /** Buku rekening per periode (tahun, bulan mulai s.d. akhir): transfer + rincian bon, saldo berjalan seperti lembar bulanan. */
    public function index(Request $request): View
    {
        return view('kas.index', $this->data($request) + [
            'bolehInput' => $request->user()->bolehMenu('input-kas'), 'antreanSheet' => StatusReimburse::antreanSheet(),
        ]);
    }

    /**
     * Download Excel isi grid Kas Harian (owner 6 Nov 2026): periode, tanggal, pencarian & status reimburse dari URL (sama dengan halaman);
     * saringan/urutan per kolom di browser dikirim sebagai daftar transfer ("t") & detail ("b") yang tampil (teks dipisah koma — ribuan baris
     * melewati max_input_vars bila array). File dibuat SESUDAH respons (data setahun ±30 detik) — browser memantau kemajuannya (status) lalu mengambil file.
     */
    public function unduh(Request $request): JsonResponse
    {
        $request->validate(['t' => ['nullable', 'string', 'regex:/^[\d,]*$/'], 'b' => ['nullable', 'string', 'regex:/^[\d,]*$/'], 'saring_detail' => ['nullable', 'boolean']]);
        $ids = fn (string $k) => array_map('intval', array_values(array_filter(explode(',', (string) $request->input($k)), 'strlen')));
        // Satu akun satu unduhan berjalan; file lama (> 1 jam) dibersihkan.
        $kunciAkun = 'kas-unduh-akun:'.$request->user()->id;
        if (($lama = Cache::get($kunciAkun)) && ($x = Cache::get(BuatExcelKas::kunci($lama))) && empty($x['siap']) && empty($x['galat'])) {
            return response()->json(['token' => $lama, 'lanjut' => true]);
        }
        foreach (glob(BuatExcelKas::folder().'/*.xlsx') ?: [] as $f) {
            if (filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
        $token = Str::random(32);
        Cache::put($kunciAkun, $token, now()->addMinutes(BuatExcelKas::MENIT_SIMPAN));
        $q = $request->query();
        BuatExcelKas::lapor($token, ['persen' => 0, 'pesan' => 'Memulai…', 'user' => $request->user()->id,
            'nama' => 'Kas Harian '.Periode::dari($request, KasBulan::orderBy('bulan')->get()->map(fn ($b) => $b->bulan->format('Y-m')))->label()
                .(($q['tgl'] ?? null) ? ' tgl '.$q['tgl'] : '').(($q['q'] ?? '') !== '' ? ' cari '.mb_substr(preg_replace('/[^\pL\pN ]+/u', '', $q['q']), 0, 30) : '')
                .' - '.now()->format('Y-m-d H.i').'.xlsx']);
        BuatExcelKas::dispatchAfterResponse($token, $request->user()->id, $q, $request->has('t') ? $ids('t') : null, $request->boolean('saring_detail') ? $ids('b') : null);

        return response()->json(['token' => $token]);
    }

    public function unduhStatus(Request $request, string $token): JsonResponse
    {
        $x = Cache::get(BuatExcelKas::kunci($token));
        abort_unless($x && ($x['user'] ?? null) === $request->user()->id, 404);

        return response()->json(collect($x)->only(['persen', 'pesan', 'siap', 'galat'])->all()
            + (! empty($x['siap']) ? ['url' => route('kas.unduh.ambil', $token)] : []));
    }

    public function unduhAmbil(Request $request, string $token): BinaryFileResponse
    {
        $x = Cache::get(BuatExcelKas::kunci($token));
        abort_unless($x && ($x['user'] ?? null) === $request->user()->id && ! empty($x['siap']) && is_file($x['file'] ?? ''), 404);
        Cache::forget(BuatExcelKas::kunci($token));

        return response()->download($x['file'], $x['nama'])->deleteFileAfterSend();
    }

    /** Data halaman Kas Harian (dipakai halaman & Excel). */
    public function data(Request $request): array
    {
        $daftarBulan = KasBulan::orderBy('bulan')->get();
        $periode = Periode::dari($request, $daftarBulan->map(fn ($b) => $b->bulan->format('Y-m')));
        // Lembar bulanan di dalam periode (urut lama → baru); $bulan = lembar terakhir (untuk satu bulan: lembar itu sendiri).
        $lembar = $daftarBulan->filter(fn ($b) => $b->bulan->between($periode->awal(), $periode->akhir()))->values();
        $bulan = $lembar->last();
        $q = trim((string) $request->query('q'));
        // Saring per tanggal hanya bila periodenya satu bulan.
        $tanggal = $periode->tunggal() ? ($request->integer('tgl') ?: null) : null;

        $transfer = collect();
        $daftarTanggal = collect();
        if ($bulan) {
            $urutLembar = $lembar->pluck('bulan', 'id')->map(fn ($b) => $b->format('Ym'));
            $daftarTanggal = $periode->tunggal() ? KasTransfer::where('kas_bulan_id', $bulan->id)->distinct()->orderBy('tanggal')->pluck('tanggal') : collect();
            $transfer = KasTransfer::whereIn('kas_bulan_id', $lembar->pluck('id'))
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
                ->get()
                // Terbaru di atas: lembar terbaru dulu, lalu baris paling bawah di sheet (= yang terakhir diinput).
                ->sortByDesc(fn ($t) => $urutLembar[$t->kas_bulan_id].sprintf('%07d', $t->baris))->values();
        }

        // Transfer yang tepat di bawahnya ada baris "Biaya Transfer Keluar" miliknya (ditanyakan saat menghapus).
        $punyaBiaya = [];
        $biayaMilik = []; // id baris Biaya Transfer Keluar → NO ID transfernya (jamnya ikut transfer itu)
        foreach ($lembar as $lb) {
            $semua = KasTransfer::where('kas_bulan_id', $lb->id)->withMax('bon', 'baris')->withCount('bon')
                ->orderBy('baris')->get(['id', 'baris', 'kredit', 'keterangan', 'no_id'])->values();
            foreach ($semua as $i => $t) {
                $berikut = $semua[$i + 1] ?? null;
                if ($berikut && $berikut->kredit > 0 && $berikut->kredit <= TulisKasSheet::BIAYA_TRANSFER_MAKS &&$berikut->bon_count <= 1
                    && stripos((string) $berikut->keterangan, 'biaya transfer') !== false
                    && stripos((string) $t->keterangan, 'biaya transfer') === false
                    && $berikut->baris === max($t->baris, (int) $t->bon_max_baris) + 1) {
                    $punyaBiaya[$t->id] = true;
                    $biayaMilik[$berikut->id] = $t->no_id;
                }
            }
        }

        // Jam:menit transaksi dari mutasi Jago yang menjadi masternya (owner 1 Nov 2026); biaya transfer ikut jam transfernya.
        $noIdJam = $transfer->map(fn ($t) => $biayaMilik[$t->id] ?? $t->no_id)->filter()->unique();
        $jamNoId = \App\Models\JagoMutasi::where('kantong', 'kas')->whereIn('dipakai', $noIdJam->map(fn ($n) => "kas:{$n}"))->get(['dipakai', 'waktu'])
            ->mapWithKeys(fn ($m) => [(int) substr($m->dipakai, 4) => $m->waktu->format('H:i')]);
        $jam = $transfer->mapWithKeys(fn ($t) => [$t->id => $jamNoId[$biayaMilik[$t->id] ?? $t->no_id] ?? null])->filter()->all();

        // Status reimburse per detail (lembar "Sudah Reimburse") dan per transfer: sudah semua / sebagian / belum.
        $sudah = StatusReimburse::untuk($transfer->flatMap(fn ($t) => $t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t)))->all());
        $statusBon = [];
        $statusTransfer = [];
        // Lembar arsip (Kas Harian 2024–2025): tanpa status reimburse, hanya dilihat.
        $bulanArsip = KasBulan::where('arsip', true)->pluck('id')->flip();
        foreach ($transfer as $t) {
            if (isset($bulanArsip[$t->kas_bulan_id])) {
                $statusTransfer[$t->id] = ['kode' => 'arsip'];

                continue;
            }
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

        // Ringkasan periode: saldo awal lembar pertama, jumlah masuk/keluar, saldo akhir lembar terakhir, catatan rekonsiliasi semua lembar.
        $ringkas = $bulan ? [
            'saldo_awal' => $lembar->first()->saldo_awal, 'masuk' => $lembar->sum('total_debet'), 'keluar' => $lembar->sum('total_kredit'),
            'saldo_akhir' => $bulan->saldo_akhir, 'cocok' => $lembar->every(fn ($b) => $b->cocok()),
            'catatan' => $lembar->flatMap(fn ($b) => collect($b->catatan ?? [])->map(fn ($c) => ($lembar->count() > 1 ? $b->bulan->translatedFormat('M Y').': ' : '').$c))->all(),
            'tidak_cocok' => $lembar->reject(fn ($b) => $b->cocok())->count(),
        ] : null;
        $namaLembar = $lembar->pluck('lembar', 'id');

        // Belum reimburse 90 hari terakhir (owner 1 Nov 2026): TIDAK mengikuti pilihan periode/bulan di atas, lembar arsip tidak dihitung.
        $dari90 = today()->subDays(89);
        $kas90 = KasTransfer::whereDate('tanggal', '>=', $dari90)->whereNotIn('kas_bulan_id', $bulanArsip->keys())->with('bon')->get()
            ->filter(fn ($t) => $t->bon->isNotEmpty());
        $sudah90 = StatusReimburse::untuk($kas90->flatMap(fn ($t) => $t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t)))->all());
        $nilaiBelum90 = $kas90->map(fn ($t) => (int) $t->bon->reject(fn ($b) => array_key_exists(StatusReimburse::idBon($b, $t), $sudah90))->sum('nominal'));
        $belum90 = ['dari' => $dari90, 'nilai' => (int) $nilaiBelum90->sum(), 'transfer' => $nilaiBelum90->filter()->count()];

        return compact('daftarBulan', 'bulan', 'transfer', 'q', 'tanggal', 'daftarTanggal', 'punyaBiaya', 'jumlahFoto', 'jam',
            'statusBon', 'statusTransfer', 'ringkasStatus', 'filterStatus', 'periode', 'lembar', 'ringkas', 'namaLembar', 'belum90');
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
            ->withCount('transferMasuk')
            ->withSum('transferMasuk', 'debet')
            ->when($saring === 'tanpa-cc', fn ($q) => $q->whereNull('cost_center_id'))
            ->get()
            ->sortBy([['akun.kelompok', 'asc'], ['akun.nama', 'asc'], ['costCenter.kode', 'asc'], ['bon_sum_nominal', 'desc']]);

        $tanpaKode = DB::table('kas_bon')->whereNull('kode_gl_id')->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(nominal),0) as total')->first();

        // Talangan: uang masuk Penerimaan Talangan dikurangi Pengembalian Talangan = sisa yang belum dikembalikan kas.
        $talangan = [
            'terima' => (int) KasTransfer::whereHas('kodeGl.akun', fn ($q) => $q->where('nama', TebakKodeGl::TERIMA_TALANGAN))->sum('debet'),
            'kembali' => (int) DB::table('kas_bon')->join('kode_gl', 'kode_gl.id', '=', 'kas_bon.kode_gl_id')->join('akun_gl', 'akun_gl.id', '=', 'kode_gl.akun_gl_id')
                ->where('akun_gl.nama', TebakKodeGl::TALANGAN)->sum('kas_bon.nominal'),
        ];

        return view('kas.kode-gl', compact('kode', 'saring', 'tanpaKode', 'talangan'));
    }
}
