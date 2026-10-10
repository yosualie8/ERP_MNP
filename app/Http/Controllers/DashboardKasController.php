<?php

namespace App\Http\Controllers;

use App\Models\KasBulan;
use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\User;
use App\Support\ReimburseKas;
use App\Support\RiwayatReimburse;
use App\Support\StatusReimburse;
use App\Support\TulisKasSheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Dashboard Kas Harian: untuk melihat kapan data Kas Harian & reimburse terakhir diperbarui — jumlah yang belum reimburse,
 * transaksi Kas Harian terakhir (urut NO ID: yang diketik di sheet maupun lewat aplikasi), dan 5 pencatatan reimburse terakhir.
 */
class DashboardKasController extends Controller
{
    public function __invoke(): View
    {
        $nama = User::pluck('name', 'id');

        // Belum reimburse: per transaksi detail (biaya transfer ikut transfer induknya).
        $antrean = ReimburseKas::antrean();
        $detailBelum = $antrean->flatMap(fn ($t) => $t['detail']);
        $belum = [
            'transfer' => $antrean->count(),
            'detail' => $detailBelum->count(),
            'total' => (int) $detailBelum->sum('nominal'),
            'tertua' => $antrean->min('tanggal'),
            'per_bulan' => $antrean->groupBy(fn ($t) => substr($t['tanggal'], 0, 7))->sortKeysDesc()
                ->map(fn ($g) => ['detail' => $g->sum(fn ($t) => count($t['detail'])), 'total' => (int) $g->sum('total')]),
        ];

        // Transaksi terakhir menurut NO ID (nomor urut tiap baris baru, dari sheet maupun aplikasi).
        // Baris "Biaya Transfer Keluar" tidak ditampilkan sendiri: nominalnya ditempelkan ke transfer di atasnya.
        $biaya = fn (KasTransfer $t) => stripos((string) $t->keterangan, 'biaya transfer') !== false && $t->kredit <= TulisKasSheet::BIAYA_TRANSFER_MAKS;
        $terakhir = collect();
        $biayaTertunda = 0;
        foreach (KasTransfer::whereNotNull('no_id')->orderByDesc('no_id')->with('bon')->limit(60)->get() as $t) {
            if ($biaya($t)) {
                $biayaTertunda += $t->kredit;

                continue;
            }
            $t->setAttribute('biaya_transfer', $biayaTertunda);
            $biayaTertunda = 0;
            $terakhir->push($t);
            if ($terakhir->count() >= 15) {
                break;
            }
        }
        $lewatAplikasi = [];
        foreach (KasRiwayat::whereIn('aksi', ['tambah', 'ubah'])->where('created_at', '>=', now()->subDays(60))->orderBy('id')->get(['aksi', 'isi', 'user_id', 'created_at']) as $r) {
            foreach ((array) ($r->isi['no_id'] ?? []) as $no) {
                $lewatAplikasi[(int) $no] = ['aksi' => $r->aksi, 'oleh' => $nama[$r->user_id] ?? '?', 'waktu' => $r->created_at];
            }
        }
        $status = StatusReimburse::untuk($terakhir->flatMap(fn ($t) => $t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t)))->all());

        // Kapan terakhir diperbarui.
        $inputTerakhir = KasRiwayat::whereIn('aksi', ['tambah', 'ubah', 'hapus'])->latest('id')->first();
        $reimburseTerakhir = DB::table('kas_sudah_reimburse')->where('sumber', 'aplikasi')->orderByDesc('created_at')->first();

        // 5 pencatatan reimburse terakhir (bulan terbaru; ditambah bulan sebelumnya bila kurang dari 5).
        $riwayat = collect();
        foreach (RiwayatReimburse::bulan()->keys()->take(3) as $bln) {
            $riwayat = $riwayat->concat(RiwayatReimburse::grup($bln));
            if ($riwayat->count() >= 5) {
                break;
            }
        }

        return view('dashboard.kas', [
            'belum' => $belum,
            'antreanSheet' => StatusReimburse::antreanSheet(),
            'terakhir' => $terakhir,
            'lewatAplikasi' => $lewatAplikasi,
            'status' => $status,
            'inputTerakhir' => $inputTerakhir ? ['waktu' => $inputTerakhir->created_at, 'oleh' => $nama[$inputTerakhir->user_id] ?? '?', 'ringkasan' => $inputTerakhir->ringkasan, 'aksi' => $inputTerakhir->aksi] : null,
            'sinkronTerakhir' => ($w = KasBulan::max('diimpor_pada')) ? Carbon::parse($w) : null,
            'transaksiTerbaru' => KasTransfer::max('tanggal'),
            'reimburseTerakhir' => $reimburseTerakhir ? ['waktu' => Carbon::parse($reimburseTerakhir->created_at), 'tanggal' => Carbon::parse(DB::table('kas_sudah_reimburse')->max('tanggal_reimburse')), 'oleh' => $nama[$reimburseTerakhir->user_id] ?? '?'] : null,
            'riwayat' => $riwayat->take(5),
        ]);
    }
}
