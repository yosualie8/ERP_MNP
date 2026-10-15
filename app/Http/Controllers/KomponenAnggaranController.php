<?php

namespace App\Http\Controllers;

use App\Models\AnggaranTahap;
use App\Models\KasRiwayat;
use App\Models\KomponenAnggaran;
use App\Models\ProyekTahap;
use App\Support\Anggaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Master Komponen Anggaran: komponen HPP & omset, sumber realisasinya, cara hitung, nilai bawaan & template per tipe tahap. */
class KomponenAnggaranController extends Controller
{
    public function index(): View
    {
        return view('komponen-anggaran.index', [
            'komponen' => KomponenAnggaran::orderBy('jenis', 'desc')->orderBy('urutan')->get(),
            'dipakai' => AnggaranTahap::selectRaw('komponen_id, count(distinct proyek_tahap_id) n')->groupBy('komponen_id')->pluck('n', 'komponen_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->baca($request);
        $k = KomponenAnggaran::create([...$d, 'urutan' => (int) KomponenAnggaran::max('urutan') + 10, 'user_id' => $request->user()->id]);
        $this->catat($request, 'komponen-tambah', "Komponen anggaran baru: {$k->nama} (".KomponenAnggaran::JENIS[$k->jenis].')', $k->toArray());

        return back()->with('success', "Komponen {$k->nama} ditambahkan.");
    }

    public function update(Request $request, KomponenAnggaran $komponen): RedirectResponse
    {
        $lama = $komponen->toArray();
        $komponen->update([...$this->baca($request, $komponen), 'user_id' => $request->user()->id]);
        $this->catat($request, 'komponen-ubah', "Komponen anggaran diubah: {$komponen->nama}", ['sebelum' => $lama, 'sesudah' => $komponen->fresh()->toArray()]);

        return back()->with('success', "Komponen {$komponen->nama} disimpan.");
    }

    public function hapus(Request $request, KomponenAnggaran $komponen): RedirectResponse
    {
        if ($n = AnggaranTahap::where('komponen_id', $komponen->id)->distinct()->count('proyek_tahap_id')) {
            return back()->with('error', "Komponen {$komponen->nama} dipakai di anggaran {$n} tahap — nonaktifkan saja supaya tidak muncul di anggaran baru.");
        }
        $this->catat($request, 'komponen-hapus', "Komponen anggaran dihapus: {$komponen->nama}", $komponen->toArray());
        $komponen->delete();

        return back()->with('success', "Komponen {$komponen->nama} dihapus.");
    }

    private function baca(Request $request, ?KomponenAnggaran $k = null): array
    {
        $request->merge(['nama' => trim(preg_replace('/\s+/', ' ', (string) $request->input('nama'))),
            'nilai_bawaan' => ($v = str_replace(' ', '', (string) $request->input('nilai_bawaan'))) === '' ? null
                : (str_contains($v, ',') ? str_replace(['.', ','], ['', '.'], $v) : (preg_match('/^\d+\.\d{1,3}$/', $v) ? $v : str_replace('.', '', $v)))]);
        $jenis = $k ? $k->jenis : $request->input('jenis');
        $d = $request->validate([
            'jenis' => [$k ? 'nullable' : 'required', Rule::in(array_keys(KomponenAnggaran::JENIS))],
            'nama' => ['required', 'string', 'max:100'],
            'satuan' => ['nullable', 'string', 'max:20'],
            'sumber' => ['required', Rule::in(array_keys(Anggaran::SUMBER[$jenis] ?? []))],
            'cara' => ['required', Rule::in($jenis === 'omset' ? ['volume'] : array_keys(Anggaran::CARA))],
            'nilai_bawaan' => ['nullable', 'numeric', 'min:0'],
            'tipe_bawaan' => ['nullable', 'array'], 'tipe_bawaan.*' => [Rule::in(array_keys(ProyekTahap::TIPE))],
        ], ['sumber.in' => 'Sumber realisasi tidak cocok dengan jenis komponen.', 'cara.in' => 'Komponen omset dihitung volume × harga.']);
        $kembar = KomponenAnggaran::where('jenis', $jenis)->whereRaw('LOWER(nama) = ?', [mb_strtolower($d['nama'])])->when($k, fn ($q) => $q->whereKeyNot($k->id))->exists();
        if ($kembar) {
            throw ValidationException::withMessages(['nama' => "Komponen \"{$d['nama']}\" sudah ada."]);
        }

        return ['jenis' => $jenis, 'nama' => $d['nama'], 'satuan' => trim((string) ($d['satuan'] ?? '')) ?: null, 'sumber' => $d['sumber'], 'cara' => $d['cara'],
            'nilai_bawaan' => $d['nilai_bawaan'] ?? null, 'tipe_bawaan' => array_values($d['tipe_bawaan'] ?? []), 'aktif' => $request->boolean('aktif'),
            'tunda_realisasi' => $jenis === 'hpp' && $request->boolean('tunda_realisasi')];
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Proyek', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
