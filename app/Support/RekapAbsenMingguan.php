<?php

namespace App\Support;

use App\Models\Absensi;
use App\Models\JabatanMenu;
use App\Models\Karyawan;
use App\Models\Lapor;
use App\Models\LokasiAbsen;
use App\Models\ProyekTahap;
use App\Models\RekapAbsen;
use App\Models\TunjanganMingguan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap absen mingguan (Senin–Minggu) → penggajian UM (owner 30 Okt 2026):
 * - UM per hari = karyawan.um_harian, bila kosong tarif UM jabatannya (Master Jabatan) — jabatan sama bisa beda UM.
 * - Hari dibayar = absen masuk & pulang lengkap. Absen belum lengkap MENUNGGU persetujuan admin (rekap_absen.hadir).
 *   Tukang tanpa absen HP diisi mandor (rekap_absen sumber "mandor"). Admin boleh menimpa hari mana pun (bayar / tidak).
 * - UM satu hari boleh diubah admin dengan alasan (owner 11 Nov 2026, rekap_absen.bayar_ubah): menimpa UM orang itu khusus hari itu
 *   dan membuat hari itu dibayar; lembur tetap ½ UM biasa, HM & tunjangan mingguan ikut aturannya.
 * - Lembur = ½ UM per hari (mulai & selesai lembur lengkap; yang belum lengkap menunggu persetujuan), hanya bila hari itu dibayar.
 * - Tahap kerja per hari: isian admin/mandor → tahap kiriman Lapor Lapangan / lokasi absen → tahap hari kerja sebelumnya.
 * - Tukang TANPA AKUN absen (owner 9 Nov 2026): jam kehadirannya IKUT MANDOR — absen HP pengisi (bila Mandor / Pengawas), kalau tidak
 *   Mandor → Pengawas → siapa pun yang absen HP di tahap yang sama hari itu (jam_mandor). Hanya untuk tukang tanpa akun (bukan pengawas /
 *   operator / yang wajib absen sendiri). Hanya keterangan; yang dibayar tetap isian mandor / admin.
 * - Operator alat berat (owner 3 Nov 2026): + basic, transport, uang kesehatan per minggu (tarif jabatan, dibayar bila minggu itu
 *   ada hari dibayar; admin boleh mengubahnya per orang per minggu (tunjangan_mingguan); dibebankan rata ke tahap hari-hari kerjanya)
 *   + HM × tarif HM jabatan (HM per hari diisi admin, hanya hari dibayar).
 */
class RekapAbsenMingguan
{
    public const BELUM_TAHAP = '(belum ada tahap)';

    /** Jabatan yang jam absennya diikuti tukang yang diisi mandor (urut prioritas). */
    public const JABATAN_MANDOR = ['Mandor', 'Pengawas'];

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
        // Jam koreksi admin (absen_koreksi) ikut dihitung; absen asli tetap tersimpan (owner 9 Nov 2026).
        $absen = Absensi::denganKoreksi(Absensi::whereBetween('tanggal', $rentang)->orderBy('waktu')->get(), $rentang)
            ->groupBy(['karyawan_id', fn ($a) => $a->tanggal->toDateString()]);
        $rekap = RekapAbsen::whereBetween('tanggal', $rentang)->get()->groupBy(['karyawan_id', fn ($r) => $r->tanggal->toDateString()]);
        $tahapLapor = Lapor::whereIn('id', $absen->flatten()->pluck('lapor_id')->filter()->unique())->pluck('proyek_tahap_id', 'id');
        $tahapLokasi = LokasiAbsen::pluck('proyek_tahap_id', 'id');
        // Tahap terakhir sebelum minggu ini (60 hari) → bawaan hari pertama.
        $tahapLalu = RekapAbsen::where('tanggal', '<', $senin->toDateString())->where('tanggal', '>=', $senin->copy()->subDays(60)->toDateString())
            ->whereNotNull('proyek_tahap_id')->orderBy('tanggal')->get(['karyawan_id', 'proyek_tahap_id'])->keyBy('karyawan_id')->map->proyek_tahap_id;

        // Karyawan aktif kecuali sopir DT (sama dengan Review Absen) + siapa pun yang absen / direkap minggu itu.
        $karyawan = Karyawan::where(fn ($q) => $q->where('status', 'aktif')->where(fn ($w) => $w->whereNull('jabatan')->orWhere('jabatan', '!=', DaftarSopir::JABATAN)->orWhereNull('no_mobil')))
            ->orWhereIn('id', $absen->keys()->concat($rekap->keys())->unique())->orderBy('nama')->get();
        $jabatan = JabatanMenu::get()->keyBy('jabatan');
        $tarif = $jabatan->map->um_harian;
        $tunjangan = TunjanganMingguan::where('senin', $senin->toDateString())->get()->keyBy('karyawan_id');
        $namaTahap = ProyekTahap::pluck('nama', 'id');

