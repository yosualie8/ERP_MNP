<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MenuAkses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Daftar pengguna yang boleh login (super admin saja). */
class PenggunaController extends Controller
{
    public function index(): View
    {
        return view('pengguna.index', [
            'pengguna' => User::orderByRaw("role = 'super_admin' desc")->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::in(array_keys(User::ROLE))],
        ], ['email.unique' => 'Email ini sudah terdaftar.']);
        $email = strtolower(trim($data['email']));

        User::create(['email' => $email, 'name' => $data['name'] ?: $email, 'role' => $data['role']]);

        return back()->with('success', "{$email} ditambahkan sebagai ".User::ROLE[$data['role']].'. Sekarang ia bisa login dengan akun Google tersebut.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $role = $request->validate(['role' => ['required', Rule::in(array_keys(User::ROLE))]])['role'];
        if ($user->is($request->user()) && $role !== 'super_admin') {
            return back()->with('error', 'Anda tidak bisa menurunkan peran akun Anda sendiri.');
        }
        $user->update(['role' => $role]);

        return back()->with('success', "Peran {$user->email} diubah menjadi ".User::ROLE[$role].'.');
    }

    /** Atur menu yang boleh dilihat akun Admin (Super Admin selalu semua menu). */
    public function menu(Request $request, User $user): RedirectResponse
    {
        $menu = $request->validate([
            'menu' => ['nullable', 'array'],
            'menu.*' => [Rule::in(array_keys(MenuAkses::DAFTAR))],
        ])['menu'] ?? [];
        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Super Admin selalu bisa melihat semua menu.');
        }
        $user->update(['menu' => array_values(array_intersect(array_keys(MenuAkses::DAFTAR), $menu))]);
        $nama = collect($user->menu)->map(fn ($k) => MenuAkses::DAFTAR[$k][0]);

        return back()->with('success', "Menu {$user->email}: ".($nama->isEmpty() ? 'hanya Beranda' : 'Beranda, '.$nama->implode(', ')).'.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user()) || in_array(strtolower($user->email), User::emailSuperAdminEnv(), true)) {
            return back()->with('error', 'Akun ini tidak bisa dihapus.');
        }
        $user->delete();

        return back()->with('success', "{$user->email} dihapus dan tidak bisa login lagi.");
    }
}
