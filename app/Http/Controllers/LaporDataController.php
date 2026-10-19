<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\BoqItem;
use App\Models\KasRiwayat;
use App\Models\Lapor;
use App\Models\LokasiAbsen;
use App\Models\PengaturanApp;
use App\Models\ProyekTahap;
use App\Support\PilahLapor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Data Lapor Lapangan (admin): semua kiriman karyawan per tanggal & proyek, hasil pilah sistem (masuk / pulang / lembur /
 * laporan), koreksi jenis, kaitkan kiriman progres ke item BOQ, dan jam kerja standar / per proyek.
 */
class LaporDataController extends Controller
{
    public function index(Request $request): View
    {
        $tanggal = (RitasiController::tanggal($request->query('tanggal')) ?? now())->copy()->startOfDay();
        $tahapId = $request->integer('tahap') ?: null;
        $semua = Lapor::with(['foto', 'karyawan', 'tahap', 'lokasi.tahap', 'boq'])->whereDate('tanggal', $tanggal)->orderBy('waktu')->get();
        $kiriman = $tahapId ? $semua->where('proyek_tahap_id', $tahapId) : $semua;
        $absen = Absensi::whereDate('tanggal', $tanggal)->whereIn('karyawan_id', $kiriman->pluck('karyawan_id')->unique())->orderBy('waktu')->get()->groupBy('karyawan_id');
        $tahapIds = $semua->pluck('proyek_tahap_id')->filter()->unique();

        return view('lapor.data', [
            'tanggal' => $tanggal, 'tahapId' => $tahapId,
            'perKaryawan' => $kiriman->groupBy('karyawan_id')->sortBy(fn ($g) => $g->first()->karyawan?->nama),
            'absen' => $absen,
            'proyek' => ProyekTahap::whereIn('id', $tahapIds)->orderBy('nama')->get()->map(fn ($t) => ['t' => $t, 'n' => $semua->where('proyek_tahap_id', $t->id)->count()]),
            'tanpaProyek' => $semua->whereNull('proyek_tahap_id')->count(),
            'boq' => BoqItem::whereIn('proyek_tahap_id', $tahapIds)->orderBy('urutan')->orderBy('id')->get()->groupBy('proyek_tahap_id'),
            'ringkas' => ['kiriman' => $kiriman->count(), 'foto' => $kiriman->sum(fn ($l) => $l->foto->count()), 'karyawan' => $kiriman->pluck('karyawan_id')->unique()->count(),
                'laporan' => $kiriman->filter(fn ($l) => $l->jenisAkhir() === 'laporan')->count(), 'luar' => $kiriman->where('di_lokasi', false)->count()],
            'jam' => PilahLapor::jam(),
            'tahapLokasi' => ProyekTahap::whereIn('id', LokasiAbsen::where('aktif', true)->whereNotNull('proyek_tahap_id')->pluck('proyek_tahap_id'))->orderBy('nama')->get(),
        ]);
    }

    /** Koreksi satu kiriman: jenis (atau kembali otomatis), item BOQ untuk progres, catatan; lalu hari itu dipilah ulang. */
    public function ubah(Request $request, Lapor $lapor): RedirectResponse
    {
        $d = $request->validate(['jenis_admin' => ['nullable', 'in:'.implode(',', array_keys(Lapor::JENIS_ADMIN))], 'boq_item_id' => ['nullable', 'integer', 'exists:boq_item,id'],
            'catatan_admin' => ['nullable', 'string', 'max:300']]);
        $lama = $lapor->only(['jenis', 'jenis_admin', 'boq_item_id', 'catatan_admin']);
        $lapor->update(['jenis_admin' => $d['jenis_admin'] ?? null, 'boq_item_id' => $d['boq_item_id'] ?? null, 'catatan_admin' => $d['catatan_admin'] ?? null]);
        PilahLapor::hari($lapor->karyawan_id, $lapor->tanggal->toDateString());
        KasRiwayat::create(['aksi' => 'lapor-ubah', 'lembar' => 'Absen', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("Lapor #{$lapor->id} {$lapor->karyawan?->nama} {$lapor->waktu->format('d-m H:i')}: jenis ".($d['jenis_admin'] ?? 'otomatis')
                .($d['boq_item_id'] ?? null ? ', item BOQ '.BoqItem::find($d['boq_item_id'])?->label() : '').(($d['catatan_admin'] ?? null) ? " — {$d['catatan_admin']}" : ''), 0, 490, '…'),
            'isi' => ['lapor_id' => $lapor->id, 'lama' => $lama, 'baru' => $lapor->only(['jenis', 'jenis_admin', 'boq_item_id', 'catatan_admin'])], 'user_id' => $request->user()->id]);

        return back()->with('success', "Kiriman {$lapor->karyawan?->nama} pukul {$lapor->waktu->format('H:i')} disimpan — absen hari itu dipilah ulang.");
    }

    /** Jam kerja standar & per proyek; semua kiriman 31 hari terakhir dipilah ulang dengan jam baru. */
    public function pengaturan(Request $request): RedirectResponse
    {
        $jam = ['nullable', 'date_format:H:i'];
        $d = $request->validate(['jam_masuk' => ['required', 'date_format:H:i'], 'jam_pulang' => ['required', 'date_format:H:i'], 'toleransi' => ['required', 'integer', 'min:0', 'max:240'],
            'tahap' => ['array'], 'tahap.*.masuk' => $jam, 'tahap.*.pulang' => $jam], ['*.date_format' => 'Format jam HH:MM, mis. 08:00.']);
        PengaturanApp::simpan('absen_jam_masuk', $d['jam_masuk'], $request->user()->id);
        PengaturanApp::simpan('absen_jam_pulang', $d['jam_pulang'], $request->user()->id);
        PengaturanApp::simpan('absen_toleransi_lembur', (string) $d['toleransi'], $request->user()->id);
        foreach ($d['tahap'] ?? [] as $id => $x) {
            ProyekTahap::whereKey($id)->update(['jam_masuk' => $x['masuk'] ?: null, 'jam_pulang' => $x['pulang'] ?: null]);
        }
        $n = 0;
        foreach (Lapor::where('tanggal', '>=', now()->subDays(31)->toDateString())->select('karyawan_id', 'tanggal')->distinct()->get() as $x) {
            PilahLapor::hari($x->karyawan_id, $x->tanggal->toDateString());
            $n++;
        }
        KasRiwayat::create(['aksi' => 'lapor-jam', 'lembar' => 'Absen', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Jam kerja standar {$d['jam_masuk']}–{$d['jam_pulang']}, toleransi lembur {$d['toleransi']} menit; {$n} hari-karyawan dipilah ulang",
            'isi' => $d, 'user_id' => $request->user()->id]);

        return back()->with('success', "Jam kerja disimpan. {$n} hari-karyawan (31 hari terakhir) dipilah ulang.");
    }
}
