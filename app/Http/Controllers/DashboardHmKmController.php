<?php

namespace App\Http\Controllers;

use App\Models\AsetMeter;
use App\Models\AsetTruk;
use App\Models\KasRiwayat;
use App\Support\MeterAset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Dashboard HM & KM (owner 16 Nov 2026): HM alat berat, KM dump truck, dan HM / KM kendaraan & aset lain di Data Aset —
 * angka terakhir, kapan dicatat, pemakaian per bulan & rata-rata per hari. HM alat berat otomatis dari Data BBM; angka lain dicatat
 * di halaman ini (hak 'hm-km-catat').
 */
class DashboardHmKmController extends Controller
{
    public function index(Request $request): View
    {
        $kelompok = array_key_exists($request->query('kelompok'), MeterAset::KELOMPOK) ? $request->query('kelompok') : 'alat';
        $semuaStatus = $request->boolean('semua');
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? $request->query('bulan') : now()->format('Y-m');
        $awal = Carbon::parse($bulan.'-01');
        $lalu = $awal->copy()->subMonth();

        $semua = AsetTruk::orderBy('no_lambung')->get();
        $aset = $semua->whereIn('kategori', MeterAset::KELOMPOK[$kelompok][1])
            ->when(! $semuaStatus, fn ($c) => $c->whereIn('status', ['aktif', 'perbaikan']))
            ->sortBy(fn ($a) => [array_search($a->kategori, MeterAset::KELOMPOK[$kelompok][1], true), $a->no_lambung])->values();
        $bacaan = MeterAset::bacaan($aset);
        $hariIni = now()->toDateString();

        $baris = $aset->map(function (AsetTruk $a) use ($bacaan, $awal, $lalu, $hariIni) {
            $satuan = AsetMeter::satuan($a->kategori);
            $langkah = MeterAset::langkah($bacaan[$a->id], $satuan);
            $akhir = $langkah->reject(fn ($b) => $b->janggal)->last();

            return (object) [
                'aset' => $a, 'satuan' => $satuan, 'akhir' => $akhir, 'n' => $langkah->count(),
                'umur' => $akhir ? Carbon::parse($akhir->tanggal)->diffInDays(Carbon::parse($hariIni)) : null,
                'ini' => MeterAset::pakai($langkah, $awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()),
                'lalu' => MeterAset::pakai($langkah, $lalu->toDateString(), $lalu->copy()->endOfMonth()->toDateString()),
                'janggal' => $langkah->filter(fn ($b) => $b->janggal)->count(),
            ];
        });

        $jumlahPer = collect(MeterAset::KELOMPOK)->map(fn ($k) => $semua->whereIn('kategori', $k[1])
            ->when(! $semuaStatus, fn ($c) => $c->whereIn('status', ['aktif', 'perbaikan']))->count());

        return view('dashboard.hm-km', [
            'kelompok' => $kelompok, 'semuaStatus' => $semuaStatus, 'bulan' => $bulan, 'awal' => $awal, 'lalu' => $lalu,
            'baris' => $baris, 'jumlahPer' => $jumlahPer,
            'daftarBulan' => collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->all(),
            'bolehCatat' => $request->user()->bolehMenu('hm-km-catat'),
        ]);
    }

    /** Riwayat bacaan satu aset (dialog). */
    public function riwayat(Request $request): JsonResponse
    {
        $a = AsetTruk::findOrFail((int) $request->query('aset'));
        $satuan = AsetMeter::satuan($a->kategori);
        $langkah = MeterAset::langkah(MeterAset::bacaan(collect([$a]))[$a->id], $satuan);
        $boleh = $request->user()->bolehMenu('hm-km-catat');

        return response()->json([
            'aset' => $a->no_lambung, 'kategori' => $a->labelKategori(), 'jenis' => $a->jenis, 'plat' => $a->plat, 'satuan' => $satuan,
            'bacaan' => $langkah->reverse()->values()->map(fn ($b) => [
                'tanggal' => $b->tanggal, 'tgl' => Carbon::parse($b->tanggal)->translatedFormat('D, j M Y'), 'nilai' => $b->nilai, 'selisih' => $b->selisih,
                'hari' => $b->hari, 'janggal' => $b->janggal, 'reset' => $b->reset, 'reset_otomatis' => $b->reset_otomatis, 'sumber' => $b->sumber, 'catatan' => $b->catatan, 'oleh' => $b->oleh,
                'id' => $b->id, 'bisa_hapus' => $boleh && $b->sumber === 'input',
            ]),
        ]);
    }

