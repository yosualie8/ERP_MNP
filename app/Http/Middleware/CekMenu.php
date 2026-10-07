<?php

namespace App\Http\Middleware;

use App\Support\MenuAkses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Tolak halaman/aksi milik menu yang tidak diberikan ke akun ini (diatur di halaman Pengguna). */
class CekMenu
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! MenuAkses::bolehRute($request->user(), $request->route()?->getName())) {
            if ($request->expectsJson()) {
                return response()->json(['galat' => ['Akun Anda tidak punya akses ke menu ini.']], 403);
            }

            return redirect()->route('dashboard')->with('error', 'Akun Anda tidak punya akses ke halaman itu. Minta Super Admin mengaturnya di menu Pengguna.');
        }

        return $next($request);
    }
}
