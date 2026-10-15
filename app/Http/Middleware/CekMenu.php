<?php

namespace App\Http\Middleware;

use App\Support\MenuAkses;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Tolak halaman/aksi milik menu yang tidak diberikan ke jabatan akun ini (diatur di halaman Hak Akses Jabatan). */
class CekMenu
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        $rute = $request->route()?->getName();
        if ($u && ! $u->isSuperAdmin()) {
            // Bukan lagi karyawan aktif (dinonaktifkan / email dihapus dari Data Karyawan) → sesi diakhiri.
            if (! $u->karyawanAktif()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => "Akun {$u->email} tidak lagi terdaftar sebagai karyawan aktif. Hubungi admin."]);
            }
            $menu = $u->menuJabatan();
            // Jabatannya diatur tanpa menu apa pun.
            if (! $menu && $rute !== 'logout') {
                return response()->view('akses.belum-terhubung', ['karyawan' => $u->karyawanAktif()], 403);
            }
            // Hanya punya menu Absen → Beranda langsung ke halaman Absen.
            if ($rute === 'dashboard' && $menu === ['absen']) {
                return redirect()->route('absen.index');
            }
        }
        if (! MenuAkses::bolehRute($u, $rute)) {
            if ($request->expectsJson()) {
                return response()->json(['galat' => ['Akun Anda tidak punya akses ke menu ini.']], 403);
            }

            return redirect()->route('dashboard')->with('error', 'Jabatan Anda tidak punya akses ke halaman itu. Minta Super Admin mengaturnya di menu Hak Akses Jabatan.');
        }

        return $next($request);
    }
}
