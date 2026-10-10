<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Models\UjPengajuanDetail;
use App\Models\UjReimburse;
use App\Models\UjTransaksi;
use App\Models\User;
use App\Support\ReimburseUj;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Dashboard UJ: kapan Kas UJ (lembar Kas Seabank) & reimburse UJ terakhir diperbarui — jumlah yang belum reimburse,
 * transaksi UJ terakhir (urut No UJ: diketik di sheet maupun lewat aplikasi), dan 5 reimburse UJ terakhir.
 */
class DashboardUjController extends Controller
{
    public function __invoke(): View
    {
        $nama = User::pluck('name', 'id');

        $antrean = ReimburseUj::antrean();
        $detailBelum = $antrean->flatMap(fn ($t) => $t['detail']);
        $belum = [
            'transfer' => $antrean->count(),
            'detail' => $detailBelum->count(),
            'total' => (int) $detailBelum->sum('nominal'),
            'tertua' => $antrean->min('tanggal'),
            'per_bulan' => $antrean->groupBy(fn ($t) => substr((string) $t['tanggal'], 0, 7))->sortKeysDesc()
                ->map(fn ($g) => ['detail' => $g->sum(fn ($t) => count($t['detail'])), 'total' => (int) $g->sum('total')]),
        ];

        // Transaksi UJ terakhir menurut No UJ (nomor urut transfer di lembar Kas Seabank).
        $terakhir = UjTransaksi::whereNotNull('no_uj')->orderByDesc('no_uj')->with('detail')->limit(15)->get();
        $lewatAplikasi = [];
        foreach (KasRiwayat::whereIn('aksi', ['uj-tambah', 'uj-ubah'])->where('created_at', '>=', now()->subDays(60))->orderBy('id')->get(['aksi', 'isi', 'user_id', 'created_at']) as $r) {
            foreach ((array) ($r->isi['id_uj'] ?? []) as $id) {
                $lewatAplikasi[$id] = ['aksi' => $r->aksi, 'oleh' => $nama[$r->user_id] ?? '?', 'waktu' => $r->created_at];
            }
        }

        $inputTerakhir = KasRiwayat::whereIn('aksi', ['uj-tambah', 'uj-ubah', 'uj-hapus'])->latest('id')->first();

        // 5 tanggal reimburse UJ terakhir (kolom Tanggal Reimburse Kas Seabank), dengan pencatatannya di aplikasi bila ada.
        $reimburse = UjDetail::whereNotNull('tanggal_reimburse')->selectRaw('tanggal_reimburse, COUNT(*) as n, SUM(nominal) as total, COUNT(DISTINCT uj_transaksi_id) as transfer')
            ->groupBy('tanggal_reimburse')->orderByDesc('tanggal_reimburse')->limit(5)->get();
        $batch = UjReimburse::whereIn('tanggal', $reimburse->pluck('tanggal_reimburse')->map(fn ($t) => Carbon::parse($t)->toDateString()))->orderBy('id')->get()
            ->groupBy(fn ($b) => $b->tanggal->toDateString());
        $riwayat = $reimburse->map(fn ($r) => [
            'tanggal' => Carbon::parse($r->tanggal_reimburse),
            'jumlah' => (int) $r->n, 'transfer' => (int) $r->transfer, 'total' => (int) $r->total,
            'batch' => ($batch[Carbon::parse($r->tanggal_reimburse)->toDateString()] ?? collect())->map(fn ($b) => [
                'oleh' => $nama[$b->user_id] ?? '?', 'waktu' => $b->created_at,
                'catatan' => 'Reimburse UJ '.rp($b->total)." ({$b->jumlah_transfer} transfer, {$b->jumlah_baris} baris)".($b->target ? ' · target '.rp($b->target) : ''),
            ])->all(),
        ]);

        return view('dashboard.uj', [
            'belum' => $belum,
            'terakhir' => $terakhir,
            'lewatAplikasi' => $lewatAplikasi,
            'inputTerakhir' => $inputTerakhir ? ['waktu' => $inputTerakhir->created_at, 'oleh' => $nama[$inputTerakhir->user_id] ?? '?', 'ringkasan' => $inputTerakhir->ringkasan, 'aksi' => $inputTerakhir->aksi] : null,
            'sinkronTerakhir' => ($w = Cache::get('uj-diimpor-pada')) ? Carbon::parse($w) : null,
            'transaksiTerbaru' => UjTransaksi::max('tanggal'),
            'pengajuanMenunggu' => UjPengajuanDetail::where('status', 'menunggu')->selectRaw('COUNT(*) n, COALESCE(SUM(nominal), 0) s')->first(),
            'riwayat' => $riwayat,
        ]);
    }
}
