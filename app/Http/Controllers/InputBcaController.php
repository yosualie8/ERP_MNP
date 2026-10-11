<?php

namespace App\Http\Controllers;

use App\Models\InputBca;
use App\Models\KasRiwayat;
use App\Models\PengaturanApp;
use App\Support\BankBca;
use App\Support\DaftarBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Input Transfer BCA: admin mengisi daftar transfer di sini; skrip Playwright di laptop (folder otomasi-bank) mengambil
 * daftar "menunggu" lewat API, menginput satu per satu ke KlikBCA Bisnis sebagai Maker (masuk Otorisasi Transaksi),
 * lalu melaporkan No. Referensi / alasan gagal. Rekening tujuan harus sudah ada di Daftar Transfer KlikBCA Bisnis.
 */
class InputBcaController extends Controller
{
    public const KUNCI_TOKEN = 'bca_skrip_token_hash';

    public function index(Request $request): View
    {
        $status = array_key_exists($request->query('status'), InputBca::STATUS) ? $request->query('status') : null;

        return view('kas.input-bca', [
            'daftar' => InputBca::with('user')->when($status, fn ($q) => $q->where('status', $status))
                ->orderByRaw("FIELD(status, 'proses', 'menunggu') DESC")->latest('id')->limit(300)->get(),
            'jumlah' => InputBca::selectRaw('status, COUNT(*) n, SUM(nominal) s')->groupBy('status')->get()->keyBy('status'),
            'status' => $status,
            'rekening' => PengaturanApp::ambil('bca_rekening_debet'),
            'adaToken' => (bool) PengaturanApp::ambil(self::KUNCI_TOKEN),
            'bank' => collect(DaftarBank::untukForm())->filter(fn ($b) => BankBca::untuk($b['kode']))->values(),
            'penerima' => TransferBcaController::penerimaDikenal(),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $baris = collect((array) $request->input('penerima', []))
            ->map(fn ($p) => [
                'nama' => trim(preg_replace('/\s+/', ' ', (string) ($p['nama'] ?? ''))),
                'bank' => DaftarBank::kode($p['bank'] ?? null),
                'bank_teks' => trim((string) ($p['bank'] ?? '')),
                'rekening' => preg_replace('/\D/', '', (string) ($p['rekening'] ?? '')),
                'nominal' => (int) preg_replace('/\D/', '', (string) ($p['nominal'] ?? '')),
                'berita' => trim(preg_replace('/\s+/', ' ', (string) ($p['berita'] ?? ''))),
                'layanan' => array_key_exists($p['layanan'] ?? '', InputBca::LAYANAN) ? $p['layanan'] : null,
            ])
            ->reject(fn ($p) => $p['nama'] === '' && $p['bank_teks'] === '' && $p['rekening'] === '' && ! $p['nominal'])->values();
        if ($baris->isEmpty()) {
            return back()->withInput()->with('error', 'Isi minimal satu transfer.');
        }
        $galat = [];
        foreach ($baris as $i => $p) {
            $salah = [];
            if ($p['nama'] === '') {
                $salah[] = 'nama penerima belum diisi';
            }
            if (! $p['bank']) {
                $salah[] = $p['bank_teks'] === '' ? 'bank belum diisi' : "bank \"{$p['bank_teks']}\" tidak dikenal";
            } elseif (! BankBca::untuk($p['bank'])) {
                $salah[] = "{$p['bank']} tidak bisa ditransfer dari KlikBCA Bisnis (e-wallet/bank tidak terdaftar)";
            }
            if (strlen($p['rekening']) < 5 || strlen($p['rekening']) > 34) {
                $salah[] = 'nomor rekening belum benar';
            } elseif ($p['bank'] === 'BCA' && strlen($p['rekening']) !== 10) {
                $salah[] = 'rekening BCA harus 10 digit';
            }
            if ($p['nominal'] < 1) {
                $salah[] = 'nominal belum diisi';
            }
            $layanan = $p['bank'] === 'BCA' ? null : ($p['layanan'] ?? InputBca::layananBawaan((string) $p['bank'], $p['nominal']));
            if (in_array($layanan, ['bifast', 'online'], true) && $p['nominal'] > InputBca::MAKS_BIFAST) {
                $salah[] = InputBca::LAYANAN[$layanan].' maks. '.rp(InputBca::MAKS_BIFAST);
            }
            if (mb_strlen($p['berita']) > 60) {
                $salah[] = 'berita maks. 60 karakter (2 baris × 30)';
            }
            if ($salah) {
                $galat["penerima.{$i}"] = 'Baris '.($i + 1).($p['nama'] ? " ({$p['nama']})" : '').': '.implode(', ', $salah).'.';
            }
        }
        if ($galat) {
            return back()->withInput()->withErrors($galat);
        }
        $dibuat = $baris->map(fn ($p) => InputBca::create([...collect($p)->except('bank_teks', 'layanan')->all(), 'berita' => $p['berita'] ?: null,
            'layanan' => $p['bank'] === 'BCA' ? null : ($p['layanan'] ?? InputBca::layananBawaan($p['bank'], $p['nominal'])), 'user_id' => $request->user()->id]));
        KasRiwayat::create(['aksi' => 'bca-input', 'lembar' => 'BCA', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth('Input Transfer BCA: '.$dibuat->count().' transfer ('.rp($dibuat->sum('nominal')).') menunggu diinput skrip — '
                .$dibuat->map(fn ($x) => $x->nama.' '.rp($x->nominal))->implode(', '), 0, 490, '…'),
            'isi' => ['input_bca_id' => $dibuat->pluck('id')->all()], 'user_id' => $request->user()->id]);

        return redirect()->route('kas.input-bca')->with('success', $dibuat->count().' transfer ('.rp($dibuat->sum('nominal'))
            .') masuk antrean. Jalankan skrip input-bca di laptop untuk menginputnya ke KlikBCA Bisnis.');
    }

    /** Admin: batalkan (menunggu), ulangi (gagal/dilewati/proses macet), atau tandai terinput manual (proses macet yang ternyata masuk). */
    public function ubahStatus(Request $request, InputBca $item): RedirectResponse
    {
        $aksi = $request->validate(['aksi' => 'required|in:batal,ulangi,terinput', 'no_referensi' => 'nullable|string|max:40'])['aksi'];
        $boleh = match ($aksi) {
            'batal' => $item->status === 'menunggu',
            'ulangi' => in_array($item->status, ['gagal', 'dilewati', 'batal'], true) || $item->perluCek(),
            'terinput' => $item->perluCek(),
        };
        if (! $boleh) {
            return back()->with('error', 'Status transfer ini tidak bisa diubah begitu.');
        }
        $lama = $item->status;
        $item->update(match ($aksi) {
            'batal' => ['status' => 'batal', 'pesan' => 'Dibatalkan '.$request->user()->name],
            'ulangi' => ['status' => 'menunggu', 'pesan' => null, 'mulai_pada' => null, 'selesai_pada' => null],
            'terinput' => ['status' => 'terinput', 'no_referensi' => $request->input('no_referensi'), 'selesai_pada' => now(),
                'pesan' => 'Ditandai terinput manual oleh '.$request->user()->name],
        });
        KasRiwayat::create(['aksi' => 'bca-input', 'lembar' => 'BCA', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Input Transfer BCA #{$item->id} {$item->nama} ".rp($item->nominal).": {$lama} → {$item->status}", 'isi' => ['input_bca_id' => $item->id],
            'user_id' => $request->user()->id]);

        return back()->with('success', "Transfer #{$item->id} ({$item->nama}) sekarang: ".InputBca::STATUS[$item->status].'.');
    }

    /** Super Admin: buat/ganti token untuk skrip di laptop (ditampilkan sekali). */
    public function token(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $token = Str::random(48);
        PengaturanApp::simpan(self::KUNCI_TOKEN, hash('sha256', $token), $request->user()->id);

        return back()->with('token_baru', $token)->with('success', 'Token skrip baru dibuat. Token lama tidak berlaku lagi.');
    }

    // ===== API untuk skrip (Authorization: Bearer <token>) =====

    public function apiAntrean(Request $request): JsonResponse
    {
        $this->cekToken($request);

        return response()->json([
            'rekening_sumber' => PengaturanApp::ambil('bca_rekening_debet'),
            'antrean' => InputBca::where('status', 'menunggu')->orderBy('id')->get(['id', 'nama', 'bank', 'rekening', 'nominal', 'berita', 'layanan']),
        ]);
    }

    /** Klaim satu transfer (menunggu → proses) supaya tidak diinput dua kali. */
    public function apiMulai(Request $request, InputBca $item): JsonResponse
    {
        $this->cekToken($request);
        $n = InputBca::whereKey($item->id)->where('status', 'menunggu')->update(['status' => 'proses', 'mulai_pada' => now(), 'pesan' => null]);

        return $n ? response()->json(['ok' => true]) : response()->json(['ok' => false, 'pesan' => 'Status sudah bukan menunggu.'], 409);
    }

    public function apiHasil(Request $request, InputBca $item): JsonResponse
    {
        $this->cekToken($request);
        $d = $request->validate(['status' => 'required|in:terinput,dilewati,gagal,menunggu', 'no_referensi' => 'nullable|string|max:40', 'pesan' => 'nullable|string|max:500']);
        if ($item->status !== 'proses') {
            return response()->json(['ok' => false, 'pesan' => 'Transfer ini tidak sedang diproses.'], 409);
        }
        $item->update(['status' => $d['status'], 'no_referensi' => $d['no_referensi'] ?? null, 'pesan' => $d['pesan'] ?? null,
            'selesai_pada' => $d['status'] === 'menunggu' ? null : now(), 'mulai_pada' => $d['status'] === 'menunggu' ? null : $item->mulai_pada]);
        if ($d['status'] !== 'menunggu') {
            KasRiwayat::create(['aksi' => 'bca-skrip', 'lembar' => 'BCA', 'baris_awal' => 0, 'baris_akhir' => 0,
                'ringkasan' => mb_strimwidth("Skrip KlikBCA: #{$item->id} {$item->nama} ".rp($item->nominal).' → '.InputBca::STATUS[$d['status']]
                    .(! empty($d['no_referensi']) ? " (No. Ref {$d['no_referensi']})" : '').(! empty($d['pesan']) ? " — {$d['pesan']}" : ''), 0, 490, '…'),
                'isi' => ['input_bca_id' => $item->id]]);
        }

        return response()->json(['ok' => true]);
    }

    private function cekToken(Request $request): void
    {
        $hash = PengaturanApp::ambil(self::KUNCI_TOKEN);
        abort_unless($hash && $request->bearerToken() && hash_equals($hash, hash('sha256', $request->bearerToken())), 401, 'Token skrip tidak sah.');
    }
}
