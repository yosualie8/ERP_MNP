<?php

namespace App\Support;

use App\Models\KasBulan;
use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ringkasan Kas Harian untuk tool MCP ringkasan_kas_harian (dulu data halaman Dashboard Kas Harian, dihapus owner 30 Okt 2026):
 * jumlah belum reimburse, antrean tulis sheet, kapan terakhir diperbarui, dan 5 pencatatan reimburse terakhir.
 */
class RingkasKas
{
    public static function data(): array
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

        $inputTerakhir = KasRiwayat::whereIn('aksi', ['tambah', 'ubah', 'hapus'])->latest('id')->first();

        // 5 pencatatan reimburse terakhir (bulan terbaru; ditambah bulan sebelumnya bila kurang dari 5).
        $riwayat = collect();
        foreach (RiwayatReimburse::bulan()->keys()->take(3) as $bln) {
            // Penyesuaian data lama bukan reimburse sungguhan: tidak ikut dihitung.
            $riwayat = $riwayat->concat(RiwayatReimburse::grup($bln)->reject(fn ($g) => $g['penyesuaian']));
            if ($riwayat->count() >= 5) {
                break;
            }
        }

        return [
            'belum' => $belum,
            'antreanSheet' => StatusReimburse::antreanSheet(),
            'inputTerakhir' => $inputTerakhir ? ['waktu' => $inputTerakhir->created_at, 'oleh' => $nama[$inputTerakhir->user_id] ?? '?', 'ringkasan' => $inputTerakhir->ringkasan, 'aksi' => $inputTerakhir->aksi] : null,
            'sinkronTerakhir' => ($w = KasBulan::max('diimpor_pada')) ? Carbon::parse($w) : null,
            'transaksiTerbaru' => KasTransfer::max('tanggal'),
            'riwayat' => $riwayat->take(5),
        ];
    }
}
