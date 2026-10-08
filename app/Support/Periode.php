<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pilihan periode halaman daftar (Kas Harian, Kas UJ): tahun + bulan mulai s.d. bulan akhir (?tahun=2026&dari=9&sampai=10).
 * Tautan lama tetap dikenali: ?lembar=1026 (lembar Kas Harian "mmyy") dan ?bulan=2026-10. Tanpa pilihan = bulan terbaru yang ada datanya.
 */
class Periode
{
    public function __construct(
        public readonly int $tahun,
        public readonly int $dari,
        public readonly int $sampai,
        /** @var int[] tahun yang punya data (terbaru dulu) */
        public readonly array $daftarTahun,
        /** @var int[] bulan (1–12) yang punya data di tahun terpilih */
        public readonly array $bulanAda,
    ) {}

    /** @param  Collection<int, string>|array  $tersedia  bulan yang punya data, format "Y-m" */
    public static function dari(Request $request, Collection|array $tersedia): self
    {
        $ada = collect($tersedia)->filter()->map(fn ($b) => substr((string) $b, 0, 7))->unique()->sort()->values();
        $terbaru = $ada->last() ? Carbon::parse($ada->last().'-01') : now()->startOfMonth();
        $daftarTahun = $ada->map(fn ($b) => (int) substr($b, 0, 4))->push((int) $terbaru->year)->unique()->sortDesc()->values()->all();

        // Tautan lama: satu bulan.
        if (preg_match('/^(\d{2})(\d{2})$/', (string) $request->query('lembar'), $m) && $m[1] >= 1 && $m[1] <= 12) {
            [$tahun, $dari, $sampai] = [2000 + (int) $m[2], (int) $m[1], (int) $m[1]];
        } elseif (preg_match('/^(\d{4})-(\d{2})$/', (string) $request->query('bulan'), $m)) {
            [$tahun, $dari, $sampai] = [(int) $m[1], (int) $m[2], (int) $m[2]];
        } else {
            $tahun = $request->integer('tahun') ?: (int) $terbaru->year;
            // Tahun dipilih tanpa bulan: bulan terakhir yang ada datanya di tahun itu.
            $akhirTahun = $ada->filter(fn ($b) => str_starts_with($b, $tahun.'-'))->last();
            $bawaan = $akhirTahun ? (int) substr($akhirTahun, 5, 2) : ($tahun === (int) $terbaru->year ? (int) $terbaru->month : 12);
            $dari = min(12, max(1, $request->integer('dari') ?: $bawaan));
            $sampai = min(12, max(1, $request->integer('sampai') ?: $dari));
        }
        if ($dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $bulanAda = $ada->filter(fn ($b) => str_starts_with($b, $tahun.'-'))->map(fn ($b) => (int) substr($b, 5, 2))->values()->all();

        return new self($tahun, $dari, $sampai, $daftarTahun, $bulanAda);
    }

    public function awal(): Carbon
    {
        return Carbon::create($this->tahun, $this->dari, 1)->startOfDay();
    }

    public function akhir(): Carbon
    {
        return Carbon::create($this->tahun, $this->sampai, 1)->endOfMonth();
    }

    public function tunggal(): bool
    {
        return $this->dari === $this->sampai;
    }

    /** Parameter URL periode ini (untuk tautan lain di halaman). */
    public function param(): array
    {
        return ['tahun' => $this->tahun, 'dari' => $this->dari, 'sampai' => $this->sampai];
    }

    /** "Okt 2026" atau "Sep – Okt 2026". */
    public function label(): string
    {
        $a = $this->awal()->translatedFormat('M');
        $b = $this->akhir()->translatedFormat('M Y');

        return $this->tunggal() ? $b : "{$a} – {$b}";
    }
}
