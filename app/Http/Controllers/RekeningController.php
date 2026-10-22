<?php

namespace App\Http\Controllers;

use App\Models\Rekening;
use App\Support\DaftarBank;
use App\Support\DaftarRekening;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Daftar Rekening: database rekening bersama Kas Harian & Kas UJ (owner 22 Okt 2026) — satu nomor rekening = satu nama pemilik. */
class RekeningController extends Controller
{
    public function index(Request $request): View
    {
        $st = DaftarRekening::statistik();
        $kar = DaftarRekening::karyawan();
        $tgl = fn ($x) => $x ? Carbon::parse($x)->translatedFormat('j M Y') : '';
        $baris = Rekening::orderBy('nama')->get()->map(function ($r) use ($st, $kar, $tgl) {
            $kas = $st['kas'][$r->kunci] ?? null;
            $uj = $st['uj'][$r->kunci] ?? null;
            $lainKas = collect($kas['nama'] ?? [])->filter(fn ($n, $t) => DaftarRekening::baku($t) !== DaftarRekening::baku($r->nama))->sortDesc();
            $sopir = collect($uj['nama'] ?? [])->sortDesc();

            return (object) [
                'r' => $r, 'status' => ! $r->nama ? 'belum' : ($r->sumber === 'kas_uj' ? 'cek' : 'ok'),
                'kas_n' => (int) ($kas['n'] ?? 0), 'kas_akhir' => $tgl($kas['akhir'] ?? null), 'kas_lain' => $lainKas,
                'uj_n' => (int) ($uj['n'] ?? 0), 'uj_akhir' => $tgl($uj['akhir'] ?? null), 'sopir' => $sopir,
                'karyawan' => collect($kar[$r->kunci] ?? [])->map(fn ($k) => $k->nama.' · '.($k->jabatan ?: '-').($k->status !== 'aktif' ? ' · '.$k->status : '')),
                'akhir' => max((string) ($kas['akhir'] ?? ''), (string) ($uj['akhir'] ?? '')),
            ];
        });
        $tampil = in_array($request->query('tampil'), ['belum', 'cek', 'beda'], true) ? $request->query('tampil') : 'semua';
        $jumlah = ['semua' => $baris->count(), 'belum' => $baris->where('status', 'belum')->count(), 'cek' => $baris->where('status', 'cek')->count(),
            'beda' => $baris->filter(fn ($b) => $b->kas_lain->isNotEmpty())->count()];
        $baris = match ($tampil) {
            'belum', 'cek' => $baris->where('status', $tampil),
            'beda' => $baris->filter(fn ($b) => $b->kas_lain->isNotEmpty()),
            default => $baris,
        };

        return view('rekening.index', ['baris' => $baris->sortByDesc('akhir')->values(), 'tampil' => $tampil, 'jumlah' => $jumlah, 'bank' => DaftarBank::untukForm()]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $this->validasi($request, null);
        $k = Rekening::kunci($data['no_rekening']);
        if ($ada = Rekening::where('kunci', $k)->first()) {
            throw ValidationException::withMessages(['no_rekening' => "Rekening {$ada->no_rekening} sudah ada".($ada->nama ? " atas nama {$ada->nama}" : '').' — ubah di daftar.']);
        }
        $r = Rekening::create(['kunci' => $k, 'no_rekening' => preg_replace('/\D/', '', $data['no_rekening']), 'bank' => DaftarBank::rapikan($data['bank'] ?? null),
            'nama' => $data['nama'], 'sumber' => 'admin', 'catatan' => $data['catatan'] ?? null, 'user_id' => $request->user()->id]);
        DaftarRekening::log("Rekening baru {$r->bank} {$r->no_rekening} a.n. {$r->nama}", ['rekening' => $r->only(['id', 'no_rekening', 'bank', 'nama'])], $request->user()->id);

        return redirect()->route('rekening.index', $request->only('tampil'))->with('success', "Rekening {$r->no_rekening} a.n. {$r->nama} ditambahkan.");
    }

    public function ubah(Request $request, Rekening $rekening): RedirectResponse
    {
        $data = $this->validasi($request, $rekening);
        $sebelum = $rekening->only(['nama', 'bank', 'catatan', 'sumber']);
        $rekening->update(['nama' => $data['nama'], 'bank' => DaftarBank::rapikan($data['bank'] ?? null), 'catatan' => $data['catatan'] ?? null,
            'sumber' => 'admin', 'user_id' => $request->user()->id]);
        DaftarRekening::log("Rekening {$rekening->no_rekening}: ".($sebelum['nama'] ?? '(tanpa nama)')." → {$rekening->nama}",
            ['rekening' => $rekening->id, 'sebelum' => $sebelum, 'sesudah' => $rekening->only(['nama', 'bank', 'catatan', 'sumber'])], $request->user()->id);

        return redirect()->route('rekening.index', $request->only('tampil'))->with('success', "Rekening {$rekening->no_rekening} disimpan atas nama {$rekening->nama}.");
    }

    /** Bank lama yang tidak dikenali daftar bank (data lama dari sheet) boleh tetap dipakai saat mengubah nama. */
    private function validasi(Request $request, ?Rekening $lama): array
    {
        $data = $request->validate([
            'no_rekening' => [$lama ? 'exclude' : 'required', 'string', 'max:40', fn ($a, $v, $gagal) => strlen(Rekening::kunci($v)) >= 5 || $gagal('Nomor rekening minimal 5 angka.')],
            'nama' => ['required', 'string', 'max:150'],
            'bank' => ['nullable', 'string', 'max:60', ...($lama && $request->input('bank') === $lama->bank ? [] : [DaftarBank::aturan()])],
            'catatan' => ['nullable', 'string', 'max:300'],
        ]);
        $data['nama'] = trim(preg_replace('/\s+/', ' ', $data['nama']));

        return $data;
    }
}
