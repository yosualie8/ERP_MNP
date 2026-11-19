<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Karyawan;
use App\Models\Lapor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Dashboard Foto Absensi (owner 19 Nov 2026): tinjau foto kiriman absen (Lapor Lapangan + absen langsung) per orang per hari,
 * disaring nama dan/atau jabatan (cocok sebagian, mis. "Operator" = semua jabatan operator). Awalnya untuk memeriksa foto HM /
 * speedometer / indikator solar operator alat berat. Hanya melihat.
 */
class DashboardFotoAbsenController extends Controller
{
    /** Rentang terpanjang sekali tampil (hari) — foto banyak, batasi supaya halaman tetap ringan. */
    public const MAKS_HARI = 31;

    public const KOLOM = ['masuk' => 'Masuk', 'pulang' => 'Pulang', 'lembur' => 'Lembur', 'laporan' => 'Laporan / lainnya'];

    public function __invoke(Request $request): View
    {
        $sampai = (RitasiController::tanggal($request->query('sampai')) ?? now())->copy()->startOfDay()->min(now()->startOfDay());
        $dari = (RitasiController::tanggal($request->query('dari')) ?? $sampai->copy()->subDays(6))->copy()->startOfDay();
        if ($dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $dipotong = $dari->diffInDays($sampai) >= self::MAKS_HARI;
        if ($dipotong) {
            $dari = $sampai->copy()->subDays(self::MAKS_HARI - 1);
        }
        $nama = trim((string) $request->query('nama'));
        $jabatan = trim((string) $request->query('jabatan'));
        $jenis = array_key_exists($request->query('jenis'), self::KOLOM) ? $request->query('jenis') : null;
        $rentang = [$dari->toDateString(), $sampai->toDateString()];

        $orang = Karyawan::query()
            ->when($nama !== '', fn ($q) => $q->where('nama', 'like', '%'.$nama.'%'))
            ->when($jabatan !== '', fn ($q) => $q->where('jabatan', 'like', '%'.$jabatan.'%'))
            ->get(['id', 'nama', 'jabatan', 'status'])->keyBy('id');

        $lapor = Lapor::with('foto:id,lapor_id,urutan,sumber,kamera')
            ->whereIn('karyawan_id', $orang->keys())->whereBetween('tanggal', $rentang)
            ->orderBy('waktu')->get(['id', 'karyawan_id', 'tanggal', 'waktu', 'jenis', 'jenis_admin', 'keterangan', 'di_lokasi', 'alamat', 'lokasi_absen_id', 'proyek_tahap_id']);
        // Absen langsung (menu Absen lama, selfie satu foto) — yang dari Lapor Lapangan sudah tampil lewat kirimannya.
        $langsung = Absensi::whereIn('karyawan_id', $orang->keys())->whereBetween('tanggal', $rentang)->whereNull('lapor_id')
            ->where('foto', '!=', '')->orderBy('waktu')->get(['id', 'karyawan_id', 'tanggal', 'waktu', 'jenis']);

        $kolomLapor = fn (Lapor $l) => match ($l->jenisAkhir()) { 'masuk' => 'masuk', 'pulang' => 'pulang', 'lembur' => 'lembur', default => 'laporan' };
        $item = $lapor->map(fn (Lapor $l) => (object) [
            'karyawan_id' => $l->karyawan_id, 'tanggal' => $l->tanggal->toDateString(), 'waktu' => $l->waktu, 'kolom' => $kolomLapor($l),
            'label' => $l->jenisAkhir() === 'abaikan' ? 'diabaikan admin' : null, 'keterangan' => $l->keterangan, 'luar' => $l->di_lokasi === false,
            'foto' => $l->foto->map(fn ($f) => ['kecil' => route('lapor.foto', ['foto' => $f, 'kecil' => 1]), 'besar' => route('lapor.foto', $f),
                'tanda' => $f->sumber === 'galeri' ? 'galeri' : ($f->kamera ?: 'kamera')])->all(),
        ])->concat($langsung->map(fn (Absensi $a) => (object) [
            'karyawan_id' => $a->karyawan_id, 'tanggal' => $a->tanggal->toDateString(), 'waktu' => $a->waktu,
            'kolom' => str_starts_with($a->jenis, 'lembur') ? 'lembur' : $a->jenis, 'label' => 'absen langsung', 'keterangan' => null, 'luar' => false,
            'foto' => [['kecil' => route('absen.foto', $a), 'besar' => route('absen.foto', $a), 'tanda' => 'selfie']],
        ]))->when($jenis, fn ($c) => $c->where('kolom', $jenis));

        // Satu baris = satu orang pada satu tanggal; terbaru di atas, lalu urut nama.
        $baris = $item->groupBy(fn ($i) => $i->tanggal.'|'.$i->karyawan_id)
            ->map(fn ($g) => (object) ['tanggal' => $g->first()->tanggal, 'k' => $orang[$g->first()->karyawan_id],
                'kolom' => $g->sortBy(fn ($i) => $i->waktu->getTimestamp())->groupBy('kolom')])
            ->sortBy([['tanggal', 'desc'], [fn ($a, $b) => strcasecmp($a->k->nama, $b->k->nama)]])->values();

        $aktifTanpaKiriman = $orang->where('status', 'aktif')->keys()->diff($item->pluck('karyawan_id')->unique())
            ->map(fn ($id) => $orang[$id])->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return view('dashboard.foto-absen', [
            'dari' => $dari, 'sampai' => $sampai, 'dipotong' => $dipotong, 'nama' => $nama, 'jabatan' => $jabatan, 'jenis' => $jenis,
            'baris' => $baris, 'tanpaKiriman' => ($nama !== '' || $jabatan !== '') ? $aktifTanpaKiriman : collect(),
            'ringkas' => ['orang' => $item->pluck('karyawan_id')->unique()->count(), 'hari' => $baris->count(), 'kiriman' => $item->count(),
                'foto' => $item->sum(fn ($i) => count($i->foto)), 'tanpaFoto' => $item->filter(fn ($i) => ! $i->foto)->count()],
            'daftarNama' => Karyawan::where('status', 'aktif')->orderBy('nama')->pluck('nama'),
            'daftarJabatan' => Karyawan::whereNotNull('jabatan')->where('jabatan', '!=', '')->distinct()->orderBy('jabatan')->pluck('jabatan'),
        ]);
    }

    /** Hari dalam rentang untuk tautan cepat (7 / 14 / 31 hari terakhir). */
    public static function rentangCepat(int $hari): array
    {
        return ['dari' => Carbon::today()->subDays($hari - 1)->toDateString(), 'sampai' => Carbon::today()->toDateString()];
    }
}
