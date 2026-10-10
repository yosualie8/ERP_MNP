<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\User;
use App\Support\AktivitasAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Dashboard Aktivitas Admin: log kegiatan semua pengguna di aplikasi (tabel kas_riwayat), bisa disaring. */
class AktivitasAdminController extends Controller
{
    public const PERIODE = ['hari-ini' => 'Hari ini', '7-hari' => '7 hari', '30-hari' => '30 hari', 'semua' => 'Semua'];

    public function __invoke(Request $request): View
    {
        $periode = array_key_exists($request->query('periode'), self::PERIODE) ? $request->query('periode') : '7-hari';
        $pengguna = (int) $request->query('pengguna') ?: null;
        $area = in_array($request->query('area'), ['Kas Harian', 'Uang Jalan', 'Ritasi', 'Aset', 'ChatGPT'], true) ? $request->query('area') : null;
        $cari = trim((string) $request->query('q', ''));
        $sejak = match ($periode) {
            'hari-ini' => today(), '7-hari' => today()->subDays(6), '30-hari' => today()->subDays(29), default => null,
        };

        $log = KasRiwayat::with('user')
            ->when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->when($pengguna, fn ($q) => $q->where('user_id', $pengguna))
            ->when($area, fn ($q) => $q->whereIn('aksi', AktivitasAdmin::aksiArea($area)))
            ->when($cari !== '', fn ($q) => $q->where('ringkasan', 'like', "%{$cari}%"))
            ->latest('id')->paginate(100)->withQueryString();

        // Ringkasan per pengguna: terakhir aktif, jumlah aktivitas hari ini / 7 hari / semua.
        $hitung = KasRiwayat::selectRaw('user_id, MAX(created_at) as terakhir, COUNT(*) as semua,
                SUM(created_at >= ?) as hari_ini, SUM(created_at >= ?) as tujuh_hari', [today(), today()->subDays(6)])
            ->groupBy('user_id')->get()->keyBy('user_id');
        $orang = User::orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->id, 'nama' => $u->name ?? $u->email, 'super' => $u->isSuperAdmin(),
            'terakhir' => $hitung[$u->id]->terakhir ?? null, 'hari_ini' => (int) ($hitung[$u->id]->hari_ini ?? 0),
            'tujuh_hari' => (int) ($hitung[$u->id]->tujuh_hari ?? 0), 'semua' => (int) ($hitung[$u->id]->semua ?? 0),
        ])->sortByDesc('terakhir')->values();

        $perArea = KasRiwayat::when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->when($pengguna, fn ($q) => $q->where('user_id', $pengguna))
            ->select('aksi', DB::raw('COUNT(*) as n'))->groupBy('aksi')->get()
            ->groupBy(fn ($r) => AktivitasAdmin::area($r->aksi))->map->sum('n');

        // Jumlah aktivitas per pengguna pada periode & area terpilih (untuk saringan nama admin).
        $perOrang = KasRiwayat::when($sejak, fn ($q) => $q->where('created_at', '>=', $sejak))
            ->when($area, fn ($q) => $q->whereIn('aksi', AktivitasAdmin::aksiArea($area)))
            ->select('user_id', DB::raw('COUNT(*) as n'))->groupBy('user_id')->pluck('n', 'user_id');

        return view('dashboard.aktivitas', compact('log', 'orang', 'perArea', 'perOrang', 'periode', 'pengguna', 'area', 'cari'));
    }
}
