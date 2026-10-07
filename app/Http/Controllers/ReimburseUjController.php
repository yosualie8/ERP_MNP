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

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'transaksi' => ['required', 'array', 'min:1'],
            'transaksi.*' => ['integer'],
            'tanggal' => ['required', 'date'],
            'target' => ['nullable', 'string'],
        ], ['transaksi.required' => 'Pilih minimal satu transaksi untuk direimburse.']);
        $target = (int) preg_replace('/\D/', '', (string) ($data['target'] ?? '')) ?: null;

        try {
            $batch = ReimburseUj::tandai(array_map('intval', $data['transaksi']), Carbon::parse($data['tanggal']), $target, $request->user()->id);
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