    /** Catat angka HM / KM (tanggal yang sama = menimpa catatan lama). Angka yang tidak berurutan ditolak; lompatan besar minta konfirmasi. */
    public function simpan(Request $request): JsonResponse
    {
        $request->merge(['nilai' => MeterAset::urai($request->input('nilai'))]);
        $data = $request->validate([
            'aset_id' => ['required', 'integer', 'exists:aset_truk,id'],
            'tanggal' => ['required', 'date', 'after_or_equal:2024-01-01', 'before_or_equal:today'],
            'nilai' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'reset' => ['nullable', 'boolean'],
            'catatan' => ['nullable', 'string', 'max:300'],
            'yakin' => ['nullable', 'boolean'],
        ], [
            'nilai.required' => 'Angka HM / KM belum diisi atau bukan angka.',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh sesudah hari ini.',
        ]);
        $a = AsetTruk::findOrFail($data['aset_id']);
        $satuan = AsetMeter::satuan($a->kategori);
        $tanggal = Carbon::parse($data['tanggal'])->toDateString();
        $reset = (bool) ($data['reset'] ?? false);
        $nilai = round((float) $data['nilai'], 1);
        $angka = fn ($v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
        $tgl = fn ($t) => Carbon::parse($t)->translatedFormat('j M Y');

        // Bandingkan dengan bacaan wajar di sekitarnya (tanggal yang sama tidak ikut — akan ditimpa).
        $lain = MeterAset::langkah(MeterAset::bacaan(collect([$a]))[$a->id], $satuan)->reject(fn ($b) => $b->janggal || $b->tanggal === $tanggal);
        $sebelum = $lain->filter(fn ($b) => $b->tanggal < $tanggal)->last();
        $sesudah = $lain->first(fn ($b) => $b->tanggal > $tanggal);
        if (! $reset && $sebelum && $nilai < $sebelum->nilai) {
            return response()->json(['message' => "{$satuan} {$angka($nilai)} lebih kecil dari bacaan sebelumnya {$angka($sebelum->nilai)} ({$tgl($sebelum->tanggal)}). "
                .'Periksa lagi angkanya — bila meter memang diganti / direset, centang "Meter diganti / direset".'], 422);
        }
        if ($sesudah && ! $sesudah->reset && $nilai > $sesudah->nilai) {
            return response()->json(['message' => "{$satuan} {$angka($nilai)} lebih besar dari bacaan sesudahnya {$angka($sesudah->nilai)} ({$tgl($sesudah->tanggal)})."], 422);
        }
        if (! $reset && $sebelum && empty($data['yakin'])) {
            $hari = max(1, Carbon::parse($sebelum->tanggal)->diffInDays(Carbon::parse($tanggal)));
            $naik = $nilai - $sebelum->nilai;
            if ($naik > MeterAset::MAKS_PER_HARI[$satuan] * $hari) {
                return response()->json(['konfirmasi' => "Naik {$angka($naik)} {$satuan} dalam {$hari} hari sejak {$tgl($sebelum->tanggal)} ({$angka($sebelum->nilai)}) — "
                    .'lebih dari '.MeterAset::MAKS_PER_HARI[$satuan]." {$satuan} per hari. Angkanya sudah benar?"], 409);
            }
        }

        $m = AsetMeter::updateOrCreate(['aset_id' => $a->id, 'tanggal' => $tanggal],
            ['nilai' => $nilai, 'reset' => $reset, 'catatan' => ($c = trim((string) ($data['catatan'] ?? ''))) === '' ? null : $c, 'user_id' => $request->user()->id]);
        $this->catat($request, 'meter-catat', "{$satuan} {$a->no_lambung} {$tgl($tanggal)}: {$angka($nilai)}".($reset ? ' (meter direset)' : ''), $m->toArray());

        return response()->json(['ok' => true, 'pesan' => "{$satuan} {$a->no_lambung} {$tgl($tanggal)} = {$angka($nilai)} tersimpan."]);
    }

    public function hapus(Request $request, AsetMeter $meter): JsonResponse
    {
        $meter->load('aset');
        $satuan = AsetMeter::satuan($meter->aset?->kategori);
        $this->catat($request, 'meter-hapus', "Hapus {$satuan} {$meter->aset?->no_lambung} {$meter->tanggal->translatedFormat('j M Y')}: {$meter->nilai}", $meter->toArray());
        $meter->delete();

        return response()->json(['ok' => true]);
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Aset', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
