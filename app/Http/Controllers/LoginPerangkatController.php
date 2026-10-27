<?php

namespace App\Http\Controllers;

use App\Models\LoginPerangkat;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Login di perangkat lain (khusus Super Admin): laptop yang sudah login membuat QR sekali pakai (5 menit),
 * HP lain men-scan → konfirmasi → masuk sebagai akun yang membuat QR. Tanpa "ingat saya": sesi berakhir sendiri,
 * dan bisa dikeluarkan dari halaman ini.
 */
class LoginPerangkatController extends Controller
{
    public function index(Request $request): View
    {
        $token = $request->session()->get('qr_login');
        $aktif = $token ? LoginPerangkat::berlaku($token) : null;

        return view('login-perangkat.index', [
            'aktif' => $aktif,
            'qr' => $aktif ? $this->qr(route('login-perangkat.masuk', $token)) : null,
            'riwayat' => LoginPerangkat::where('user_id', $request->user()->id)->latest('id')->limit(15)->get(),
        ]);
    }

    public function buat(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;
        // Satu QR aktif per akun: QR lama yang belum dipakai langsung tidak berlaku. Riwayat > 30 hari dibuang.
        LoginPerangkat::where('user_id', $userId)->whereNull('dipakai_pada')->where('kedaluwarsa_pada', '>', now())->update(['kedaluwarsa_pada' => now()]);
        LoginPerangkat::where('created_at', '<', now()->subDays(30))->delete();
        $token = Str::random(48);
        LoginPerangkat::create([
            'user_id' => $userId, 'token_hash' => LoginPerangkat::hash($token),
            'kedaluwarsa_pada' => now()->addMinutes(LoginPerangkat::MENIT_BERLAKU), 'ip_buat' => $request->ip(),
        ]);
        $request->session()->put('qr_login', $token);

        return redirect()->route('login-perangkat.index');
    }

    /** Dipanggil halaman QR tiap beberapa detik: sudah di-scan & dipakai? */
    public function status(Request $request, LoginPerangkat $login): JsonResponse
    {
        abort_unless($login->user_id === $request->user()->id, 404);

        return response()->json(['dipakai' => (bool) $login->dipakai_pada, 'kedaluwarsa' => $login->kedaluwarsa_pada->isPast()]);
    }

    /** Keluarkan sesi perangkat yang masuk lewat QR (file sesinya dihapus → perangkat itu kembali ke halaman login). */
    public function keluarkan(Request $request, LoginPerangkat $login): RedirectResponse
    {
        abort_unless($login->user_id === $request->user()->id, 404);
        if ($login->sesi_id && ! $login->dikeluarkan_pada) {
            app('session')->getHandler()->destroy($login->sesi_id);
            $login->update(['dikeluarkan_pada' => now()]);
        }

        return redirect()->route('login-perangkat.index')->with('success', 'Perangkat '.self::namaPerangkat($login->perangkat).' sudah dikeluarkan.');
    }

    /** Halaman di HP setelah scan: konfirmasi dulu (supaya pratinjau link / pemindai otomatis tidak memakai QR-nya). */
    public function konfirmasi(string $token): View
    {
        return view('login-perangkat.masuk', ['login' => LoginPerangkat::berlaku($token), 'token' => $token]);
    }

    public function masuk(Request $request, string $token): RedirectResponse
    {
        $hash = LoginPerangkat::hash($token);
        // Atomik: hanya satu permintaan yang bisa memakai token ini.
        $dipakai = LoginPerangkat::where('token_hash', $hash)->whereNull('dipakai_pada')->where('kedaluwarsa_pada', '>', now())
            ->update(['dipakai_pada' => now(), 'ip_pakai' => $request->ip(), 'perangkat' => mb_substr((string) $request->userAgent(), 0, 255)]);
        $login = $dipakai ? LoginPerangkat::with('user')->where('token_hash', $hash)->first() : null;
        if (! $login?->user?->isSuperAdmin()) {
            return redirect()->route('login-perangkat.masuk', $token);
        }
        if (Auth::check()) {
            Auth::logout();
        }
        Auth::login($login->user); // tanpa "ingat saya"
        $request->session()->regenerate();
        $login->update(['sesi_id' => $request->session()->getId()]);

        return redirect()->route('dashboard')->with('success', 'Masuk lewat QR sebagai '.$login->user->email.'. Keluar dari perangkat ini lewat tombol Keluar, atau dari laptop di halaman Login Perangkat Lain.');
    }

    private function qr(string $url): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(260, 2), new SvgImageBackEnd())))->writeString($url);
    }

    public static function namaPerangkat(?string $ua): string
    {
        if (! $ua) {
            return '-';
        }
        $model = preg_match('/Android [\d.]+; ([^;)]+)/', $ua, $m) ? trim($m[1]) : null;
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', default => 'Perangkat',
        };
        $browser = match (true) {
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet', str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'Safari') => 'Safari', default => '',
        };

        return trim(($model && $model !== 'K' ? "{$os} {$model}" : $os).($browser ? " · {$browser}" : ''));
    }
}
