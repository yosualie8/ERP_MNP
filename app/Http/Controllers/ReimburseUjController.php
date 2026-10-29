<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\UjReimburse;
use App\Support\ReimburseUj;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Pantau uang jalan yang belum reimburse, pilih otomatis sampai mendekati nominal, tandai sudah reimburse, unduh Excel. */
class ReimburseUjController extends Controller
{
    public function index(): View
    {
        return view('uj.reimburse', [
            'antrean' => ReimburseUj::antrean(),
            'riwayat' => UjReimburse::with('user')->latest('id')->limit(30)->get(),
            'baru' => session('batch_baru') ? UjReimburse::find(session('batch_baru')) : null,
        ]);
    }

    /**
     * Jumlah transaksi Kas UJ di luar Uang Jalan, Uang Tanah & Uang Material pada satu tanggal (owner 25 Okt 2026; bawaan H-1), per
     * kategori — bahan menentukan nominal reimburse. Semua status reimburse ikut dihitung; biaya transfer tampil sebagai kategorinya sendiri.
     */
    public function lainnya(Request $request): \Illuminate\Http\JsonResponse
    {
        $tanggal = RitasiController::tanggal($request->query('tanggal')) ?? today()->subDay();
        $baris = \App\Models\UjDetail::whereDate('tanggal', $tanggal)->orderBy('baris')->get()
            ->reject(fn ($d) => ! $d->biaya_transfer && (in_array(\App\Support\ValidasiUj::jenisKategori($d->kategori), ['uj', 'ut'], true)
                || str_contains(strtolower((string) $d->kategori), 'material')));
        $kategori = $baris->groupBy(fn ($d) => $d->biaya_transfer ? 'Biaya Transfer' : (trim((string) $d->kategori) ?: 'Tanpa kategori'))
            ->map(fn ($g, $k) => ['kategori' => $k, 'n' => $g->count(), 'total' => (int) $g->sum('nominal'),
                'belum' => (int) $g->filter(fn ($d) => ! $d->tanggal_reimburse)->sum('nominal')])->sortByDesc('total')->values();

        return response()->json(['tanggal' => $tanggal->toDateString(), 'label' => $tanggal->translatedFormat('l, j F Y'), 'kategori' => $kategori,
            'total' => (int) $baris->sum('nominal'), 'n' => $baris->count(),
            'baris' => $baris->take(300)->map(fn ($d) => ['id_uj' => $d->id_uj, 'nama' => $d->nama, 'kategori' => $d->biaya_transfer ? 'Biaya Transfer' : $d->kategori,
                'ket' => $d->keterangan, 'mobil' => $d->no_mobil, 'nominal' => (int) $d->nominal, 'sudah' => (bool) $d->tanggal_reimburse])->values()]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'detail' => ['required', 'array', 'min:1'],
            'detail.*' => ['integer'],
            'tanggal' => ['required', 'date'],
            'target' => ['nullable', 'string'],
        ], ['detail.required' => 'Pilih minimal satu transaksi untuk direimburse.']);
        $target = (int) preg_replace('/\D/', '', (string) ($data['target'] ?? '')) ?: null;
        $ids = array_values(array_unique(array_map('intval', $data['detail'])));
        // Owner 25 Okt 2026: reimburse wajib berurutan dari transaksi paling tua; 29 Okt 2026: transfer bayar tanah terakhir boleh dicicil per baris.
        if ($salah = ReimburseUj::periksaPilihan($ids)) {
            return back()->with('error', $salah);
        }

        try {
            $batch = ReimburseUj::tandai($ids, Carbon::parse($data['tanggal']), $target, $request->user()->id);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Reimburse gagal: '.$e->getMessage());
        }
        KasRiwayat::create([
            'aksi' => 'uj-reimburse', 'lembar' => 'Seabank', 'baris_awal' => collect($batch->isi)->min('baris'), 'baris_akhir' => collect($batch->isi)->max('baris'),
            'ringkasan' => 'Reimburse UJ '.rp($batch->total).' tgl '.$batch->tanggal->translatedFormat('j M Y')." ({$batch->jumlah_transfer} transfer, {$batch->jumlah_baris} baris)",
            'isi' => ['reimburse_id' => $batch->id, 'id_uj' => collect($batch->isi)->pluck('id_uj')->filter()->values()->all()], 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('reimburse.index')->with('batch_baru', $batch->id)->with('success',
            'Tercatat sudah reimburse tanggal '.$batch->tanggal->translatedFormat('j M Y').': '.rp($batch->total)." ({$batch->jumlah_transfer} transfer, {$batch->jumlah_baris} baris). "
            .'Kolom Tanggal Reimburse di sheet Kas Seabank sudah diisi.');
    }

    public function unduh(UjReimburse $reimburse): BinaryFileResponse
    {
        $nama = 'Reimburse UJ '.$reimburse->tanggal->format('Y-m-d').' - Rp '.number_format($reimburse->total, 0, ',', '.').' (#'.$reimburse->id.').xlsx';

        return response()->download(ReimburseUj::excel($reimburse), $nama)->deleteFileAfterSend();
    }
}
