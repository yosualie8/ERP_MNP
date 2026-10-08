<?php

namespace App\Console\Commands;

use App\Models\AsetTruk;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Isi awal Data Aset (SEKALI): truk bernomor "DT 000" yang mayoritas ritnya atas nama MNP, atau yang hanya muncul di Kas UJ.
 * Plat & jenis = yang paling sering tercatat; status dari aktivitas 30 hari terakhir. Sesudah itu Data Aset diatur di aplikasi.
 */
class AsetAwal extends Command
{
    protected $signature = 'mnp:aset-awal {--paksa : Tetap jalan walau Data Aset sudah berisi (hanya menambah yang belum ada)}';

    protected $description = 'Isi awal Data Aset truk MNP dari Ritasi & Kas UJ (sekali)';

    public function handle(): int
    {
        if (AsetTruk::exists() && ! $this->option('paksa')) {
            $this->error('Data Aset sudah berisi. Pakai --paksa untuk menambah truk yang belum terdaftar saja.');

            return self::FAILURE;
        }
        $plat = fn ($p) => ($p = trim(preg_replace('/\s+/', ' ', strtoupper(str_replace(['`', "'"], '', (string) $p))))) === '' ? null : $p;
        $mode = fn ($c) => collect($c)->filter()->countBy()->sortDesc();
        $rit = DB::table('ritasi')->where('no_lambung', 'like', 'DT %')->get(['no_lambung', 'plat', 'no_polisi', 'jenis_kendaraan', 'pemilik', 'tanggal'])->groupBy('no_lambung');
        $uj = DB::table('uj_detail')->where('no_mobil', 'like', 'DT %')->where('biaya_transfer', 0)->get(['no_mobil', 'nama', 'tanggal', 'jenis_kendaraan'])->groupBy('no_mobil');
        $batas = now()->subDays(30)->toDateString();

        $semua = $rit->keys()->merge($uj->keys())->filter(fn ($d) => preg_match('/^DT \d{3}$/', $d))->unique()->sort()->values();
        $baru = [];
        foreach ($semua as $dt) {
            $r = $rit->get($dt, collect());
            $u = $uj->get($dt, collect());
            if ($r->isNotEmpty() && $mode($r->pluck('pemilik'))->keys()->first() !== 'MNP') {
                continue; // truk mitra
            }
            $terakhir = collect([$r->max('tanggal'), $u->max('tanggal')])->filter()->max();
            $baru[$dt] = [
                'plat' => $mode($r->map(fn ($x) => $plat($x->plat ?: explode('/', (string) $x->no_polisi)[0])))->keys()->first(),
                'jenis' => $mode($r->pluck('jenis_kendaraan')->merge($u->pluck('jenis_kendaraan')))->keys()->first(),
                'status' => $terakhir && $terakhir >= $batas ? 'aktif' : 'tidak_aktif',
                'driver_tetap' => $mode($u->where('tanggal', '>=', now()->subDays(60)->toDateString())->pluck('nama')->map(fn ($n) => ucwords(strtolower(trim((string) $n)))))->keys()->first(),
                'catatan' => trim(($r->isEmpty() ? 'Hanya tercatat di Kas UJ (UJ terakhir '.Carbon::parse($u->max('tanggal'))->translatedFormat('j M Y').'); plat belum diketahui. ' : '')
                    .'Diisi otomatis dari data ritasi & Kas UJ '.now()->translatedFormat('j M Y').' — cocokkan dengan STNK.'),
            ];
        }
        // Plat yang sama di dua nomor DT → beri catatan.
        foreach (collect($baru)->filter(fn ($a) => $a['plat'])->groupBy('plat') as $p => $g) {
            if ($g->count() > 1) {
                $dts = collect($baru)->filter(fn ($a) => $a['plat'] === $p)->keys();
                foreach ($dts as $dt) {
                    $baru[$dt]['catatan'] = "Plat {$p} juga tercatat di ".$dts->reject(fn ($x) => $x === $dt)->implode(', ').' — cek STNK. '.$baru[$dt]['catatan'];
                }
            }
        }
        $n = 0;
        foreach ($baru as $dt => $a) {
            if (! AsetTruk::where('no_lambung', $dt)->exists()) {
                AsetTruk::create(['no_lambung' => $dt, ...$a]);
                $n++;
            }
        }
        $this->info("Data Aset: {$n} truk ditambahkan (".collect($baru)->where('status', 'aktif')->count().' aktif).');

        return self::SUCCESS;
    }
}
