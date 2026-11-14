<?php

namespace App\Support;

use App\Models\UjDetail;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Nomor DO perjalanan MOB (owner 14 Nov 2026): setiap Uang MOB mendapat No DO sendiri "MOB-00001", "MOB-00002", … (otomatis saat
 * disimpan di Input UJ). Transaksi lain DT itu selama perjalanan MOB — sampai DT itu punya Uang Jalan baru (rit berikutnya yang akan
 * bongkar) — memakai nomor tersebut sebagai No DO. Nomor MOB bukan DO rit: tidak dipantau bongkarnya (bukan angka) dan tidak
 * dipasangkan ke surat jalan. Kasus asal: UJ-16761 impack & dongkrak DT 028 10 Nov di perjalanan MOB Kendal → PIK (UJ-16437).
 * Uang MOB lama yang sudah memakai nomor buku DO Eddy (mis. 17255) dibiarkan.
 */
class NomorMob
{
    public const AWALAN = 'MOB-';

    public static function adalah(?string $do): bool
    {
        return (bool) preg_match('/^MOB-\d{5}$/', (string) $do);
    }

    /** Ketikan admin "mob 16", "Mob-16", "MOB00016" → "MOB-00016"; selain itu apa adanya. */
    public static function rapikan(?string $do): ?string
    {
        if ($do !== null && preg_match('/^\s*mob\s*-?\s*(\d{1,5})\s*$/i', $do, $m)) {
            return self::AWALAN.str_pad($m[1], 5, '0', STR_PAD_LEFT);
        }

        return $do;
    }

    /**
     * Beri nomor MOB ke baris Uang MOB yang No DO-nya kosong (dipanggil tepat sebelum ditulis ke Kas Seabank).
     *
     * @return array{0: array, 1: string[]} input yang sudah bernomor + daftar nomor baru
     */
    public static function beri(array $input): array
    {
        $baru = [];
        $perlu = collect($input['detail'])->filter(fn ($d) => ($d['kategori'] ?? null) === 'Uang MOB' && trim((string) ($d['no_do'] ?? '')) === '');
        if ($perlu->isEmpty()) {
            return [$input, $baru];
        }
        Cache::lock('nomor-mob', 30)->block(15, function () use (&$input, &$baru, $perlu) {
            $n = self::terbesar();
            foreach ($perlu->keys() as $i) {
                $input['detail'][$i]['no_do'] = $baru[] = self::AWALAN.str_pad((string) ++$n, 5, '0', STR_PAD_LEFT);
            }
            Cache::forever('mob-max', $n);
        });

        return [$input, $baru];
    }

    /** Nomor MOB terbesar yang sudah dipakai (database + yang baru dibagikan sebelum impor berikutnya). */
    public static function terbesar(): int
    {
        $db = (int) UjDetail::where('no_do', 'like', self::AWALAN.'%')->selectRaw("MAX(CAST(SUBSTRING(no_do, 5) AS UNSIGNED)) as m")->value('m');

        return max($db, (int) Cache::get('mob-max', 0));
    }

    /** Baris Uang MOB pemilik nomor ini (null bila nomor tidak dikenal). */
    public static function pemilik(?string $do): ?UjDetail
    {
        return self::adalah($do) ? UjDetail::where('kategori', 'Uang MOB')->where('no_do', $do)->orderBy('id')->first() : null;
    }

    /**
     * Perjalanan MOB yang masih berjalan untuk DT ini pada tanggal itu: Uang MOB bernomor MOB terakhir s/d tanggal itu, dan sesudahnya
     * DT itu belum punya Uang Jalan (Uang Jalan baru = rit berikutnya → transaksi memakai DO rit itu).
     */
    public static function terbuka(?string $mobil, CarbonInterface $tanggal, ?int $kecualiTransaksi = null): ?UjDetail
    {
        if (! $mobil) {
            return null;
        }
        $mob = UjDetail::where('kategori', 'Uang MOB')->where('no_do', 'like', self::AWALAN.'%')->where('no_mobil', $mobil)
            ->whereDate('tanggal', '<=', $tanggal->toDateString())
            ->when($kecualiTransaksi, fn ($q) => $q->where('uj_transaksi_id', '!=', $kecualiTransaksi))
            ->orderByDesc('tanggal')->orderByDesc('baris')->first();
        if (! $mob) {
            return null;
        }
        $sudahJalan = UjDetail::where('kategori', 'Uang Jalan')->where('no_mobil', $mobil)
            ->whereDate('tanggal', '<=', $tanggal->toDateString())
            ->where(fn ($q) => $q->whereDate('tanggal', '>', $mob->tanggal->toDateString())
                ->orWhere(fn ($q2) => $q2->whereDate('tanggal', $mob->tanggal->toDateString())->where('baris', '>', $mob->baris)))
            ->when($kecualiTransaksi, fn ($q) => $q->where('uj_transaksi_id', '!=', $kecualiTransaksi))
            ->exists();

        return $sudahJalan ? null : $mob;
    }
}
