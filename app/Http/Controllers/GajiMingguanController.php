<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\JabatanMenu;
use App\Models\Karyawan;
use App\Support\DaftarSopir;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap Gaji Mingguan (owner 27 Okt 2026, versi awal "seadanya"): per karyawan per minggu Senin–Minggu, hari hadir dari absensi
 * (hari yang ada absen masuk atau pulang) × tarif UM per hari dari Master Jabatan. Lembur & hari yang absennya belum lengkap
 * hanya ditampilkan sebagai catatan — belum mengubah hitungan.
 */
class GajiMingguanController extends Controller
{
    public function index(Request $request): View
    {
        $d = $this->data($request);

        return view('karyawan.gaji-mingguan', $d + [
            'jumlah' => ['hadir' => $d['baris']->where('hadir', '>', 0)->count(), 'hari' => $d['baris']->sum('hadir'),
                'total' => $d['baris']->sum('total'), 'tanpa_tarif' => $d['baris']->where('hadir', '>', 0)->whereNull('tarif')->count()],
        ]);
    }

    public function unduh(Request $request): StreamedResponse
    {
        ['senin' => $senin, 'hari' => $hari, 'baris' => $baris] = $this->data($request);
        $minggu = $senin->copy()->addDays(6);

        return response()->streamDownload(function () use ($hari, $baris) {
            $w = new \OpenSpout\Writer\XLSX\Writer;
            $w->openToFile('php://output');
            $tebal = (new \OpenSpout\Common\Entity\Style\Style)->setFontBold();
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Nama', 'Jabatan', 'Site', ...$hari->map(fn ($h) => $h->translatedFormat('D j/n'))->all(),
                'Hari hadir', 'Tarif UM / hari', 'Total UM', 'Lembur (jam)', 'Catatan'], $tebal));
            foreach ($baris as $b) {
                $w->addRow(\OpenSpout\Common\Entity\Row::fromValues([$b->k->nama, $b->k->jabatan, $b->k->site,
                    ...array_map(fn ($x) => $x ? ($x['masuk'] ?? '?').'–'.($x['pulang'] ?? '?') : '', $b->hari),
                    $b->hadir, $b->tarif, $b->total, $b->lembur ? round($b->lembur / 60, 1) : null, implode('; ', $b->catatan)]));
            }
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['TOTAL', '', '', ...array_fill(0, 7, ''), $baris->sum('hadir'), '', $baris->sum('total')], $tebal));
            $w->close();
        }, 'Rekap Gaji Mingguan '.$senin->translatedFormat('j M').' - '.$minggu->translatedFormat('j M Y').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{senin: Carbon, hari: Collection<int, Carbon>, baris: Collection<int, object>} */
    private function data(Request $request): array
    {
        // Bawaan: minggu lalu (minggu ini masih berjalan).
        $senin = (RitasiController::tanggal($request->query('minggu')) ?? today()->subWeek())->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $hari = collect(range(0, 6))->map(fn ($i) => $senin->copy()->addDays($i));
        $absen = Absensi::whereBetween('tanggal', [$senin->toDateString(), $hari->last()->toDateString()])->orderBy('waktu')->get()
            ->groupBy(['karyawan_id', fn ($a) => $a->tanggal->toDateString()]);
        // Sama dengan Review Absen Harian: karyawan aktif kecuali sopir DT + siapa pun yang absen minggu itu.
        $karyawan = Karyawan::where(fn ($q) => $q->where('status', 'aktif')->where(fn ($w) => $w->whereNull('jabatan')->orWhere('jabatan', '!=', DaftarSopir::JABATAN)->orWhereNull('no_mobil')))
            ->orWhereIn('id', $absen->keys())->orderBy('nama')->get();
        $tarif = JabatanMenu::pluck('um_harian', 'jabatan');

        $baris = $karyawan->map(function ($k) use ($absen, $hari, $tarif) {
            $milik = $absen[$k->id] ?? collect();
            $lembur = 0;
            $kurang = [];
            $perHari = $hari->map(function ($h) use ($milik, &$lembur, &$kurang) {
                $a = ($milik[$h->toDateString()] ?? collect())->keyBy('jenis');
                if ($a->isEmpty()) {
                    return null;
                }
                if (isset($a['lembur_mulai'], $a['lembur_selesai'])) {
                    $lembur += $a['lembur_mulai']->waktu->diffInMinutes($a['lembur_selesai']->waktu);
                }
                if (! isset($a['masuk']) || ! isset($a['pulang'])) {
                    $kurang[] = $h->translatedFormat('D j/n').(isset($a['masuk']) ? ' tanpa pulang' : ' tanpa masuk');
                }

                return ['masuk' => $a->get('masuk')?->waktu->format('H:i'), 'pulang' => $a->get('pulang')?->waktu->format('H:i'), 'lembur' => isset($a['lembur_mulai'])];
            })->all();
            $hadir = count(array_filter($perHari));
            $t = $tarif[$k->jabatan] ?? null;
            $catatan = [];
            if ($hadir && ! $t) {
                $catatan[] = $k->jabatan ? "tarif UM jabatan {$k->jabatan} belum diisi" : 'jabatan belum diisi';
            }
            if ($kurang) {
                $catatan[] = 'absen belum lengkap: '.implode(', ', $kurang);
            }

            return (object) ['k' => $k, 'hari' => $perHari, 'hadir' => $hadir, 'tarif' => $t, 'total' => $hadir * (int) $t, 'lembur' => $lembur, 'catatan' => $catatan];
        })->sortBy(fn ($b) => [$b->hadir ? 0 : 1, $b->k->jabatan ?? 'zzz', $b->k->nama])->values();

        return ['senin' => $senin, 'hari' => $hari, 'baris' => $baris];
    }
}
