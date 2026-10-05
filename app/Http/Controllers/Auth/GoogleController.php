<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GoogleIntegrasi;
use App\Models\User;
use App\Support\DriveFoto;
use App\Support\GoogleSheets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Login Google hanya meminta nama & email. Izin Google Sheets diminta terpisah lewat "Hubungkan Google Sheets"
 * (khusus super admin), supaya login pengguna lain tidak mengganti akun yang dipakai membaca sheet kas.
 */
class GoogleController extends Controller
{
    private const SESI_HUBUNGKAN = 'hubungkan_google_sheets';

    public function show(): View
    {
        return view('auth.login');
    }

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
    }

    /** Super admin: minta izin Sheets + Drive baca-saja untuk akun pemilik sheet. */
    public function hubungkanSheets(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $request->session()->put(self::SESI_HUBUNGKAN, 'sheets');

        return Socialite::driver('google')
            ->scopes(GoogleSheets::SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'select_account consent', 'include_granted_scopes' => 'true'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $hubungkan = $request->session()->pull(self::SESI_HUBUNGKAN, false);
        if ($request->has('error')) {
            return $request->user()
                ? redirect()->route('dashboard')->with('error', 'Proses di halaman Google dibatalkan.')
                : redirect()->route('login')->withErrors(['email' => 'Login Google dibatalkan.']);
        }

        $googleUser = Socialite::driver('google')->user();
        if ($hubungkan === 'foto' && $request->user()?->isSuperAdmin()) {
            return $this->simpanIntegrasiFoto($request, $googleUser);
        }
        if ($hubungkan && $request->user()?->isSuperAdmin()) {
            return $this->simpanIntegrasi($request, $googleUser);
        }
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        $email = strtolower((string) $googleUser->getEmail());
        $profil = ['name' => $googleUser->getName() ?: $email, 'google_id' => $googleUser->getId(), 'avatar' => $googleUser->getAvatar()];

        if (in_array($email, User::emailSuperAdminEnv(), true)) {
            $user = User::updateOrCreate(['email' => $email], [...$profil, 'role' => 'super_admin']);
        } else {
            // Pengguna lain harus sudah didaftarkan super admin di menu Pengguna.
            $user = User::where('email', $email)->first();
            if (! $user) {
                Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $googleUser->token]);

                return redirect()->route('login')->withErrors(['email' => "Akun {$email} belum terdaftar. Minta super admin menambahkan email Anda di menu Pengguna."]);
            }
            $user->update($profil);
        }

        Auth::login($user, remember: true);

        return redirect()->route('dashboard');
    }

    private function simpanIntegrasi(Request $request, GoogleUser $googleUser): RedirectResponse
    {
        $email = strtolower((string) $googleUser->getEmail());
        $izin = $googleUser->approvedScopes ?? [];
        if (array_diff(GoogleSheets::SCOPES, $izin)) {
            return redirect()->route('dashboard')->with('error', 'Izin Google Sheets / Drive tidak dicentang semua. Klik "Hubungkan Google Sheets" lagi dan centang semua izin.');
        }
        if (! $googleUser->refreshToken) {
            return redirect()->route('dashboard')->with('error', 'Google tidak memberikan izin jangka panjang. Cabut akses "MNP ERP" di myaccount.google.com/permissions, lalu hubungkan ulang.');
        }

        GoogleIntegrasi::where('keperluan', 'sheets')->delete();
        GoogleIntegrasi::create([
            'keperluan' => 'sheets',
            'email' => $email,
            'refresh_token' => $googleUser->refreshToken,
            'access_token' => $googleUser->token,
            'access_token_kedaluwarsa' => now()->addSeconds(max(60, (int) $googleUser->expiresIn - 120)),
            'izin' => array_values($izin),
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('dashboard')->with('success', "Google Sheets terhubung dengan akun {$email}.");
    }

    /** Super admin: hubungkan akun perusahaan (izin Drive) untuk menyimpan foto bon di folder "Foto Bon MNP". */
    public function hubungkanDriveFoto(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $request->session()->put(self::SESI_HUBUNGKAN, 'foto');

        return Socialite::driver('google')
            ->scopes([DriveFoto::SCOPE])
            ->with(['access_type' => 'offline', 'prompt' => 'select_account consent', 'login_hint' => config('mnp.drive_foto_email')])
            ->redirect();
    }

    private function simpanIntegrasiFoto(Request $request, GoogleUser $googleUser): RedirectResponse
    {
        $email = strtolower((string) $googleUser->getEmail());
        $wajib = strtolower(config('mnp.drive_foto_email'));
        if ($email !== $wajib) {
            Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $googleUser->refreshToken ?: $googleUser->token]);

            return redirect()->route('dashboard')->with('error', "Foto bon disimpan di Google Drive {$wajib}, tetapi yang dipilih {$email}. Klik \"Hubungkan Google Drive foto\" lagi dan pilih akun {$wajib}.");
        }
        if (! in_array(DriveFoto::SCOPE, $googleUser->approvedScopes ?? [], true)) {
            return redirect()->route('dashboard')->with('error', 'Izin Google Drive tidak dicentang. Klik "Hubungkan Google Drive foto" lagi dan centang izinnya.');
        }
        if (! $googleUser->refreshToken) {
            return redirect()->route('dashboard')->with('error', "Google tidak memberikan izin jangka panjang. Login ke {$wajib}, cabut akses \"MNP ERP\" di myaccount.google.com/permissions, lalu hubungkan ulang.");
        }

        GoogleIntegrasi::where('keperluan', 'foto')->delete();
        GoogleIntegrasi::create([
            'keperluan' => 'foto',
            'email' => $email,
            'refresh_token' => $googleUser->refreshToken,
            'access_token' => $googleUser->token,
            'access_token_kedaluwarsa' => now()->addSeconds(max(60, (int) $googleUser->expiresIn - 120)),
            'izin' => array_values($googleUser->approvedScopes ?? []),
            'user_id' => $request->user()->id,
        ]);

        try {
            $folder = DriveFoto::terhubung()->periksaFolder();
        } catch (\Throwable $e) {
            return redirect()->route('dashboard')->with('error', "Akun {$email} terhubung, tetapi folder foto belum bisa dipakai: ".$e->getMessage());
        }

        return redirect()->route('dashboard')->with('success', "Google Drive {$email} terhubung. Foto bon disimpan ke folder \"{$folder}\" (dipindah otomatis tiap menit).");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
