<?php

namespace App\Http\Controllers;

use App\Models\MuatanKurang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Menu Ritasi → Muatan Kurang (MK) (owner 11 Nov 2026): daftar MK per surat jalan + tandai MK untuk rit yang sudah tercatat
 * (Kendal dari LS, atau yang terlewat saat Simpan bongkar di Monitor Ritasi). Lihat App\Models\MuatanKurang.
 */
class MuatanKurangController extends Controller
{
    public function index(Request $request): View
    {
        $cari = trim((string) $request->query('cari', ''));
        $status = $request->query('status', 'semua');
        $daftar = MuatanKurang::with('user:id,name')->orderByDesc('tanggal')->orderByDesc('id')
            ->when($status === 'belum', fn ($q) => $q->whereNull('pakai_id'))
            ->when($status === 'dipotong', fn ($q) => $q->whereNotNull('pakai_id'))
            ->limit(500)->get();

        return view('ritasi.muatan-kurang', [
            'cari' => $cari,
            'status' => $status,
            'calon' => $cari !== '' ? MuatanKurang::cariRit($cari) : collect(),
            'daftar' => $daftar,
            'bolehNominal' => MuatanKurang::bolehUbahNominal($request->user()),
        ]);
    }

    public function tandai(Request $request): RedirectResponse
    {
        $data = $request->validate(['no_seri' => ['required', 'string', 'max:100'], 'nominal' => ['nullable', 'string', 'max:20'], 'alasan' => ['nullable', 'string', 'max:250']]);
        $rit = MuatanKurang::cariRit($data['no_seri'])->first(fn ($r) => MuatanKurang::kunciSeri($r['no_seri']) === MuatanKurang::kunciSeri($data['no_seri']));
        if (! $rit) {
            return back()->with('error', "Surat jalan {$data['no_seri']} tidak ditemukan di Ritasi / rit menunggu uang jalan.");
        }
        $nominal = $this->nominal($request, $data['nominal'] ?? null);
        $mk = MuatanKurang::tandai($rit, $nominal, $data['alasan'] ?? null, $request->user()->id);
        if (is_string($mk)) {
            return back()->with('error', $mk);
        }
        if (! $mk->wasRecentlyCreated) {
            return back()->with('error', "Surat jalan {$rit['no_seri']} sudah ditandai MK sebelumnya (Rp".rp($mk->nominal).').');
        }

        return redirect()->route('mk.index')->with('success', "MK surat jalan {$mk->no_seri} ({$mk->sopir}) Rp".rp($mk->nominal)
            .' tercatat — hutang sopir bertambah, dipotong dari uang jalan berikutnya (diusulkan otomatis di Input UJ).');
    }

    public function ubah(Request $request, MuatanKurang $mk): RedirectResponse
    {
        if (! MuatanKurang::bolehUbahNominal($request->user())) {
            return back()->with('error', 'Nominal MK hanya bisa diubah Valen, Eddy & Super Admin.');
        }
        $nominal = (int) preg_replace('/\D/', '', (string) $request->input('nominal'));
        if ($salah = $mk->ubahNominal($nominal, $request->user()->id)) {
            return back()->with('error', $salah);
        }

        return back()->with('success', "Nominal MK surat jalan {$mk->no_seri} jadi Rp".rp($nominal).'.');
    }

    public function hapus(Request $request, MuatanKurang $mk): RedirectResponse
    {
        if ($salah = $mk->hapusMk($request->user()->id)) {
            return back()->with('error', $salah);
        }

        return back()->with('success', "MK surat jalan {$mk->no_seri} dihapus — hutang {$mk->sopir} berkurang Rp".rp($mk->nominal).'.');
    }

    /** Nominal isian hanya dipakai untuk yang boleh mengubah; selain itu selalu nominal bawaan. */
    private function nominal(Request $request, ?string $isian): int
    {
        $n = (int) preg_replace('/\D/', '', (string) $isian);

        return $n > 0 && MuatanKurang::bolehUbahNominal($request->user()) ? $n : MuatanKurang::NOMINAL;
    }
}
