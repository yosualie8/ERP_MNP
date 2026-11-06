<?php

namespace App\Jobs;

use App\Http\Controllers\RitasiController;
use App\Models\User;
use App\Support\RitasiExcel;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Buat Excel Ritasi sesudah respons dikirim (owner 6 Nov 2026) — sama dengan Excel Kas Harian (BuatExcelKas): progress di cache,
 * satu pembuatan sekaligus (kunci bersama dengan Kas Harian), file dihapus sesudah diunduh.
 */
class BuatExcelRitasi
{
    use Dispatchable;

    /** @param  int[]|null  $ids  id rit yang tampil di grid (saringan kolom); null = semua hasil saringan halaman */
    public function __construct(public string $token, public int $userId, public array $query, public ?array $ids) {}

    public function handle(): void
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');
        $lapor = fn (int $persen, string $pesan) => BuatExcelKas::lapor($this->token, ['persen' => $persen, 'pesan' => $pesan]);
        $lapor(1, 'Menunggu giliran (unduhan lain sedang dibuat)…');
        try {
            Cache::lock('kas-unduh-buat', 900)->block(600, function () use ($lapor) {
                $lapor(5, 'Membaca data Ritasi…');
                $user = User::findOrFail($this->userId);
                $request = Request::create('/ritasi', 'GET', $this->query);
                $request->setUserResolver(fn () => $user);
                $rit = app(RitasiController::class)->data($request)['rit'];
                if ($this->ids !== null) {
                    $boleh = array_flip($this->ids);
                    $rit = $rit->filter(fn ($r) => isset($boleh[$r->id]))->values();
                }
                $lapor(20, 'Menyusun Excel…');
                $path = RitasiExcel::buat($rit, fn (int $i, int $n) => $lapor(20 + (int) floor($i / max(1, $n) * 78), "Menulis rit {$i} dari {$n}…"),
                    BuatExcelKas::folder()."/{$this->token}.xlsx");
                BuatExcelKas::lapor($this->token, ['persen' => 100, 'pesan' => 'Selesai', 'siap' => true, 'file' => $path]);
            });
        } catch (\Throwable $e) {
            report($e);
            BuatExcelKas::lapor($this->token, ['galat' => 'Gagal membuat Excel: '.$e->getMessage()]);
        }
    }
}
