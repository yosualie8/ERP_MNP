<?php

namespace App\Http\Controllers;

use App\Models\Pengaturan;
use App\Models\User;
use App\Support\BacaBon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Daftar pengguna yang boleh login (super admin saja) dan pengaturan API key Claude untuk scan bon. */
class PenggunaController extends Controller
{
    public function index(): View
    {
        return view('pengguna.index', [
            'pengguna' => User::orderByRaw("role = 'super_admin' desc")->orderBy('name')->get(),
            'apiKeyAda' => BacaBon::apiKey() !== null,
            'apiKeyDariEnv' => (bool) config('services.anthropic.key'),
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

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user()) || in_array(strtolower($user->email), User::emailSuperAdminEnv(), true)) {
            return back()->with('error', 'Akun ini tidak bisa dihapus.');
        }
        $user->delete();

        return back()->with('success', "{$user->email} dihapus dan tidak bisa login lagi.");
    }

    public function simpanApiKey(Request $request): RedirectResponse
    {
        $data = $request->validate(['api_key' => ['nullable', 'string', 'max:300']]);
        $key = trim((string) ($data['api_key'] ?? ''));
        if ($key !== '' && ! str_starts_with($key, 'sk-ant-')) {
            return back()->with('error', 'API key Claude diawali "sk-ant-". Periksa lagi yang ditempel.');
        }
        Pengaturan::simpan(Pengaturan::API_KEY_CLAUDE, $key ?: null, $request->user()->id);

        return back()->with('success', $key ? 'API key Claude disimpan (terenkripsi). Fitur Scan foto bon aktif.' : 'API key Claude dihapus.');
    }
}
