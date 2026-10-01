<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GoogleIntegrasi;
use App\Models\User;
use App\Support\GoogleSheets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    /** Login sekaligus meminta izin Sheets + Drive baca-saja, supaya aplikasi bisa membaca semua sheet akun ini. */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(GoogleSheets::SCOPES)
            ->with([
                'access_type' => 'offline',
                'prompt' => 'select_account consent',
                'include_granted_scopes' => 'true',
            ])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('login')->withErrors(['email' => 'Login Google dibatalkan.']);
        }

        $googleUser = Socialite::driver('google')->user();
        $email = strtolower((string) $googleUser->getEmail());

        if (! in_array($email, User::emailSuperAdminEnv(), true)) {
            // Cabut izin yang terlanjur diberikan akun yang tidak berhak.
            Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $googleUser->refreshToken ?: $googleUser->token]);

            return redirect()->route('login')->withErrors(['email' => "Akun {$email} tidak diizinkan mengakses aplikasi MNP."]);
        }

        $user = User::updateOrCreate(['email' => $email], [
            'name' => $googleUser->getName() ?: $email,
            'google_id' => $googleUser->getId(),
            'avatar' => $googleUser->getAvatar(),
        ]);
        Auth::login($user, remember: true);

        $izin = $googleUser->approvedScopes ?? [];
        $kurang = array_diff(GoogleSheets::SCOPES, $izin);
        if ($kurang) {
            return redirect()->route('dashboard')->with('error',
                'Izin Google Sheets / Drive tidak dicentang semua. Logout, lalu login lagi dan centang semua izin.');
        }

        if ($googleUser->refreshToken) {
            GoogleIntegrasi::query()->delete();
            GoogleIntegrasi::create([
                'email' => $email,
                'refresh_token' => $googleUser->refreshToken,
                'access_token' => $googleUser->token,
                'access_token_kedaluwarsa' => now()->addSeconds(max(60, (int) $googleUser->expiresIn - 120)),
                'izin' => array_values($izin),
                'user_id' => $user->id,
            ]);
        } elseif (! GoogleIntegrasi::aktif()) {
            return redirect()->route('dashboard')->with('error',
                'Google tidak memberikan izin jangka panjang. Cabut akses "MNP ERP" di myaccount.google.com/permissions, lalu login ulang.');
        }

        return redirect()->route('dashboard')->with('success', "Masuk sebagai {$email}. Akses Google Sheets aktif.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
