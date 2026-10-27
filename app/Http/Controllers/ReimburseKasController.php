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

        // File disimpan sementara bila ada opsi "tanpa transaksi yang sudah reimburse" (Excel revisinya dibuat saat disimpan).
        $token = $hasil['bisa_bersih'] ? ValidasiReimburse::simpanUnggahan($request->file('file')->getRealPath(), $request->user()->id) : null;

        return view('kas.validasi-reimburse', ['hasil' => $hasil, 'namaFile' => $request->file('file')->getClientOriginalName(), 'token' => $token,
            'kolom' => ['gl' => strtoupper($data['kolom_gl']), 'nominal' => strtoupper($data['kolom_nominal'])]]);
    }

    /**
     * Opsi bersih: file yang sama diperiksa ulang, transaksi yang masih belum reimburse (tanpa yang sudah & tanpa ID dobel) ditandai
     * Sudah Reimburse, lalu dibuat Excel revisi = file asli tanpa baris-baris yang tidak valid → diunduh pengguna.
     */
    public function simpanBersih(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'regex:/^[A-Za-z0-9]{40}$/'],
            'lembar' => ['required', 'string', 'max:100'],
            'kolom_gl' => ['required', 'regex:/^[A-Za-z]{1,2}$/'],
            'kolom_nominal' => ['required', 'regex:/^[A-Za-z]{1,2}$/'],
            'nama_file' => ['nullable', 'string', 'max:255'],
            'total' => ['required', 'integer'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
        ], ['tanggal.required' => 'Isi tanggal reimburse.', 'tanggal.before_or_equal' => 'Tanggal reimburse tidak boleh di masa depan.']);
        $userId = $request->user()->id;
        $asli = ValidasiReimburse::path($data['token'], $userId);
        if (! is_file($asli)) {
            return redirect()->route('kas.validasi-reimburse')->with('error', 'File unggahan sudah tidak ada (lebih dari 3 hari atau sudah diproses). Upload ulang file-nya.');
        }
        ini_set('memory_limit', '512M');
        $tanggal = \Illuminate\Support\Carbon::parse($data['tanggal']);
        $revisi = ValidasiReimburse::path($data['token'], $userId, true);
        try {
            // Periksa ulang: status bisa berubah sejak halaman dibuka (mis. admin lain baru menandai).
            $hasil = ValidasiReimburse::periksa($asli, $data['lembar'], $data['kolom_gl'], $data['kolom_nominal']);
            if (! $hasil['bisa_bersih']) {
                throw new \RuntimeException('Daftar ini sekarang tidak bisa diproses tanpa transaksi yang sudah reimburse. Validasi ulang file-nya.');
            }
            if ($hasil['bersih']['total'] !== (int) $data['total']) {
                throw new \RuntimeException('Total berubah sejak halaman dibuka ('.rp((int) $data['total']).' → '.rp($hasil['bersih']['total']).'). Periksa lagi sebelum menyimpan.');
            }
            $rev = ValidasiReimburse::revisi($asli, $hasil['lembar'], array_column($hasil['sudah'], 'id'), array_column($hasil['baris'], 'id'), $revisi);
            $dibuang = $rev['dihapus'];
            if ($dibuang !== $hasil['bersih']['dibuang']) {
                throw new \RuntimeException("Excel revisi tidak sesuai: {$dibuang} baris terhapus, seharusnya {$hasil['bersih']['dibuang']}. Tidak ada yang ditandai.");
            }
            $tandai = ValidasiReimburse::tandai(array_column($hasil['bersih']['baris'], 'id'), $tanggal, $userId);
        } catch (\RuntimeException $e) {
            @unlink($revisi);

            return redirect()->route('kas.validasi-reimburse')->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            @unlink($revisi);

            return redirect()->route('kas.validasi-reimburse')->with('error', 'Gagal memproses file: '.$e->getMessage());
        }
        @unlink($asli);

        $sumber = trim(($data['nama_file'] ?? '').' · sheet "'.$hasil['lembar'].'"', ' ·');
        \App\Models\KasRiwayat::create([
            'aksi' => 'reimburse-validasi', 'lembar' => 'Kas', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth('Validasi Reimburse (tanpa yang sudah reimburse): '.count($tandai['ditandai']).' transaksi ('.rp($tandai['total']).') ditandai Sudah Reimburse tgl '
                .$tanggal->translatedFormat('j M Y').'; '.$dibuang.' baris tidak valid dihapus dari Excel revisi — '.$sumber, 0, 490, '…'),
            'isi' => ['id_transaksi' => $tandai['ditandai'], 'tak_dikenal' => $tandai['tak_dikenal'], 'dibuang' => [...array_column($hasil['sudah'], 'id'),
                ...array_column(array_filter($hasil['ganda'], fn ($b) => $b['status'] !== 'sudah'), 'id')], 'tanggal' => $tanggal->toDateString(), 'sumber' => $sumber],
            'user_id' => $userId,
        ]);

        return redirect()->route('kas.validasi-reimburse')
            ->with('success', count($tandai['ditandai']).' transaksi ('.rp($tandai['total']).') dari '.$sumber.' ditandai Sudah Reimburse tanggal '
                .$tanggal->translatedFormat('j M Y').'. '.$dibuang.' baris tidak valid dihapus dari Excel revisi (diunduh otomatis).'
                .($tandai['tak_dikenal'] ? ' '.count($tandai['tak_dikenal']).' ID tidak ditandai karena tidak ada di Kas Harian aplikasi.' : ''))
            ->with('revisi', ['token' => $data['token'], 'nama' => $data['nama_file'] ?? 'Reimburse.xlsx', 'total' => $tandai['total'], 'peringatan' => $rev['peringatan']]);
    }

    /** Unduh Excel revisi (milik pengguna yang membuatnya). */
    public function unduhRevisi(Request $request, string $token): BinaryFileResponse
    {
        $f = ValidasiReimburse::path($token, $request->user()->id, true);
        abort_unless(is_file($f), 404, 'Excel revisi sudah tidak ada (disimpan 3 hari).');
        $nama = preg_replace('/\.xlsx$/i', '', basename((string) $request->query('nama', 'Reimburse'))).' (revisi).xlsx';

        return response()->download($f, $nama);
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
