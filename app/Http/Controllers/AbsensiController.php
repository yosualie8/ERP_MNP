<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Karyawan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Data Absensi (admin): per tanggal — jam masuk/pulang/lembur tiap karyawan aktif, lokasi, jarak & selfie; yang tidak absen. */
class AbsensiController extends Controller
{
    public function index(Request $request): View
    {
        $tanggal = (RitasiController::tanggal($request->query('tanggal')) ?? now())->copy()->startOfDay();
        $absen = Absensi::with('lokasi.tahap')->whereDate('tanggal', $tanggal)->orderBy('waktu')->get()->groupBy('karyawan_id');
        $karyawan = Karyawan::where('status', 'aktif')->orWhereIn('id', $absen->keys())->orderBy('nama')->get();
        $baris = $karyawan->map(fn ($k) => ['k' => $k, 'absen' => ($absen[$k->id] ?? collect())->keyBy('jenis')]);

        return view('absensi.index', [
            'tanggal' => $tanggal, 'baris' => $baris,
            'hadir' => $absen->count(), 'aktif' => Karyawan::where('status', 'aktif')->count(),
            'tanpaEmail' => Karyawan::where('status', 'aktif')->whereNull('email')->count(),
        ]);
    }
}
