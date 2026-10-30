<?php

namespace App\Support;

use App\Models\Absensi;
use App\Models\JabatanMenu;
use App\Models\Karyawan;
use App\Models\Lapor;
use App\Models\LokasiAbsen;
use App\Models\ProyekTahap;
use App\Models\RekapAbsen;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap absen mingguan (Senin–Minggu) → penggajian UM (owner 30 Okt 2026):
 * - UM per hari = karyawan.um_harian, bila kosong tarif UM jabatannya (Master Jabatan) — jabatan sama bisa beda UM.
 * - Hari dibayar = absen masuk & pulang lengkap. Absen belum lengkap MENUNGGU persetujuan admin (rekap_absen.hadir).
 *   Tukang tanpa absen HP diisi mandor (rekap_absen sumber "mandor"). Admin boleh menimpa hari mana pun (bayar / tidak).
 * - Lembur = ½ UM per hari (mulai & selesai lembur lengkap; yang belum lengkap menunggu persetujuan), hanya bila hari itu dibayar.
 * - Tahap kerja per hari: isian admin/mandor → tahap kiriman Lapor Lapangan / lokasi absen → tahap hari kerja sebelumnya.
 */
class RekapAbsenMingguan
{
    public const BELUM_TAHAP = '(belum ada tahap)';

