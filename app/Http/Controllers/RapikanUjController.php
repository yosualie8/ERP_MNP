<?php

namespace App\Http\Controllers;

use App\Models\AsetTruk;
use App\Models\UjDetail;
use App\Support\RapikanUj;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Rapikan Kas UJ: isi No Mobil yang kosong (pilih dari Data Aset) atau tandai "bukan truk"; ditulis ke sheet & aplikasi. */
class RapikanUjController extends Controller
{
    private const PER_HALAMAN = 200;

    public function index(Request $request): View
    {
        $abaikan = DB::table('uj_tanpa_mobil')->pluck('id_uj');
        $semua = UjDetail::where('biaya_transfer', false)->whereNull('no_mobil')->whereNotNull('kategori')
            ->where(fn ($q) => $q->whereNull('id_uj')->orWhereNotIn('id_uj', $abaikan))
            ->orderByDesc('tanggal')->orderByDesc('baris')->get();
        $kategori = $request->query('kategori');
        $baris = $kategori ? $semua->where('kategori', $kategori)->values() : $semua;
        $hal = max(1, $request->integer('hal', 1));
        $tampil = $baris->slice(($hal - 1) * self::PER_HALAMAN, self::PER_HALAMAN)->values();

        return view('uj.rapikan', [
            'baris' => $tampil, 'jumlah' => $baris->count(), 'semua' => $semua, 'kategori' => $kategori, 'hal' => $hal,
            'halaman' => (int) ceil($baris->count() / self::PER_HALAMAN), 'saran' => $this->saran($tampil),
            'aset' => AsetTruk::where('status', '!=', 'dijual')->orderBy('no_lambung')->get(['no_lambung', 'plat', 'jenis', 'driver_tetap']),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $aset = AsetTruk::pluck('jenis', 'no_lambung');
        $isian = collect((array) $request->input('mobil', []))->map(fn ($v) => strtoupper(trim(preg_replace('/\s+/', ' ', (string) $v))))->filter();
        $ubah = [];
        $bukan = [];
        $salah = [];
        $rows = UjDetail::whereIn('baris', $isian->keys())->whereNull('no_mobil')->get()->keyBy('baris');
        foreach ($isian as $n => $v) {
            $d = $rows[(int) $n] ?? null;
            if (! $d) {
                continue;
            }
            if ($v === 'BUKAN TRUK') {
                if ($d->id_uj) {
                    $bukan[] = $d->id_uj;
                }

                continue;
            }
            $dt = \App\Support\NomorMobil::rapikan($v);
            if (! isset($aset[$dt])) {
                $salah[] = "{$d->id_uj}: \"{$v}\" tidak ada di Data Aset";

                continue;
            }
            $ubah[(int) $n] = ['baris' => (int) $n, 'no_mobil' => $dt, ...($aset[$dt] ? ['jenis' => $aset[$dt]] : [])];
        }
        try {
            $hasil = RapikanUj::tulis($ubah, $request->user()->id, 'Rapikan Kas UJ: No Mobil diisi admin');
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        foreach ($bukan as $id) {
            DB::table('uj_tanpa_mobil')->updateOrInsert(['id_uj' => $id], ['user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with($salah || $hasil['dilewati'] ? 'error' : 'success',
            "No Mobil terisi {$hasil['ditulis']} baris (sheet Kas Seabank & aplikasi)".($bukan ? ', '.count($bukan).' ditandai bukan truk' : '').'.'
            .($salah ? ' Ditolak: '.implode('; ', array_slice($salah, 0, 5)).'.' : '')
            .($hasil['dilewati'] ? ' Dilewati (sheet berubah, klik Sinkron di Kas UJ lalu ulangi): '.count($hasil['dilewati']).' baris.' : ''));
    }

    /**
     * Saran No Mobil per baris: (1) transaksi yang sama hanya berisi satu DT, (2) DT yang dipakai driver itu ±14 hari,
     * (3) driver tetap di Data Aset.
     *
     * @return array<int, array{dt: string, asal: string}> baris sheet → saran
     */
    private function saran($baris): array
    {
        if ($baris->isEmpty()) {
            return [];
        }
        $kunci = fn ($n) => preg_replace('/[^a-z]/', '', strtolower((string) $n));
        $perTransaksi = UjDetail::whereIn('uj_transaksi_id', $baris->pluck('uj_transaksi_id')->unique())->whereNotNull('no_mobil')
            ->get(['uj_transaksi_id', 'no_mobil'])->groupBy('uj_transaksi_id')->map(fn ($g) => $g->pluck('no_mobil')->unique()->values());
        $riwayat = UjDetail::whereNotNull('no_mobil')->whereNotNull('nama')
            ->whereBetween('tanggal', [$baris->min('tanggal')->copy()->subDays(14), $baris->max('tanggal')->copy()->addDays(14)])
            ->get(['nama', 'no_mobil', 'tanggal'])->groupBy(fn ($u) => $kunci($u->nama));
        $tetap = AsetTruk::whereNotNull('driver_tetap')->where('status', '!=', 'dijual')->get()->mapWithKeys(fn ($a) => [$kunci($a->driver_tetap) => $a->no_lambung]);

        $hasil = [];
        foreach ($baris as $d) {
            if (($t = $perTransaksi[$d->uj_transaksi_id] ?? null) && $t->count() === 1) {
                $hasil[$d->baris] = ['dt' => $t[0], 'asal' => 'transaksi yang sama'];

                continue;
            }
            $tgl = Carbon::parse($d->tanggal);
            $dekat = ($riwayat[$kunci($d->nama)] ?? collect())->filter(fn ($u) => abs($u->tanggal->diffInDays($tgl, false)) <= 14);
            if ($dekat->isNotEmpty()) {
                $hitung = $dekat->countBy('no_mobil')->sortDesc();
                $hasil[$d->baris] = ['dt' => $hitung->keys()->first(), 'asal' => "driver {$d->nama} ±14 hari ({$hitung->first()}×".($hitung->count() > 1 ? ', ada DT lain' : '').')'];

                continue;
            }
            if ($d->nama && ($dt = $tetap[$kunci($d->nama)] ?? null)) {
                $hasil[$d->baris] = ['dt' => $dt, 'asal' => "driver tetap {$dt}"];
            }
        }

        return $hasil;
    }
}
