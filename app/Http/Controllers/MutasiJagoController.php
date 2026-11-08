<?php

namespace App\Http\Controllers;

use App\Models\JagoMutasi;
use App\Models\JagoUnggah;
use App\Models\KasRiwayat;
use App\Support\MutasiJago;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
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
            'bolehCatatan' => \App\Support\MenuAkses::boleh($user, 'jago-catatan'),
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
        // Bunga & pajak bunga kantong Kas MNP langsung dicatat di Kas Harian (owner 28 Okt 2026) — hanya yang valid & belum ada.
        if ($h['kantong'] === 'kas') {
            try {
                if ($bunga = MutasiJago::catatBungaOtomatis($request->user()->id)) {
                    $isi .= ' Otomatis dicatat di Kas Harian: '.implode(', ', $bunga).'.';
                }
            } catch (\Throwable $e) {
                report($e);
                $lain[] = $e->getMessage();
                $isi .= ' Bunga / pajak bunga GAGAL dicatat otomatis ('.$e->getMessage().') — input manual di Input Kas.';
            }
        }

        return redirect($tujuan)->with($v['valid'] && ! $lain ? 'success' : 'error', $isi.($h['peringatan'] ? ' '.implode(' ', $h['peringatan']) : ''));
    }

    /**
     * Ubah catatan satu transaksi mutasi yang sudah diunggah (owner 9 Nov 2026 — untuk Eddy; hak akses "Edit catatan Mutasi Jago").
     * Catatan asli dari PDF bank disimpan di catatan_bank saat pertama diubah; unggah ulang PDF tidak menimpa catatan yang sudah diubah.
     */
    public function catatan(Request $request, JagoMutasi $mutasi): JsonResponse
    {
        abort_unless(MutasiJago::bolehLihat($request->user(), $mutasi->kantong), 403);
        $baru = trim(preg_replace('/\s+/', ' ', (string) ($request->validate(['catatan' => ['nullable', 'string', 'max:500']])['catatan'] ?? '')));
        $baru = $baru === '' ? null : $baru;
        $lama = $mutasi->catatan;
        if ($baru === $lama) {
            return response()->json(['ok' => true, 'catatan' => $lama, 'berubah' => false]);
        }
        $mutasi->update([
            'catatan_bank' => $mutasi->catatan_diubah_pada ? $mutasi->catatan_bank : $lama,
            'catatan' => $baru, 'catatan_diubah_oleh' => $request->user()->id, 'catatan_diubah_pada' => now(),
        ]);
        KasRiwayat::create(['aksi' => 'jago-catatan', 'lembar' => 'Jago', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
            'ringkasan' => mb_strimwidth('Catatan mutasi Jago '.$mutasi->id_bank.' ('.$mutasi->waktu->format('d-m-Y H:i').' Rp '.number_format(abs($mutasi->jumlah), 0, ',', '.')
                .') diubah: "'.($lama ?? '–').'" → "'.($baru ?? '–').'"', 0, 490, '…'),
            'isi' => ['id' => $mutasi->id, 'id_bank' => $mutasi->id_bank, 'lama' => $lama, 'baru' => $baru, 'catatan_bank' => $mutasi->catatan_bank]]);

        return response()->json(['ok' => true, 'catatan' => $baru, 'berubah' => true]);
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