        $baris = $karyawan->map(function ($k) use ($absen, $rekap, $hari, $jabatan, $tarif, $tunjangan, $tahapLapor, $tahapLokasi, $tahapLalu, $namaTahap) {
            $um = self::um($k, $tarif);
            $j = $jabatan[$k->jabatan] ?? null;
            $tarifHm = (int) ($j?->tarif_hm ?? 0);
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
                $ubahBayar = $r?->bayar_ubah;
                $hadir = $putusHadir ? $r->hadir : ($absenHadir === 'lengkap' || $ubahBayar !== null);
                $lembur = $hadir && ($putusLembur ? $r->lembur : $absenLembur === 'lengkap');
                $tungguHadir = ! $putusHadir && $ubahBayar === null && $absenHadir === 'kurang';
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
                    'putus_lembur' => $putusLembur ? $r->lembur : null, 'sumber' => $r?->sumber, 'catatan' => $r?->catatan, 'oleh' => $r?->user_id, 'jam_mandor' => null,
                    'tahap_id' => $tahap, 'tahap' => $tahap ? ($namaTahap[$tahap] ?? null) : null, 'tahap_diisi' => (bool) $r?->proyek_tahap_id,
                    'bayar' => $hadir ? (int) ($ubahBayar ?? $um) : 0, 'bayar_lembur' => $lembur ? (int) round($um / 2) : 0,
                    'bayar_ubah' => $ubahBayar, 'bayar_alasan' => $r?->bayar_alasan,
                    // Per jenis absen: jam berlaku, jam asli (null = tidak ada absen asli), penanda koreksi admin.
                    'jam' => collect(Absensi::JENIS)->map(fn ($l, $j) => ($x = $a->get($j)) ? ['jam' => $x->waktu->format('H:i'), 'asli' => $x->koreksiAdmin()
                        ? $x->getAttribute('waktu_asli')?->format('H:i') : $x->waktu->format('H:i'), 'diedit' => (bool) $x->koreksiAdmin(), 'ket' => $x->catatanKoreksi(),
                        'catatan' => $x->koreksiAdmin()?->catatan] : null)->all(),
                    'diedit' => $a->contains(fn ($x) => $x->koreksiAdmin()),
                    'hm' => $r?->hm, 'bayar_hm' => $hadir && $r?->hm ? (int) round($r->hm * $tarifHm) : 0, 'bayar_mingguan' => 0,
                ];
            }
            $x = collect($perHari);
            // Komponen mingguan (operator): dibayar penuh bila ada hari dibayar (atau sesuai ubahan admin minggu itu),
            // dibebankan rata ke hari-hari itu (sisa bagi ke hari pertama).
            $ubah = $tunjangan[$k->id] ?? null;
            $mingguan = collect(JabatanMenu::MINGGUAN)->mapWithKeys(fn ($l, $kol) => [$kol => (int) ($ubah?->{$kol} ?? $j?->{$kol} ?? 0)]);
            $bawaan = collect(JabatanMenu::MINGGUAN)->mapWithKeys(fn ($l, $kol) => [$kol => $j?->{$kol}]);
            $dibayar = $x->where('hadir', true);
            $totalMingguan = $dibayar->isNotEmpty() ? $mingguan->sum() : 0;
            foreach ($dibayar->keys()->values() as $i => $d) {
                $perHari[$d]->bayar_mingguan = intdiv($totalMingguan, $dibayar->count()) + ($i === 0 ? $totalMingguan % $dibayar->count() : 0);
            }
            foreach ($perHari as $h) {
                $h->jumlah = $h->bayar + $h->bayar_lembur + $h->bayar_hm + $h->bayar_mingguan;
            }

