<?php

namespace App\Http\Controllers;

use App\Models\KasReimburse;
use App\Models\KasRiwayat;
use App\Support\ReimburseKas;
use App\Support\ValidasiReimburse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Owner memilih transaksi Kas Harian yang belum reimburse (otomatis mendekati nominal), menandainya sudah reimburse, unduh Excel. */
class ReimburseKasController extends Controller
{
    public function index(): View
    {
        return view('kas.reimburse', [
            'antrean' => ReimburseKas::antrean(),
            'riwayat' => KasReimburse::with('user')->latest('id')->limit(30)->get(),
            'baru' => session('batch_baru') ? KasReimburse::find(session('batch_baru')) : null,
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'transfer' => ['required', 'array', 'min:1'],
            'transfer.*' => ['integer'],
            'tanggal' => ['required', 'date'],
            'target' => ['nullable', 'string'],
        ], ['transfer.required' => 'Pilih minimal satu transfer untuk direimburse.']);
        $target = (int) preg_replace('/\D/', '', (string) ($data['target'] ?? '')) ?: null;

        try {
            $batch = ReimburseKas::tandai(array_map('intval', $data['transfer']), Carbon::parse($data['tanggal']), $target, $request->user()->id);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Reimburse gagal: '.$e->getMessage());
        }
        KasRiwayat::create([
            'aksi' => 'kas-reimburse', 'lembar' => 'Kas', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => 'Reimburse Kas '.rp($batch->total).' tgl '.$batch->tanggal->translatedFormat('j M Y')." ({$batch->jumlah_transfer} transfer, {$batch->jumlah_baris} detail)",
            'isi' => ['reimburse_id' => $batch->id, 'id_transaksi' => collect($batch->isi)->pluck('id')->all()], 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('kas.reimburse')->with('batch_baru', $batch->id)->with('success',
            'Tercatat sudah reimburse tanggal '.$batch->tanggal->translatedFormat('j M Y').': '.rp($batch->total)." ({$batch->jumlah_transfer} transfer, {$batch->jumlah_baris} detail). "
            .'Baris-barisnya sedang ditulis ke lembar Sudah Reimburse di sheet.');
    }

    /** Validasi Excel daftar reimburse: upload + nama lembar → cek double reimburse, lalu rekap per Kode GL. */
    public function validasi(): View
    {
        return view('kas.validasi-reimburse', ['hasil' => null, 'namaFile' => null]);
    }

    public function periksaValidasi(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
            'lembar' => ['required', 'string', 'max:100'],
            'kolom_gl' => ['required', 'regex:/^[A-Za-z]{1,2}$/'],
            'kolom_nominal' => ['required', 'regex:/^[A-Za-z]{1,2}$/'],
        ], [
            'file.required' => 'Pilih file Excel (.xlsx) dulu.', 'file.mimes' => 'File harus Excel .xlsx.',
            'lembar.required' => 'Isi nama sheet yang berisi daftar transaksi.', '*.regex' => 'Kolom diisi huruf, mis. K atau N.',
        ]);
        try {
            $hasil = ValidasiReimburse::periksa($request->file('file')->getRealPath(), $data['lembar'], $data['kolom_gl'], $data['kolom_nominal']);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'File tidak bisa dibaca: '.$e->getMessage());
        }

        return view('kas.validasi-reimburse', ['hasil' => $hasil, 'namaFile' => $request->file('file')->getClientOriginalName()]);
    }

    /** Excel offline: semua transaksi Kas Harian yang belum reimburse. */
    public function unduhBelum(): BinaryFileResponse
    {
        return response()->download(ReimburseKas::excelBelum(), 'Kas Belum Reimburse per '.now()->format('Y-m-d H.i').'.xlsx')->deleteFileAfterSend();
    }

    public function unduh(KasReimburse $reimburse): BinaryFileResponse
    {
        $nama = 'Reimburse Kas '.$reimburse->tanggal->format('Y-m-d').' - Rp '.number_format($reimburse->total, 0, ',', '.').' (#'.$reimburse->id.').xlsx';

        return response()->download(ReimburseKas::excel($reimburse), $nama)->deleteFileAfterSend();
    }
}
