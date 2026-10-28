<?php

namespace App\Http\Controllers;

use App\Models\JagoUnggah;
use App\Models\KasRiwayat;
use App\Support\MutasiJago;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Mutasi Jago (owner 28 Okt 2026): unggah PDF riwayat transaksi kantong Jago lalu cocokkan dengan Kas UJ / Kas Harian. Lihat App\Support\MutasiJago. */
class MutasiJagoController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $kantongBoleh = collect(MutasiJago::KANTONG)->keys()->filter(fn ($k) => MutasiJago::bolehLihat($user, $k))->values();
        abort_if($kantongBoleh->isEmpty(), 403);
        $kantong = in_array($request->query('kantong'), $kantongBoleh->all(), true) ? $request->query('kantong') : $kantongBoleh->first();
        $mulai = Carbon::parse(MutasiJago::MULAI);
        try {
            $sampai = $request->filled('sampai') ? Carbon::parse($request->query('sampai')) : today();
            $dari = $request->filled('dari') ? Carbon::parse($request->query('dari')) : $sampai->copy()->subDays(6);
        } catch (\Throwable) {
            [$dari, $sampai] = [today()->subDays(6), today()];
        }
        $dari = $dari->max($mulai);
        $sampai = $sampai->max($dari);
        if ($dari->diffInDays($sampai) > 62) {
            $dari = $sampai->copy()->subDays(62);
        }

        return view('mutasi-jago.index', [
            'kantong' => $kantong, 'kantongBoleh' => $kantongBoleh, 'dari' => $dari, 'sampai' => $sampai,
            'hasil' => MutasiJago::cocokkan($kantong, $dari, $sampai),
            'unggahan' => JagoUnggah::with('user')->whereIn('kantong', $kantongBoleh)->latest('id')->limit(12)->get(),
        ]);
    }

    public function unggah(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:20480']], ['file.required' => 'Pilih file PDF riwayat transaksi Jago.', 'file.mimes' => 'File harus PDF dari aplikasi Jago.']);
        $f = $request->file('file');
        try {
            $h = MutasiJago::impor($f->getRealPath(), $f->getClientOriginalName(), $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'File tidak bisa dibaca: '.$e->getMessage());
        }
        $u = $h['unggah'];
        $nama = MutasiJago::KANTONG[$h['kantong']]['nama'];
        KasRiwayat::create(['aksi' => 'jago-unggah', 'lembar' => 'Jago', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("Unggah mutasi Jago {$nama} ".($u->dari?->format('d-m-Y') ?? '?').' s/d '.($u->sampai?->format('d-m-Y') ?? '?').": {$h['baru']} baris baru, {$h['sudah']} sudah ada"
                .($h['peringatan'] ? ' — '.implode(' ', $h['peringatan']) : ''), 0, 490, '…'),
            'isi' => ['unggah' => $u->id, 'kantong' => $h['kantong'], 'baru' => $h['baru'], 'sudah' => $h['sudah'], 'peringatan' => $h['peringatan']], 'user_id' => $request->user()->id]);

        // Semua pemegang menu boleh mengunggah; hasil kantong Kas MNP hanya tampil untuk akun ber-menu Kas Harian.
        $tujuan = MutasiJago::bolehLihat($request->user(), $h['kantong'])
            ? route('mutasi-jago.index', ['kantong' => $h['kantong'], 'dari' => ($u->dari ?? today())->max(Carbon::parse(MutasiJago::MULAI))->toDateString(), 'sampai' => ($u->sampai ?? today())->toDateString()])
            : route('mutasi-jago.index');

        // Validitas dulu (owner 28 Okt 2026): pengecekan ke Kas UJ / Kas Harian hanya berjalan bila saldo seluruh mutasi bersambung.
        $v = $h['validitas'];
        $isi = "Mutasi {$nama} ".($u->dari?->translatedFormat('j M') ?? '').' – '.($u->sampai?->translatedFormat('j M Y') ?? '').": {$h['baru']} transaksi baru tersimpan, {$h['sudah']} sudah ada sebelumnya. ";
        $isi .= $v['valid']
            ? '✓ Mutasi VALID — saldo bersambung dari '.$v['awal']?->format('d-m-Y H:i').' s/d '.$v['akhir']?->format('d-m-Y H:i').' ('.count($v['urut']).' transaksi); pengecekan dijalankan.'
            : '✗ Mutasi BELUM VALID — pengecekan hanya sampai '.$v['valid_sampai']?->format('d-m-Y H:i').'.';
        $lain = array_values(array_filter($h['peringatan'], fn ($p) => ! str_starts_with($p, 'Mutasi tidak valid')));

        return redirect($tujuan)->with($v['valid'] && ! $lain ? 'success' : 'error', $isi.($h['peringatan'] ? ' '.implode(' ', $h['peringatan']) : ''));
    }

    /** PDF asli yang diunggah. */
    public function pdf(Request $request, JagoUnggah $unggah): BinaryFileResponse
    {
        abort_unless(MutasiJago::bolehLihat($request->user(), $unggah->kantong), 403);
        $f = MutasiJago::pathPdf($unggah->sha256);
        abort_unless(is_file($f), 404);

        return response()->file($f, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.addslashes($unggah->nama_file ?: 'mutasi-jago.pdf').'"']);
    }
}
