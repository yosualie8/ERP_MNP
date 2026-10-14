<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Models\KasRiwayat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Data Karyawan: data induk karyawan MNP (dasar modul absensi) — sumber utama di aplikasi. */
class KaryawanController extends Controller
{
    public function index(Request $request): View
    {
        $status = array_key_exists($request->query('status'), Karyawan::STATUS) ? $request->query('status') : (($request->query('status') === 'semua') ? null : 'aktif');
        $semua = Karyawan::orderBy('nama')->get();
        $nikDobel = $semua->whereNotNull('nik')->groupBy('nik')->filter(fn ($g) => $g->count() > 1)->keys()->flip();

        return view('karyawan.index', [
            'karyawan' => $semua->when($status, fn ($c) => $c->where('status', $status))->values(),
            'semua' => $semua, 'status' => $status, 'nikDobel' => $nikDobel, 'bolehUbah' => $request->user()->bolehMenu('karyawan'),
        ]);
    }

    public function create(): View
    {
        return view('karyawan.form', ['karyawan' => new Karyawan(['status' => 'aktif']), ...$this->saran()]);
    }

    public function edit(Karyawan $karyawan): View
    {
        return view('karyawan.form', ['karyawan' => $karyawan, ...$this->saran()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $k = Karyawan::create([...$this->baca($request, null), 'user_id' => $request->user()->id]);
        $this->catat($request, 'karyawan-tambah', "Karyawan baru {$k->nama}", $k->toArray());

        return redirect()->route('karyawan.index')->with('success', "{$k->nama} ditambahkan ke Data Karyawan.");
    }

    public function update(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $lama = $karyawan->toArray();
        $karyawan->update([...$this->baca($request, $karyawan), 'user_id' => $request->user()->id]);
        $berubah = collect($karyawan->getChanges())->except(['updated_at', 'user_id'])->keys()->implode(', ');
        $this->catat($request, 'karyawan-ubah', "Karyawan {$karyawan->nama} diubah: ".($berubah ?: 'tanpa perubahan'), ['sebelum' => $lama, 'sesudah' => $karyawan->fresh()->toArray()]);

        return redirect()->route('karyawan.index')->with('success', "Data {$karyawan->nama} disimpan.");
    }

    private function baca(Request $request, ?Karyawan $k): array
    {
        $t = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $request->merge([
            'nama' => $t($request->input('nama')), 'email' => ($e = strtolower(trim((string) $request->input('email')))) === '' ? null : $e, 'nik' => ($n = preg_replace('/\s+/', '', (string) $request->input('nik'))) === '' ? null : $n,
            'npwp' => $t($request->input('npwp')), 'site' => Karyawan::rapikanSite($request->input('site')), 'jabatan' => $t($request->input('jabatan')),
            'bank' => $t($request->input('bank')), 'no_rekening' => ($r = preg_replace('/\s+/', '', (string) $request->input('no_rekening'))) === '' ? null : $r,
            'nama_rekening' => $t($request->input('nama_rekening')),
        ]);

        return $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('karyawan', 'email')->ignore($k?->id)],
            // NIK unik — kecuali NIK lama yang memang sudah dobel sejak impor & tidak diubah (supaya data lain tetap bisa diedit).
            'nik' => ['nullable', 'regex:/^\d{16}$/', ...($k && $request->input('nik') === $k->nik ? [] : [Rule::unique('karyawan', 'nik')->ignore($k?->id)])],
            'npwp' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(array_keys(Karyawan::STATUS))],
            'site' => ['nullable', 'string', 'max:40'], 'jabatan' => ['nullable', 'string', 'max:80'],
            'bank' => ['nullable', 'string', 'max:40'], 'no_rekening' => ['nullable', 'regex:/^\d{5,30}$/'],
            'nama_rekening' => ['nullable', 'string', 'max:120'],
        ], [
            'nama.required' => 'Nama belum diisi.',
            'nik.regex' => 'NIK harus 16 digit angka (sesuai KTP).',
            'nik.unique' => 'NIK ini sudah dipakai karyawan lain — periksa KTP-nya.',
            'email.email' => 'Email tidak valid.', 'email.unique' => 'Email ini sudah dipakai karyawan lain.',
            'no_rekening.regex' => 'No rekening hanya angka (5–30 digit).',
        ]);
    }

    private function saran(): array
    {
        return [
            'saranSite' => collect(Karyawan::SITE)->merge(Karyawan::whereNotNull('site')->distinct()->pluck('site'))->unique()->sort()->values(),
            'saranBank' => Karyawan::whereNotNull('bank')->select('bank')->groupBy('bank')->orderByRaw('COUNT(*) DESC')->pluck('bank'),
            'saranJabatan' => Karyawan::whereNotNull('jabatan')->distinct()->orderBy('jabatan')->pluck('jabatan'),
        ];
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Karyawan', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
