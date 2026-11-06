<?php

namespace App\Jobs;

use App\Http\Controllers\KasController;
use App\Models\User;
use App\Support\KasExcel;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Buat Excel Kas Harian sesudah respons dikirim ke browser (owner 6 Nov 2026): data setahun ±30 detik & ±170 MB — melewati
 * batas halaman web (30 detik, 128 MB). Kemajuan di cache "kas-unduh:{token}" dibaca browser untuk progress bar.
 * Hanya satu pembuatan berjalan sekaligus (yang lain antre) supaya server tidak berat.
 */
class BuatExcelKas
{
    use Dispatchable;

    public const MENIT_SIMPAN = 60;

    public function __construct(public string $token, public int $userId, public array $query, public ?array $t, public ?array $b) {}

    public static function kunci(string $token): string
    {
        return "kas-unduh:{$token}";
    }

    public static function lapor(string $token, array $isi): void
    {
        Cache::put(self::kunci($token), [...(Cache::get(self::kunci($token)) ?? []), ...$isi], now()->addMinutes(self::MENIT_SIMPAN));
    }

    public static function folder(): string
    {
        $f = storage_path('app/kas-unduh');
        if (! is_dir($f)) {
            mkdir($f, 0775, true);
        }

        return $f;
    }

    public function handle(): void
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');
        $lapor = fn (int $persen, string $pesan) => self::lapor($this->token, ['persen' => $persen, 'pesan' => $pesan]);
        $lapor(1, 'Menunggu giliran (unduhan lain sedang dibuat)…');
        try {
            Cache::lock('kas-unduh-buat', 900)->block(600, function () use ($lapor) {
                $lapor(3, 'Membaca data Kas Harian…');
                $user = User::findOrFail($this->userId);
                $request = Request::create('/kas', 'GET', $this->query);
                $request->setUserResolver(fn () => $user);
                $d = app(KasController::class)->data($request);
                $transfer = $d['transfer'];
                if ($this->t !== null) {
                    $urut = array_flip($this->t);
                    $transfer = $transfer->filter(fn ($x) => isset($urut[$x->id]))->values();
                }
                $transfer->load('kodeGl.akun');
                $lapor(30, 'Menyusun Excel…');
                $path = KasExcel::buat($transfer, $this->b === null ? null : array_flip($this->b), $d,
                    fn (int $i, int $n) => $lapor(30 + (int) floor($i / max(1, $n) * 68), "Menulis baris {$i} dari {$n} transfer…"), self::folder()."/{$this->token}.xlsx");
                self::lapor($this->token, ['persen' => 100, 'pesan' => 'Selesai', 'siap' => true, 'file' => $path]);
            });
        } catch (\Throwable $e) {
            report($e);
            self::lapor($this->token, ['galat' => 'Gagal membuat Excel: '.$e->getMessage()]);
        }
    }
}
