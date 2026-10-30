<?php

namespace App\Support;

use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Models\UjPengajuanDetail;
use App\Models\UjReimburse;
use App\Models\UjTransaksi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ringkasan Kas UJ untuk tool MCP ringkasan_uj (dulu data halaman Dashboard UJ, dihapus owner 30 Okt 2026): jumlah belum reimburse,
 * kapan terakhir diperbarui, pengajuan menunggu realisasi, dan 5 tanggal reimburse UJ terakhir.
 */
class RingkasUj
{
    public static function data(): array
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

        return [
            'belum' => $belum,
            'inputTerakhir' => $inputTerakhir ? ['waktu' => $inputTerakhir->created_at, 'oleh' => $nama[$inputTerakhir->user_id] ?? '?', 'ringkasan' => $inputTerakhir->ringkasan, 'aksi' => $inputTerakhir->aksi] : null,
            // Waktu impor terakhir (cache; hilang saat deploy → waktu baris Kas Seabank terakhir ditulis ulang).
            'sinkronTerakhir' => ($w = Cache::get('uj-diimpor-pada') ?? UjTransaksi::max('created_at')) ? Carbon::parse($w) : null,
            'transaksiTerbaru' => UjTransaksi::max('tanggal'),
            'pengajuanMenunggu' => UjPengajuanDetail::where('status', 'menunggu')->selectRaw('COUNT(*) n, COALESCE(SUM(nominal), 0) s')->first(),
            'riwayat' => $riwayat,
        ];
    }
}
