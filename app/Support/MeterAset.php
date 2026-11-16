<?php

namespace App\Support;

use App\Models\AsetMeter;
use App\Models\AsetTruk;
use App\Models\Bbm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard HM & KM (owner 16 Nov 2026): urutan angka HM / KM per aset dan pemakaiannya dalam suatu periode.
 * Sumber: Data BBM (HM akhir terbesar per alat per hari, hanya aset ber-HM) + catatan manual aset_meter (menimpa BBM di tanggal yang sama).
 * Pemakaian = jumlah kenaikan antar-bacaan berurutan yang bacaan akhirnya jatuh di periode itu; angka turun (salah ketik / meter diganti
 * tanpa tanda reset) atau lompatan melebihi batas per hari tidak dihitung, hanya ditandai.
 */
class MeterAset
{
    /** Kelompok halaman: kunci => [judul, kategori aset]. */
    public const KELOMPOK = [
        'alat' => ['Alat Berat (HM)', ['alat_berat']],
        'truk' => ['Dump Truck (KM)', ['dump_truck']],
        'lain' => ['Kendaraan & Aset Lain', ['engkel', 'lv', 'water_tank', 'mesin', 'lainnya']],
    ];

    /** Kenaikan wajar maksimal per hari (lebih dari ini dianggap salah ketik). */
    public const MAKS_PER_HARI = ['HM' => 24, 'KM' => 1500];

    /** Bacaan dianggap basi bila lebih lama dari ini (hari). */
    public const BASI = 7;

    /**
     * Bacaan per aset, urut tanggal naik.
     *
     * @param  Collection<int, AsetTruk>  $aset
     * @return array<int, Collection<int, object>> aset_id => [{tanggal, nilai, sumber: bbm|input, id, reset, catatan, oleh}]
     */
    public static function bacaan(Collection $aset): array
    {
        $ids = $aset->pluck('id')->all();
        $idHm = $aset->filter(fn ($a) => AsetMeter::satuan($a->kategori) === 'HM')->pluck('id')->all();
        $per = array_fill_keys($ids, []);

        if ($idHm) {
            $bbm = Bbm::where('jenis', 'pakai')->whereIn('aset_id', $idHm)->where('hm_akhir', '>', 0)->whereNotNull('tanggal')
                ->groupBy('aset_id', 'tanggal')->selectRaw('aset_id, tanggal, MAX(hm_akhir) nilai')->toBase()->get();
            foreach ($bbm as $b) {
                $t = substr((string) $b->tanggal, 0, 10);
                $per[$b->aset_id][$t] = (object) ['tanggal' => $t, 'nilai' => (float) $b->nilai, 'sumber' => 'bbm', 'id' => null, 'reset' => false, 'catatan' => null, 'oleh' => null];
            }
        }
        foreach (AsetMeter::with('user:id,name')->whereIn('aset_id', $ids)->get() as $m) {
            $t = $m->tanggal->toDateString();
            $per[$m->aset_id][$t] = (object) ['tanggal' => $t, 'nilai' => $m->nilai, 'sumber' => 'input', 'id' => $m->id, 'reset' => $m->reset,
                'catatan' => $m->catatan, 'oleh' => $m->user?->name];
        }

        return array_map(fn ($b) => collect($b)->sortKeys()->values(), $per);
    }

    /**
     * Bacaan berurutan + selisih dari bacaan sebelumnya & tanda kejanggalan.
     *
     * Angka turun yang diikuti ≥ 2 catatan yang terus naik wajar dari angka baru itu (tetap di bawah angka lama) dianggap meter diganti:
     * reset otomatis (reset = true, reset_otomatis = true) — mis. Excavator Kobelco 07: 7.930 HM (Apr 2026) lalu 277 HM (Nov 2026).
     *
     * @return Collection<int, object> tiap baris: + selisih, hari (dari bacaan wajar sebelumnya), janggal (null | 'turun' | 'lompat'), reset_otomatis
     */
    public static function langkah(Collection $bacaan, string $satuan): Collection
    {
        $daftar = $bacaan->map(fn ($b) => clone $b)->values()->all();
        $hari = fn ($a, $b) => max(1, Carbon::parse($a->tanggal)->diffInDays(Carbon::parse($b->tanggal)));
        $wajar = fn ($a, $b) => $b->nilai >= $a->nilai && $b->nilai - $a->nilai <= self::MAKS_PER_HARI[$satuan] * $hari($a, $b);
        $sebelum = null;

        foreach ($daftar as $i => $b) {
            $b->selisih = $b->hari = $b->janggal = null;
            $b->reset_otomatis = false;
            if ($sebelum && ! $b->reset) {
                $b->hari = $hari($sebelum, $b);
                $b->selisih = round($b->nilai - $sebelum->nilai, 1);
                if ($b->selisih < 0) {
                    $n1 = $daftar[$i + 1] ?? null;
                    $n2 = $daftar[$i + 2] ?? null;
                    if ($n1 && $n2 && $wajar($b, $n1) && $wajar($n1, $n2) && $n2->nilai < $sebelum->nilai) {
                        $b->reset = $b->reset_otomatis = true;
                        $b->selisih = $b->hari = null;
                    } else {
                        $b->janggal = 'turun';
                    }
                } elseif ($b->sumber === 'bbm' && $b->selisih > self::MAKS_PER_HARI[$satuan] * $b->hari) {
                    // catatan manual sudah dikonfirmasi pencatat saat disimpan (DashboardHmKmController::simpan), jadi hanya Data BBM yang dicurigai
                    $b->janggal = 'lompat';
                }
            }
            // bacaan janggal tidak dijadikan patokan bacaan berikutnya (salah ketik sekali tidak merusak hitungan sesudahnya)
            if (! $b->janggal) {
                $sebelum = $b;
            }
        }

        return collect($daftar);
    }

    /**
     * Pemakaian dalam periode [dari, sampai] (YYYY-MM-DD).
     *
     * @return array{nilai: ?float, hari: int, janggal: int, rata: ?float}
     */
    public static function pakai(Collection $langkah, string $dari, string $sampai): array
    {
        $nilai = 0.0;
        $hari = $janggal = $pasang = 0;
        foreach ($langkah as $b) {
            if ($b->tanggal < $dari || $b->tanggal > $sampai || $b->selisih === null) {
                continue;
            }
            if ($b->janggal) {
                $janggal++;

                continue;
            }
            $pasang++;
            $nilai += $b->selisih;
            $hari += $b->hari;
        }

        return ['nilai' => $pasang ? round($nilai, 1) : null, 'hari' => $hari, 'janggal' => $janggal, 'rata' => $hari ? round($nilai / $hari, 1) : null];
    }

    /** Angka bacaan diketik orang ("5.370,5", "5370.5", "125.300" KM) → float; null bila bukan angka. */
    public static function urai(?string $v): ?float
    {
        $v = str_replace(' ', '', trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }
}
