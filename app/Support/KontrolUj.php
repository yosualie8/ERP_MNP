<?php

namespace App\Support;

use App\Models\ProyekTahap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Kontrol UJ (owner 18 Okt 2026): setiap Uang Jalan / Uang MOB yang sudah diberikan WAJIB berakhir bongkar (DO ada di Ritasi),
 * dialihkan, dikembalikan, atau dibatalkan dengan alasan. Selama belum, UJ itu TERBUKA.
 * Patokannya umur tiap UJ (bukan jumlah rit per hari, supaya sama untuk proyek 1 rit/hari maupun 4 rit/hari):
 *  - wajar : umur ≤ batas proyek (proyek_tahap.batas_bongkar, bawaan 2 hari — rute jauh boleh lebih);
 *  - lewat : umur > batas (kuning);
 *  - merah : umur > 7 hari → pengajuan UJ berikutnya untuk mobil itu wajib diberi alasan (tidak diblokir).
 * Data sebelum 1 Mei 2026 tidak dipakai untuk laporan (standar keyakinan laporan) — ditampilkan terpisah.
 */
class KontrolUj
{
    public const BATAS_BAWAAN = 2;

    public const MERAH = 7;

    public const SEJAK = '2026-05-01';

    /** Kategori yang wajib ada bongkarnya. Uang MOB TIDAK (owner 21 Okt 2026): ongkos mobilisasi truk pindah lokasi, bukan rit muatan. */
    public const KATEGORI = ['Uang Jalan'];

    private static ?Collection $cache = null;

    /** Tahap ritasi ber-kata kunci Kas UJ (untuk menebak proyek UJ yang belum bongkar). @return Collection<int, array{t: ProyekTahap, pola: string}> */
    public static function tahapKata(): Collection
    {
        return ProyekTahap::whereNotNull('kata_uj')->where('kata_uj', '!=', '')->get()
            ->map(fn ($t) => ['t' => $t, 'pola' => collect(preg_split('/\s*,\s*/', (string) $t->kata_uj))->filter()->map(fn ($k) => preg_quote($k, '/'))->implode('|')])
            ->filter(fn ($x) => $x['pola'] !== '')->values();
    }

    /**
     * DO belum bongkar yang punya Uang Jalan (termasuk pemakaian saldo sopir), lengkap dengan proyek, batas & status.
     *
     * @return Collection<int, array> baris MonitorRitasi::belumBongkar + uj, tahap, batas, status, lama (sebelum SEJAK)
     */
    public static function terbuka(?Collection $daftar = null): Collection
    {
        if ($daftar === null && self::$cache !== null) {
            return self::$cache;
        }
        $kata = self::tahapKata();
        $tahap = ProyekTahap::get(['id', 'nama', 'batas_bongkar'])->keyBy('id');
        $sejak = Carbon::parse(self::SEJAK);

        $hasil = ($daftar ?? MonitorRitasi::belumBongkar()['do'])->map(function (array $d) use ($kata, $tahap, $sejak) {
            $uj = collect($d['detail'])->filter(fn ($x) => in_array($x->kategori, self::KATEGORI, true));
            if ($uj->isEmpty()) {
                return null;
            }
            $t = ($id = $uj->first()->tahap_saldo ?? null) ? $tahap[$id] ?? null : null;
            if (! $t) {
                $ket = $uj->pluck('keterangan')->implode(' | ');
                $t = $kata->first(fn ($x) => preg_match("/\\b({$x['pola']})\\b/i", $ket))['t'] ?? null;
            }
            $batas = (int) ($t?->batas_bongkar ?: self::BATAS_BAWAAN);
            $umur = $d['umur'] ?? 0;

            return [...$d, 'uj' => (int) $uj->sum('nominal'), 'tahap' => $t?->nama, 'tahap_id' => $t?->id, 'batas' => $batas,
                'status' => $umur > self::MERAH ? 'merah' : ($umur > $batas ? 'lewat' : 'wajar'),
                'lama' => $d['pertama'] && $d['pertama']->lt($sejak)];
        })->filter()->values();

        return $daftar === null ? self::$cache = $hasil : $hasil;
    }

    /** UJ terbuka milik satu mobil yang sudah lewat batas (untuk peringatan di Pengajuan UJ), kecuali DO tertentu. */
    public static function lewatMobil(?string $mobil, ?string $kecualiDo = null): Collection
    {
        if (! $mobil) {
            return collect();
        }
        $k = SaldoSopir::kunciDo($kecualiDo);

        $mobil = NomorMobil::rapikan($mobil);

        return self::terbuka()->filter(fn ($d) => ! $d['lama'] && $d['status'] !== 'wajar' && $d['do'] !== $k
            && collect($d['mobil'])->contains(fn ($m) => NomorMobil::rapikan($m) === $mobil))
            ->sortByDesc('umur')->values();
    }

    /** Ringkasan untuk dashboard (sejak 1 Mei 2026). */
    public static function ringkas(): array
    {
        $baru = self::terbuka()->reject(fn ($d) => $d['lama']);
        $p = fn ($s) => ['n' => $baru->where('status', $s)->count(), 'rp' => (int) $baru->where('status', $s)->sum('uj')];

        return ['wajar' => $p('wajar'), 'lewat' => $p('lewat'), 'merah' => $p('merah'),
            'lama' => ['n' => self::terbuka()->where('lama', true)->count(), 'rp' => (int) self::terbuka()->where('lama', true)->sum('uj')]];
    }
}