    /** Senin minggu itu; bawaan minggu lalu (minggu ini masih berjalan). */
    public static function senin(?string $tanggal): Carbon
    {
        $t = $tanggal ? \App\Http\Controllers\RitasiController::tanggal($tanggal) : null;

        return ($t ?? today()->subWeek())->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** UM per hari satu karyawan (per orang, bila kosong dari jabatan). */
    public static function um(Karyawan $k, ?Collection $tarifJabatan = null): ?int
    {
        $tarif = $tarifJabatan ?? JabatanMenu::pluck('um_harian', 'jabatan');

        return $k->um_harian ?: ($tarif[$k->jabatan] ?? null);
    }

    /**
     * @return array{senin: Carbon, hari: Collection<int, Carbon>, baris: Collection<int, object>, tahap: Collection<int, string>,
     *     perTahap: Collection<string, int>, ringkas: array}
     */
    public static function data(Carbon $senin): array
    {
        $hari = collect(range(0, 6))->map(fn ($i) => $senin->copy()->addDays($i));
        $rentang = [$senin->toDateString(), $hari->last()->toDateString()];
        $absen = Absensi::whereBetween('tanggal', $rentang)->orderBy('waktu')->get()->groupBy(['karyawan_id', fn ($a) => $a->tanggal->toDateString()]);
        $rekap = RekapAbsen::whereBetween('tanggal', $rentang)->get()->groupBy(['karyawan_id', fn ($r) => $r->tanggal->toDateString()]);
        $tahapLapor = Lapor::whereIn('id', $absen->flatten()->pluck('lapor_id')->filter()->unique())->pluck('proyek_tahap_id', 'id');
        $tahapLokasi = LokasiAbsen::pluck('proyek_tahap_id', 'id');
        // Tahap terakhir sebelum minggu ini (60 hari) → bawaan hari pertama.
        $tahapLalu = RekapAbsen::where('tanggal', '<', $senin->toDateString())->where('tanggal', '>=', $senin->copy()->subDays(60)->toDateString())
            ->whereNotNull('proyek_tahap_id')->orderBy('tanggal')->get(['karyawan_id', 'proyek_tahap_id'])->keyBy('karyawan_id')->map->proyek_tahap_id;

        // Karyawan aktif kecuali sopir DT (sama dengan Review Absen) + siapa pun yang absen / direkap minggu itu.
        $karyawan = Karyawan::where(fn ($q) => $q->where('status', 'aktif')->where(fn ($w) => $w->whereNull('jabatan')->orWhere('jabatan', '!=', DaftarSopir::JABATAN)->orWhereNull('no_mobil')))
            ->orWhereIn('id', $absen->keys()->concat($rekap->keys())->unique())->orderBy('nama')->get();
        $tarif = JabatanMenu::pluck('um_harian', 'jabatan');
        $namaTahap = ProyekTahap::pluck('nama', 'id');

        $baris = $karyawan->map(function ($k) use ($absen, $rekap, $hari, $tarif, $tahapLapor, $tahapLokasi, $tahapLalu, $namaTahap) {
            $um = self::um($k, $tarif);
            $jalan = $tahapLalu[$k->id] ?? null;
            $perHari = [];
            foreach ($hari as $h) {
                $d = $h->toDateString();
                $a = ($absen[$k->id][$d] ?? collect())->keyBy('jenis');
                $r = ($rekap[$k->id][$d] ?? null)?->first(); // unik per karyawan + tanggal
                [$m, $p, $lm, $ls] = [$a->get('masuk'), $a->get('pulang'), $a->get('lembur_mulai'), $a->get('lembur_selesai')];
                $absenHadir = $m && $p ? 'lengkap' : ($m || $p ? 'kurang' : null);
                $absenLembur = $lm && $ls ? 'lengkap' : ($lm || $ls ? 'kurang' : null);
                $putusHadir = $r && $r->hadir !== null;
                $putusLembur = $r && $r->lembur !== null;
                $hadir = $putusHadir ? $r->hadir : $absenHadir === 'lengkap';
                $lembur = $hadir && ($putusLembur ? $r->lembur : $absenLembur === 'lengkap');
                $tungguHadir = ! $putusHadir && $absenHadir === 'kurang';
                $tungguLembur = ! $putusLembur && $absenLembur === 'kurang' && ($hadir || $tungguHadir);
                $tahapAbsen = collect([$m, $p])->filter()->map(fn ($x) => ($x->lapor_id ? $tahapLapor[$x->lapor_id] ?? null : null) ?? ($x->lokasi_absen_id ? $tahapLokasi[$x->lokasi_absen_id] ?? null : null))->filter()->first();
                $tahap = $r?->proyek_tahap_id ?? $tahapAbsen ?? (($hadir || $tungguHadir) ? $jalan : null);
                if (($hadir || $tungguHadir) && $tahap) {
                    $jalan = $tahap;
                }
                $perHari[$d] = (object) [
                    'tanggal' => $h, 'masuk' => $m?->waktu->format('H:i'), 'pulang' => $p?->waktu->format('H:i'),
                    'lembur_mulai' => $lm?->waktu->format('H:i'), 'lembur_selesai' => $ls?->waktu->format('H:i'),
                    'absen' => $absenHadir, 'absen_lembur' => $absenLembur, 'hadir' => $hadir, 'lembur' => $lembur,
                    'tunggu' => $tungguHadir, 'tunggu_lembur' => $tungguLembur, 'putus_hadir' => $putusHadir ? $r->hadir : null,
                    'putus_lembur' => $putusLembur ? $r->lembur : null, 'sumber' => $r?->sumber, 'catatan' => $r?->catatan,
                    'tahap_id' => $tahap, 'tahap' => $tahap ? ($namaTahap[$tahap] ?? null) : null, 'tahap_diisi' => (bool) $r?->proyek_tahap_id,
                    'bayar' => $hadir ? (int) $um : 0, 'bayar_lembur' => $lembur ? (int) round($um / 2) : 0,
                ];
            }
            $x = collect($perHari);

            return (object) [
                'k' => $k, 'um' => $um, 'um_sendiri' => (bool) $k->um_harian, 'hari' => $perHari,
                'hadir' => $x->where('hadir', true)->count(), 'lembur' => $x->where('lembur', true)->count(),
                'tunggu' => $x->where('tunggu', true)->count() + $x->where('tunggu_lembur', true)->count(),
                'total_um' => $x->sum('bayar'), 'total_lembur' => $x->sum('bayar_lembur'), 'total' => $x->sum('bayar') + $x->sum('bayar_lembur'),
                'ada' => $x->contains(fn ($h) => $h->absen || $h->absen_lembur || $h->sumber || $h->hadir),
            ];
        })->sortBy(fn ($b) => [$b->ada ? 0 : 1, $b->k->site ?? 'zzz', $b->k->nama])->values();

        $semuaHari = $baris->flatMap(fn ($b) => array_values($b->hari));
        $perTahap = $semuaHari->filter(fn ($h) => $h->bayar || $h->bayar_lembur)->groupBy(fn ($h) => $h->tahap ?? self::BELUM_TAHAP)
            ->map(fn ($g) => $g->sum('bayar') + $g->sum('bayar_lembur'))->sortDesc();

        return [
            'senin' => $senin, 'hari' => $hari, 'baris' => $baris, 'perTahap' => $perTahap,
            'tahap' => ProyekTahap::whereIn('status', ['belum', 'progress'])->orWhereIn('id', $semuaHari->pluck('tahap_id')->filter()->unique())
                ->orderBy('nama')->pluck('nama', 'id'),
            'ringkas' => [
                'orang' => $baris->where('total', '>', 0)->count(), 'hari' => $baris->sum('hadir'), 'lembur' => $baris->sum('lembur'),
                'total_um' => $baris->sum('total_um'), 'total_lembur' => $baris->sum('total_lembur'), 'total' => $baris->sum('total'),
                'tunggu' => $baris->sum('tunggu'), 'tanpa_um' => $baris->filter(fn ($b) => $b->hadir && ! $b->um)->count(),
                'tanpa_tahap' => $semuaHari->filter(fn ($h) => $h->hadir && ! $h->tahap_id)->count(),
            ],
        ];
    }
}
