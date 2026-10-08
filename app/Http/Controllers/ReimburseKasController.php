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

    /** Excel offline: semua transaksi Kas Harian yang belum reimburse. */
    public function unduhBelum(): BinaryFileResponse
    {
        return response()->download(ReimburseKas::excelBelum(), 'Kas Belum Reimburse per '.now()->format('Y-m-d H.i').'.xlsx')->deleteFileAfterSend();
    }
}