            return (object) [
                'k' => $k, 'um' => $um, 'um_sendiri' => (bool) $k->um_harian, 'hari' => $perHari, 'tarif_hm' => $tarifHm,
                'operator' => $tarifHm > 0 || $bawaan->filter()->isNotEmpty() || $ubah, 'tunjangan_ubah' => $ubah, 'tunjangan_bawaan' => $bawaan->all(), 'mingguan' => $dibayar->isNotEmpty() ? $mingguan->all() : array_fill_keys($mingguan->keys()->all(), 0),
                'total_mingguan' => $totalMingguan, 'jam_hm' => $tarifHm ? round($x->where('hadir', true)->sum('hm'), 1) : 0, 'total_hm' => $x->sum('bayar_hm'),
                'hadir' => $x->where('hadir', true)->count(), 'lembur' => $x->where('lembur', true)->count(),
                'tunggu' => $x->where('tunggu', true)->count() + $x->where('tunggu_lembur', true)->count(),
                'total_um' => $x->sum('bayar'), 'total_lembur' => $x->sum('bayar_lembur'), 'total' => $x->sum('jumlah'),
                'ada' => $x->contains(fn ($h) => $h->absen || $h->absen_lembur || $h->sumber || $h->hadir || $h->hm),
                'tanpa_um' => ! $um && $x->contains(fn ($h) => $h->hadir && $h->bayar_ubah === null),
            ];
        })->sortBy(fn ($b) => [$b->ada ? 0 : 1, $b->k->site ?? 'zzz', $b->k->nama])->values();

        self::jamIkutMandor($baris);
        $semuaHari = $baris->flatMap(fn ($b) => array_values($b->hari));
        $perTahap = $semuaHari->filter(fn ($h) => $h->jumlah > 0)->groupBy(fn ($h) => $h->tahap ?? self::BELUM_TAHAP)
            ->map(fn ($g) => $g->sum('jumlah'))->sortDesc();

        return [
            'senin' => $senin, 'hari' => $hari, 'baris' => $baris, 'perTahap' => $perTahap,
            'tahap' => ProyekTahap::whereIn('status', ['belum', 'progress'])->orWhereIn('id', $semuaHari->pluck('tahap_id')->filter()->unique())
                ->orderBy('nama')->pluck('nama', 'id'),
            'ringkas' => [
                'orang' => $baris->where('total', '>', 0)->count(), 'hari' => $baris->sum('hadir'), 'lembur' => $baris->sum('lembur'),
                'total_um' => $baris->sum('total_um'), 'total_lembur' => $baris->sum('total_lembur'), 'total' => $baris->sum('total'),
                'total_mingguan' => $baris->sum('total_mingguan'), 'total_hm' => $baris->sum('total_hm'), 'jam_hm' => round($baris->sum('jam_hm'), 1),
                'operator' => $baris->filter(fn ($b) => $b->total_mingguan || $b->total_hm)->count(),
                // HM diisi pada hari yang tidak dibayar → tidak ikut dihitung (perlu dicek admin).
                'hm_tak_dibayar' => $semuaHari->filter(fn ($h) => $h->hm && ! $h->hadir)->count(),
                'tunggu' => $baris->sum('tunggu'), 'tanpa_um' => $baris->where('tanpa_um', true)->count(),
                'bayar_diubah' => $semuaHari->filter(fn ($h) => $h->hadir && $h->bayar_ubah !== null)->count(),
                'tanpa_tahap' => $semuaHari->filter(fn ($h) => $h->hadir && ! $h->tahap_id)->count(),
            ],
        ];
    }

    /**
     * Isi jam_mandor ['nama', 'masuk', 'pulang'] untuk hari dibayar milik tukang TANPA AKUN absen (email kosong; bukan Mandor / Pengawas /
     * Operator): pengisi absen tukang bila ia Mandor / Pengawas yang absen hari itu, kalau tidak yang absen HP di tahap yang sama hari itu —
     * Mandor dulu, lalu Pengawas, lalu siapa pun (operator, surveyor, …); absen lengkap didahulukan.
     */
    private static function jamIkutMandor(Collection $baris): void
    {
        $urutan = array_flip(self::JABATAN_MANDOR);
        $lain = count($urutan);
        [$perOrang, $perTahap] = [[], []];
        foreach ($baris as $b) {
            $tingkat = $urutan[$b->k->jabatan] ?? $lain;
            foreach ($b->hari as $d => $h) {
                if ((! $h->masuk && ! $h->pulang) || $h->hadir === false) {
                    continue;
                }
                $calon = ['nama' => $b->k->nama, 'masuk' => $h->masuk, 'pulang' => $h->pulang, 'skor' => [$tingkat, $h->masuk && $h->pulang ? 0 : 1]];
                if ($tingkat < $lain) {
                    $perOrang[$b->k->id][$d] = $calon;
                }
                if ($h->tahap_id && (! isset($perTahap[$d][$h->tahap_id]) || $calon['skor'] < $perTahap[$d][$h->tahap_id]['skor'])) {
                    $perTahap[$d][$h->tahap_id] = $calon;
                }
            }
        }
        if (! $perTahap) {
            return;
        }
        $pengisi = [];
        $userIds = $baris->flatMap(fn ($b) => collect($b->hari)->pluck('oleh'))->filter()->unique();
        foreach (\App\Models\User::whereIn('id', $userIds)->pluck('email', 'id') as $uid => $email) {
            $pengisi[$uid] = Karyawan::where('email', strtolower((string) $email))->where('status', 'aktif')->value('id');
        }
        foreach ($baris as $b) {
            // Hanya tukang tanpa akun absen; pengawas / operator / yang punya akun wajib absen sendiri.
            if (isset($urutan[$b->k->jabatan]) || str_contains(mb_strtolower((string) $b->k->jabatan), 'operator') || trim((string) $b->k->email) !== '') {
                continue;
            }
            foreach ($b->hari as $d => $h) {
                if (! $h->hadir || $h->masuk || $h->pulang) {
                    continue;
                }
                $kp = $h->oleh ? ($pengisi[$h->oleh] ?? null) : null;
                $ikut = ($kp ? ($perOrang[$kp][$d] ?? null) : null) ?? ($h->tahap_id ? ($perTahap[$d][$h->tahap_id] ?? null) : null);
                $h->jam_mandor = $ikut ? ['nama' => $ikut['nama'], 'masuk' => $ikut['masuk'], 'pulang' => $ikut['pulang']] : null;
            }
        }
    }
}
