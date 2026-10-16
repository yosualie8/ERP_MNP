<?php

namespace App\Http\Controllers;

use App\Models\ProyekTahap;
use App\Support\Anggaran;
use App\Support\DaftarProyek;
use App\Support\Overhead;
use Illuminate\View\View;

/**
 * Dashboard Progres Proyek: tahap berstatus Progress — persen progres (fisik: kubikasi bongkaran ÷ m³ HPP tanah; bila belum
 * ada baris tanah m³: serapan biaya), biaya yang sudah keluar (realisasi HPP) & rinciannya per komponen.
 */
class DashboardProyekController extends Controller
{
    public function __invoke(): View
    {
        [$ritasi, $kas, $bbm] = [DaftarProyek::ritasi(), DaftarProyek::kas(), DaftarProyek::bbm()];
        $overhead = Overhead::teralokasi();
        $rincianOh = Overhead::rincianTeralokasi();
        $tahap = ProyekTahap::with(['proyek', 'anggaran.komponen'])->where('status', 'progress')->whereNull('pos_overhead')->where('tipe', '!=', 'biaya')->get();
        // Transaksi Kas Harian yang jelas untuk tahap ini (alokasi / Kode GL tahap), tanpa akun yang diabaikan (HPP ritasi, BBM, sparepart, DT).
        $ids = $tahap->pluck('id')->all();
        $trx = DaftarProyek::queryKas()
            ->select('b.no_id', 'b.tanggal', 'b.nominal', 'b.pic', 'b.keterangan', 'k.kode_asli', 'c.kode as cc', 'k.ref', 'a.nama as akun', 'a.kelompok as kel', 'al.proyek_tahap_id as alokasi')
            ->orderByDesc('b.tanggal')->orderByDesc('b.no_id')->get()
            ->map(fn ($r) => tap($r, fn ($r) => $r->tahap = $r->alokasi ?: DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10))))
            ->filter(fn ($r) => in_array($r->tahap, $ids, true) && ! Overhead::diabaikan($r->kel, $r->akun))
            ->groupBy('tahap');
        $baris = $tahap
            ->map(function ($t) use ($ritasi, $kas, $bbm, $overhead, $rincianOh, $trx) {
                $oh = $overhead[$t->id] ?? 0;
                $h = Anggaran::hitung($t->anggaran, $t);
                $realisasi = array_sum(array_column(Anggaran::rincianRealisasi($t, $ritasi, $kas, $bbm), 1)) + $oh;
                $volTanah = Anggaran::volumeTanah($t->anggaran);
                $m3 = $ritasi['per_tahap'][$t->id]['m3'] ?? 0;
                $r = $ritasi['per_tahap'][$t->id] ?? [];

                return (object) [
                    't' => $t, 'ada' => $t->anggaran->isNotEmpty(), 'hpp' => $h['hpp'], 'omset' => $h['omset'], 'realisasi' => $realisasi,
                    'fisik' => $volTanah > 0 ? $m3 / $volTanah * 100 : null, 'm3' => $m3, 'volTanah' => $volTanah,
                    'serapan' => $h['hpp'] > 0 ? $realisasi / $h['hpp'] * 100 : null,
                    'terakhir' => max($r['selesai'] ?? '', $bbm['per_tahap'][$t->id]['selesai'] ?? '', $kas['per_tahap'][$t->id]['selesai'] ?? '') ?: null,
                    'rincian' => Anggaran::realisasiKomponen($t, $t->anggaran, $ritasi, $bbm, $oh),
                    'kas' => $trx[$t->id] ?? collect(),
                    'overheadBulan' => $rincianOh[$t->id] ?? [],
                ];
            })
            ->sortBy(fn ($x) => [$x->t->proyek->urutan, (int) preg_replace('/\D/', '', $x->t->nama) ?: 99999, $x->t->nama])->values();

        return view('dashboard.proyek', ['baris' => $baris]);
    }
}
