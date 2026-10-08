<?php

namespace App\Http\Controllers;

use App\Support\ReimburseKas;
use App\Support\ValidasiReimburse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Validasi Excel daftar reimburse Kas Harian dan unduh Excel transaksi yang belum reimburse. */
class ReimburseKasController extends Controller
{
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

    /** Hasil validasi yang sudah valid → tandai semua transaksinya Sudah Reimburse (aplikasi + lembar Sudah Reimburse di sheet). */
    public function tandaiValidasi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'json'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'nama_file' => ['nullable', 'string', 'max:255'],
            'lembar' => ['nullable', 'string', 'max:100'],
        ], ['tanggal.required' => 'Isi tanggal reimburse.', 'tanggal.before_or_equal' => 'Tanggal reimburse tidak boleh di masa depan.']);
        $tanggal = \Illuminate\Support\Carbon::parse($data['tanggal']);
        try {
            $hasil = ValidasiReimburse::tandai((array) json_decode($data['ids'], true), $tanggal, $request->user()->id);
        } catch (\RuntimeException $e) {
            return redirect()->route('kas.validasi-reimburse')->with('error', $e->getMessage());
        }
        $sumber = trim(($data['nama_file'] ?? '').' · sheet "'.($data['lembar'] ?? '').'"', ' ·');
        \App\Models\KasRiwayat::create([
            'aksi' => 'reimburse-validasi', 'lembar' => 'Kas', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth('Validasi Reimburse: '.count($hasil['ditandai']).' transaksi ('.rp($hasil['total']).') ditandai Sudah Reimburse tgl '
                .$tanggal->translatedFormat('j M Y').' — '.$sumber, 0, 490, '…'),
            'isi' => ['id_transaksi' => $hasil['ditandai'], 'tak_dikenal' => $hasil['tak_dikenal'], 'tanggal' => $tanggal->toDateString(), 'sumber' => $sumber],
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('kas.validasi-reimburse')->with('success',
            count($hasil['ditandai']).' transaksi ('.rp($hasil['total']).') dari '.$sumber.' ditandai Sudah Reimburse tanggal '.$tanggal->translatedFormat('j M Y')
            .'. Statusnya langsung berlaku di aplikasi dan sedang ditulis ke lembar "Sudah Reimburse" di sheet.'
            .($hasil['tak_dikenal'] ? ' '.count($hasil['tak_dikenal']).' ID tidak ditandai karena tidak ada di Kas Harian aplikasi: '
                .implode(', ', array_slice($hasil['tak_dikenal'], 0, 10)).(count($hasil['tak_dikenal']) > 10 ? ', …' : '').'.' : ''));
    }

    /** Excel offline: semua transaksi Kas Harian yang belum reimburse. */
    public function unduhBelum(): BinaryFileResponse
    {
        return response()->download(ReimburseKas::excelBelum(), 'Kas Belum Reimburse per '.now()->format('Y-m-d H.i').'.xlsx')->deleteFileAfterSend();
    }
}
