<?php

namespace App\Http\Controllers;

use App\Models\JabatanMenu;
use App\Models\Karyawan;
use App\Models\User;
use App\Support\MenuAkses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Hak Akses Jabatan (super admin): menu yang boleh dibuka tiap jabatan karyawan. */
class JabatanMenuController extends Controller
{
    public function index(): View
    {
        $aktif = Karyawan::where('status', 'aktif')->whereNotNull('jabatan')->orderBy('nama')->get()->groupBy('jabatan');
        $atur = JabatanMenu::all()->keyBy('jabatan');
        $akun = User::pluck('email')->map(fn ($e) => strtolower($e))->flip();
        $jabatan = $aktif->keys()->merge($atur->keys())->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()
            ->map(fn ($j) => (object) [
                'nama' => $j,
                'menu' => $atur[$j]->menu ?? JabatanMenu::BAWAAN,
                'diatur' => isset($atur[$j]),
                'karyawan' => $aktif[$j] ?? collect(),
                'bisaLogin' => ($aktif[$j] ?? collect())->filter(fn ($k) => $k->email)->count(),
                'sudahLogin' => ($aktif[$j] ?? collect())->filter(fn ($k) => $k->email && isset($akun[strtolower($k->email)]))->count(),
            ]);

        return view('jabatan-menu.index', ['jabatan' => $jabatan]);
    }

    public function update(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $d = $request->validate([
            'jabatan' => ['required', 'string', 'max:100'],
            'menu' => ['nullable', 'array'],
            'menu.*' => [Rule::in(array_keys(MenuAkses::DAFTAR))],
        ]);
        $menu = array_values(array_intersect(array_keys(MenuAkses::DAFTAR), array_diff($d['menu'] ?? [], MenuAkses::KHUSUS_SUPER)));
        JabatanMenu::updateOrCreate(['jabatan' => $d['jabatan']], ['menu' => $menu, 'user_id' => $request->user()->id]);
        $nama = collect($menu)->map(fn ($k) => MenuAkses::DAFTAR[$k][0]);
        $pesan = "Menu jabatan {$d['jabatan']}: ".($nama->isEmpty() ? 'tidak ada (tidak bisa membuka menu apa pun)' : $nama->implode(', ')).'.';

        // Disimpan dari halaman tanpa muat ulang (posisi gulir tetap).
        return $request->expectsJson() ? response()->json(['pesan' => $pesan, 'jumlah' => count($menu)]) : back()->with('success', $pesan);
    }
}
