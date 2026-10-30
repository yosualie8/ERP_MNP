<?php

namespace App\Http\Controllers;

use App\Models\JabatanMenu;
use App\Models\Karyawan;
use App\Models\ProyekTahap;
use App\Models\RekapAbsen;
use App\Support\DaftarSopir;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Absen Tukang oleh Mandor (owner 30 Okt 2026): tukang / helper yang tidak absen dari HP sendiri dicentang hadir (dan lembur) per
 * tanggal oleh mandor, beserta tahap kerjanya. Disimpan di rekap_absen (sumber "mandor") dan langsung dihitung di Rekap Absen &
 * Gaji Mingguan. Hari yang sudah diputuskan admin tidak diubah mandor. Mandor boleh menambah tukang baru ke Data Karyawan.
 */
class AbsenMandorController extends Controller
{
    public function index(Request $request): View
    {
        $tanggal = $this->tanggal($request->query('tanggal'));
        $rekap = RekapAbsen::where('tanggal', $tanggal->toDateString())->get()->keyBy('karyawan_id');
        // Yang tidak punya akun absen HP (email kosong): aktif, bukan sopir DT; ditambah yang sudah direkap tanggal itu.
        $orang = Karyawan::where(fn ($q) => $q->where('status', 'aktif')->where(fn ($w) => $w->whereNull('email')->orWhere('email', ''))
            ->where(fn ($w) => $w->whereNull('jabatan')->orWhere('jabatan', '!=', DaftarSopir::JABATAN)))
            ->orWhereIn('id', $rekap->where('sumber', 'mandor')->keys())->orderBy('site')->orderBy('nama')->get();
        $tahapAkhir = RekapAbsen::where('user_id', $request->user()->id)->where('sumber', 'mandor')->whereNotNull('proyek_tahap_id')->latest('updated_at')->value('proyek_tahap_id');

        return view('karyawan.absen-mandor', [
            'tanggal' => $tanggal, 'orang' => $orang, 'rekap' => $rekap, 'tahapAkhir' => $tahapAkhir,
            'tahap' => ProyekTahap::whereIn('status', ['belum', 'progress'])->orWhereIn('id', $rekap->pluck('proyek_tahap_id')->filter())->orderBy('nama')->pluck('nama', 'id'),
            'jabatan' => JabatanMenu::orderBy('jabatan')->pluck('jabatan'),
            'site' => Karyawan::whereNotNull('site')->distinct()->orderBy('site')->pluck('site'),
            'siteSaya' => $request->user()->karyawanAktif()?->site,
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'tanggal' => ['required', 'date', 'before_or_equal:today'], 'tahap' => ['nullable', 'integer', 'exists:proyek_tahap,id'],
            'orang' => ['array'], 'orang.*.hadir' => ['nullable', 'boolean'], 'orang.*.lembur' => ['nullable', 'boolean'],
            'orang.*.tahap' => ['nullable', 'integer', 'exists:proyek_tahap,id'],
        ]);
        $tanggal = Carbon::parse($v['tanggal'])->toDateString();
        $ada = RekapAbsen::where('tanggal', $tanggal)->whereIn('karyawan_id', array_keys($v['orang'] ?? []))->get()->keyBy('karyawan_id');
        [$hadir, $dilewati] = [0, 0];
        foreach ($v['orang'] ?? [] as $id => $o) {
            $r = $ada[$id] ?? null;
            // Hari yang sudah diputuskan admin (bayar / tidak) tidak diubah mandor.
            if ($r && $r->sumber === 'admin' && $r->hadir !== null) {
                $dilewati++;

                continue;
            }
            if (! empty($o['hadir'])) {
                $r ??= new RekapAbsen(['karyawan_id' => (int) $id, 'tanggal' => $tanggal]);
                $r->fill(['hadir' => true, 'lembur' => ! empty($o['lembur']), 'proyek_tahap_id' => ($o['tahap'] ?? null) ?: ($v['tahap'] ?? null),
                    'sumber' => 'mandor', 'user_id' => $request->user()->id])->save();
                $hadir++;
            } elseif ($r && $r->sumber === 'mandor') {
                $r->delete();
            }
        }

        return redirect()->route('absen-mandor.index', ['tanggal' => $tanggal])
            ->with('success', "Absen {$hadir} orang tanggal ".Carbon::parse($tanggal)->translatedFormat('j M Y').' tersimpan.'
                .($dilewati ? " {$dilewati} orang tidak diubah karena sudah diputuskan admin." : ''));
    }

    /** Tambah tukang / helper baru ke Data Karyawan (aktif, tanpa akun absen HP). */
    public function tambah(Request $request): RedirectResponse
    {
        $v = $request->validate(['nama' => ['required', 'string', 'max:100'], 'jabatan' => ['required', 'string', 'max:60'], 'site' => ['nullable', 'string', 'max:40'],
            'tanggal' => ['nullable', 'date']]);
        $nama = trim(preg_replace('/\s+/', ' ', $v['nama']));
        if ($k = Karyawan::whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->first()) {
            return back()->withInput()->with('error', "Nama {$nama} sudah ada di Data Karyawan (".($k->jabatan ?? 'tanpa jabatan').', '.($k->status === 'aktif' ? 'aktif' : 'nonaktif').').');
        }
        Karyawan::create(['nama' => $nama, 'jabatan' => trim($v['jabatan']), 'site' => Karyawan::rapikanSite($v['site'] ?? null), 'status' => 'aktif']);

        return redirect()->route('absen-mandor.index', array_filter(['tanggal' => $v['tanggal'] ?? null]))->with('success', "{$nama} ditambahkan ke Data Karyawan.");
    }

    private function tanggal(?string $v): Carbon
    {
        $t = $v ? RitasiController::tanggal($v) : null;

        return ($t && $t->lte(today()) ? $t : today())->copy()->startOfDay();
    }
}
