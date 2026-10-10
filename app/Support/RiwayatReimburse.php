<?php

namespace App\Support;

use App\Models\KasBulan;
use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * History Reimburse: transaksi Kas Harian yang sudah direimburse (tabel kas_sudah_reimburse), direkap per tanggal reimburse.
 * Rincian tiap ID diambil dari Kas Harian aplikasi; ID dari lembar yang belum diimpor (2025) hanya tampil ID-nya.
 */
class RiwayatReimburse
{
    /** Bulan (Y-m) yang punya data reimburse, terbaru dulu: [Y-m => [hari, transaksi]]. */
    public static function bulan(): Collection
    {
        return DB::table('kas_sudah_reimburse')->whereNotNull('tanggal_reimburse')
            ->selectRaw("DATE_FORMAT(tanggal_reimburse, '%Y-%m') as bln, COUNT(DISTINCT tanggal_reimburse) as hari, COUNT(*) as n")
            ->groupBy('bln')->orderByDesc('bln')->get()->keyBy('bln');
    }

    /**
     * Grup per tanggal reimburse (terbaru dulu), masing-masing dengan rincian transaksinya.
     * $bulan = 'Y-m' membatasi ke bulan itu; $cari menyaring rincian (ID, PIC, keterangan, Kode GL, tujuan) di semua bulan.
     */
    public static function grup(?string $bulan, string $cari = ''): Collection
    {
        $q = DB::table('kas_sudah_reimburse')->orderByDesc('tanggal_reimburse')->orderBy('id_transaksi');
        if ($cari === '' && $bulan) {
            $q->whereBetween('tanggal_reimburse', [Carbon::parse($bulan.'-01')->startOfMonth()->toDateString(), Carbon::parse($bulan.'-01')->endOfMonth()->toDateString()]);
        }
        $status = $q->get();
        $peta = self::petaKas();
        $kecil = mb_strtolower($cari);
        $nama = User::pluck('name', 'id');

        $baris = $status->map(fn ($s) => ['status' => $s, 'kas' => $peta[$s->id_transaksi] ?? null]);
        if ($cari !== '') {
            $baris = $baris->filter(fn ($r) => str_contains(mb_strtolower($r['status']->id_transaksi.' '.implode(' ', array_map('strval', $r['kas'] ?? []))), $kecil));
        }

        $tanggal = $baris->pluck('status.tanggal_reimburse')->filter()->unique()->all();
        $sumberFile = KasRiwayat::where('aksi', 'reimburse-validasi')->get(['isi'])
            ->filter(fn ($r) => in_array($r->isi['tanggal'] ?? null, $tanggal, true))
            ->groupBy(fn ($r) => $r->isi['tanggal'])->map(fn ($g) => $g->pluck('isi.sumber')->filter()->unique()->values()->all());

        return $baris->groupBy(fn ($r) => $r['status']->tanggal_reimburse ?? '')
            ->map(function (Collection $g, string $tgl) use ($nama, $sumberFile) {
                $rinci = $g->sortBy(fn ($r) => ($r['kas']['tanggal'] ?? '0').sprintf('%08d', $r['kas']['no_id'] ?? 0).$r['status']->id_transaksi)
                    ->map(fn ($r) => ['id' => $r['status']->id_transaksi, ...($r['kas'] ?? [])])->values();
                $aplikasi = $g->filter(fn ($r) => $r['status']->sumber === 'aplikasi');
                $gl = $rinci->filter(fn ($r) => isset($r['nominal']))->groupBy(fn ($r) => $r['akun'] ?: 'tanpa Kode GL')
                    ->map->sum('nominal')->sortDesc();

                return [
                    'tanggal' => $tgl !== '' ? Carbon::parse($tgl) : null,
                    'jumlah' => $rinci->count(),
                    'total' => (int) $rinci->sum('nominal'),
                    'tanpa_data' => $rinci->filter(fn ($r) => ! isset($r['nominal']))->count(),
                    'per_akun' => $gl,
                    'dari_sheet' => $g->count() - $aplikasi->count(),
                    'oleh' => $aplikasi->pluck('status.user_id')->filter()->unique()->map(fn ($id) => $nama[$id] ?? '?')->values()->all(),
                    'dicatat' => $aplikasi->pluck('status.created_at')->filter()->max(),
                    'file' => $tgl !== '' ? ($sumberFile[$tgl] ?? []) : [],
                    'menunggu_sheet' => $g->filter(fn ($r) => ! $r['status']->di_sheet)->count(),
                    'rinci' => $rinci->all(),
                ];
            })->values();
    }

    /**
     * ID transaksi kas → rincian, dari semua transaksi Kas Harian di aplikasi. Disimpan sampai ada impor baru.
     *
     * @return array<string, array{tanggal: string, no_id: ?int, tujuan: ?string, pic: ?string, ket: ?string, akun: ?string, kode_gl: ?string, no_mobil: ?string, nominal: int}>
     */
    public static function petaKas(): array
    {
        $versi = (string) KasBulan::max('diimpor_pada').'|'.KasBulan::count();

        return Cache::remember('peta-id-kas:'.md5($versi), now()->addDay(), function () {
            $peta = [];
            KasTransfer::with('bon.kodeGl.akun', 'bon.kodeGl.costCenter')->chunkById(1000, function ($daftar) use (&$peta) {
                foreach ($daftar as $t) {
                    $dasar = ['tanggal' => $t->tanggal->toDateString(), 'tujuan' => $t->nama_tujuan];
                    if ($t->bon->isEmpty()) {
                        $peta[$t->tanggal->format('ymd').'-Jago-'.$t->no_id] = $dasar + ['no_id' => $t->no_id, 'pic' => null, 'ket' => $t->keterangan,
                            'akun' => null, 'kode_gl' => null, 'no_mobil' => null, 'nominal' => (int) ($t->kredit ?: $t->debet)];

                        continue;
                    }
                    foreach ($t->bon as $b) {
                        $k = $b->kodeGl;
                        $peta[StatusReimburse::idBon($b, $t)] = $dasar + ['no_id' => (int) ($b->no_id ?: $t->no_id), 'pic' => $b->pic, 'ket' => $b->keterangan,
                            'akun' => $k?->akun?->nama ?? $k?->kode_asli, 'kode_gl' => $k ? trim(($k->akun?->nama ?? $k->kode_asli).' '.$k->costCenter?->kode.' '.$k->ref) : null,
                            'no_mobil' => $b->no_mobil, 'nominal' => (int) $b->nominal];
                    }
                }
            });

            return $peta;
        });
    }
}
