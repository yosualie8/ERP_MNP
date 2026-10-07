<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\Ritasi;
use App\Models\RitasiTemuan;
use App\Models\UjDetail;
use App\Support\GoogleSheets;
use App\Support\LembarRitasi;
use App\Support\NomorMobil;
use App\Support\TulisRitasiSheet;
use App\Support\ValidasiRitasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Ritasi dump truck (lembar "Ritasi"): daftar per bulan, input banyak rit sekaligus, edit & hapus per rit. */
class RitasiController extends Controller
{
    private const MIN_KONFIRMASI = 10;

    public function index(Request $request): View
    {
        $daftarBulan = Ritasi::whereNotNull('tanggal')->selectRaw("DATE_FORMAT(tanggal, '%Y-%m') as bulan")->distinct()->orderBy('bulan')->pluck('bulan');
        $bulan = $daftarBulan->contains($request->query('bulan')) ? $request->query('bulan') : $daftarBulan->last();
        $q = trim((string) $request->query('q'));
        $rit = collect();
        if ($bulan) {
            $awal = Carbon::parse($bulan.'-01');
            $rit = Ritasi::whereBetween('tanggal', [$awal, $awal->copy()->endOfMonth()])
                ->when($q !== '', function ($query) use ($q) {
                    $like = '%'.$q.'%';
                    $query->where(fn ($w) => collect(['tahap', 'no_seri', 'no_polisi', 'no_lambung', 'galian', 'jenis_tanah', 'pemilik', 'no_do', 'keterangan'])
                        ->each(fn ($k) => $w->orWhere($k, 'like', $like)));
                })
                ->orderByDesc('baris')->get();
        }

        return view('ritasi.index', [
            'daftarBulan' => $daftarBulan, 'bulan' => $bulan, 'q' => $q, 'rit' => $rit,
            'diimpor' => Cache::get('ritasi-diimpor-pada'), 'bolehInput' => $request->user()->bolehMenu('input-ritasi'),
        ]);
    }

    public function create(): View
    {
        $setahun = now()->subYear()->toDateString();
        $sering = fn (string $kolom) => Ritasi::where('tanggal', '>=', $setahun)->whereNotNull($kolom)->where($kolom, '!=', '')
            ->select($kolom, DB::raw('COUNT(*) n'))->groupBy($kolom)->orderByDesc('n')->limit(60)->pluck($kolom);

        // DT → plat, driver, jenis, pemilik dari rit terakhir (cadangan: driver & jenis dari Kas UJ).
        $dt = [];
        foreach (UjDetail::whereNotNull('no_mobil')->orderBy('baris')->get(['no_mobil', 'nama', 'jenis_kendaraan']) as $d) {
            $dt[$d->no_mobil] = ['plat' => null, 'driver' => $d->nama, 'jenis' => $d->jenis_kendaraan, 'pemilik' => 'MNP'];
        }
        foreach (Ritasi::whereNotNull('no_lambung')->where('tanggal', '>=', $setahun)->orderBy('baris')->get(['no_lambung', 'no_polisi', 'plat', 'jenis_kendaraan', 'pemilik']) as $r) {
            $dt[$r->no_lambung] = ['plat' => $r->plat, 'driver' => $r->driver ?? ($dt[$r->no_lambung]['driver'] ?? null), 'jenis' => $r->jenis_kendaraan, 'pemilik' => $r->pemilik];
        }
        ksort($dt);

        // Harga jual terakhir per tahap|galian|jenis|pemilik (cadangan tahap|galian).
        $harga = [];
        foreach (Ritasi::whereNotNull('harga_jual')->where('tanggal', '>=', $setahun)->orderBy('baris')->get(['tahap', 'galian', 'jenis_kendaraan', 'pemilik', 'harga_jual']) as $r) {
            $harga[mb_strtolower("{$r->tahap}|{$r->galian}|{$r->jenis_kendaraan}|{$r->pemilik}")] = (int) $r->harga_jual;
            $harga[mb_strtolower("{$r->tahap}|{$r->galian}")] = (int) $r->harga_jual;
        }

        // No Seri berikutnya per tahap (angka terbesar + 1, panjang nol di depan mengikuti yang terakhir).
        $seri = Ritasi::whereNotNull('no_seri')->where('tanggal', '>=', $setahun)->orderBy('baris')->get(['tahap', 'no_seri'])
            ->groupBy('tahap')->map(fn ($g) => ['max' => $g->map(fn ($r) => (int) preg_replace('/\D/', '', $r->no_seri))->max(), 'pad' => strlen((string) $g->last()->no_seri)]);

        $tahap = Ritasi::where('tanggal', '>=', now()->subDays(120))->whereNotNull('tahap')->select('tahap', DB::raw('MAX(baris) b'))->groupBy('tahap')->orderByDesc('b')->pluck('tahap');

        return view('ritasi.input', [
            'tahap' => $tahap, 'galian' => $sering('galian'), 'jenisTanah' => $sering('jenis_tanah'), 'jenisBuangan' => $sering('jenis_buangan'),
            'jenisKendaraan' => $sering('jenis_kendaraan'), 'pemilik' => $sering('pemilik'), 'dt' => $dt, 'harga' => $harga, 'seri' => $seri,
        ]);
    }

    /** @return array{0: array, 1: array<int, array>} kepala & rit yang sudah dirapikan (indeks dipertahankan) */
    private function rapikan(Request $request, bool $longgar): array
    {
        $rapi = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $kepala = [
            'tahap' => $rapi($request->input('tahap')), 'tanggal' => rescue(fn () => Carbon::parse((string) $request->input('tanggal')), null, false),
            'galian' => $rapi($request->input('galian')), 'jenis_buangan' => $rapi($request->input('jenis_buangan')), 'jenis_tanah' => $rapi($request->input('jenis_tanah')),
        ];
        $rit = [];
        foreach ((array) $request->input('rit', []) as $i => $r) {
            $baris = [
                'no_seri' => $rapi($r['no_seri'] ?? null), 'jam' => LembarRitasi::jam($rapi($r['jam'] ?? null)), 'no_lambung' => NomorMobil::rapikan($r['no_lambung'] ?? null),
                'plat' => $rapi(strtoupper((string) ($r['plat'] ?? ''))), 'driver' => $rapi($r['driver'] ?? null), 'jenis_kendaraan' => NomorMobil::rapikanJenis($r['jenis_kendaraan'] ?? null),
                'pemilik' => $rapi($r['pemilik'] ?? null), 'no_do' => $rapi($r['no_do'] ?? null),
                'harga_jual' => (int) preg_replace('/\D/', '', (string) ($r['harga_jual'] ?? '')) ?: null, 'keterangan' => $rapi($r['keterangan'] ?? null),
                'konfirmasi' => $rapi($r['konfirmasi'] ?? null),
            ];
            // Baris yang belum lengkap tidak ikut diperiksa saat pemeriksaan longgar (form menandai hijau baris per baris).
            if ($longgar && (! $baris['no_seri'] || ! ($baris['no_lambung'] || $baris['plat']))) {
                continue;
            }
            $rit[(int) $i] = $baris;
        }

        return [$kepala, $rit];
    }

    private function aturan(): array
    {
        return [
            'tahap' => ['required', 'string', 'max:120'], 'tanggal' => ['required', 'date'], 'galian' => ['required', 'string', 'max:80'],
            'jenis_buangan' => ['required', 'string', 'max:40'], 'jenis_tanah' => ['required', 'string', 'max:60'],
            'rit' => ['required', 'array', 'min:1'],
            'rit.*.no_seri' => ['required', 'string', 'max:30'], 'rit.*.jam' => ['nullable', 'string', 'max:10'],
            'rit.*.no_lambung' => ['nullable', 'string', 'max:20', 'required_without:rit.*.plat'], 'rit.*.plat' => ['nullable', 'string', 'max:30'],
            'rit.*.driver' => ['nullable', 'string', 'max:50'], 'rit.*.jenis_kendaraan' => ['required', 'string', 'max:40'], 'rit.*.pemilik' => ['required', 'string', 'max:60'],
            'rit.*.no_do' => ['nullable', 'string', 'max:30'], 'rit.*.harga_jual' => ['nullable', 'string', 'max:20'], 'rit.*.keterangan' => ['nullable', 'string', 'max:300'],
            'rit.*.konfirmasi' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private const PESAN = [
        'rit.required' => 'Isi minimal satu rit.',
        'rit.*.no_seri.required' => 'Rit baris :position: No Seri belum diisi.',
        'rit.*.no_lambung.required_without' => 'Rit baris :position: isi No Lambung (DT) atau Plat.',
        'rit.*.jenis_kendaraan.required' => 'Rit baris :position: Jenis Kendaraan belum diisi.',
        'rit.*.pemilik.required' => 'Rit baris :position: Pemilik belum diisi.',
    ];

    /** Pemeriksaan saat admin mengisi (longgar: rit yang sudah lengkap saja). */
    public function periksa(Request $request): JsonResponse
    {
        [$kepala, $rit] = $this->rapikan($request, true);
        if (! $kepala['tahap']) {
            return response()->json(['temuan' => (object) []]);
        }

        return response()->json(['temuan' => (object) ValidasiRitasi::periksa($kepala, $rit, $request->integer('baris_edit') ?: null)]);
    }

    /** Penentu akhir di server: rit ber-FLAG wajib dikonfirmasi (min. 10 karakter). */
    private function wajibKonfirmasi(array $kepala, array $rit, ?int $kecuali): array|RedirectResponse
    {
        $temuan = ValidasiRitasi::periksa($kepala, $rit, $kecuali);
        $pesan = [];
        foreach ($temuan as $i => $daftar) {
            if (mb_strlen((string) $rit[$i]['konfirmasi']) < self::MIN_KONFIRMASI) {
                $pesan["konfirmasi.{$i}"] = 'Rit baris '.($i + 1).' (No Seri '.$rit[$i]['no_seri'].'): '.count($daftar).' FLAG ('.implode(', ', array_unique(array_column($daftar, 'kode'))).') belum dikonfirmasi.';
            }
        }

        return $pesan ? back()->withInput()->withErrors($pesan) : $temuan;
    }

    private function catatTemuan(array $temuan, array $kepala, array $rit, int $userId): void
    {
        foreach ($temuan as $i => $daftar) {
            RitasiTemuan::where('tahap', $kepala['tahap'])->where('no_seri', $rit[$i]['no_seri'])->delete();
            foreach ($daftar as $t) {
                RitasiTemuan::create(['tahap' => $kepala['tahap'], 'no_seri' => $rit[$i]['no_seri'], 'no_do' => $rit[$i]['no_do'], 'aturan' => $t['kode'],
                    'prioritas' => $t['prioritas'], 'pesan' => mb_strimwidth($t['pesan'], 0, 1000, '…'), 'konfirmasi' => $rit[$i]['konfirmasi'], 'user_id' => $userId]);
            }
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate($this->aturan(), self::PESAN);
        [$kepala, $rit] = $this->rapikan($request, false);
        $rit = array_values($rit);
        $temuan = $this->wajibKonfirmasi($kepala, $rit, null);
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        try {
            $hasil = (new TulisRitasiSheet(GoogleSheets::wajib()))->tulis($kepala, $rit);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        $this->catatTemuan($temuan, $kepala, $rit, $request->user()->id);
        $ringkas = count($rit).' rit '.$kepala['tahap'].' · '.$kepala['galian'].' · '.$kepala['tanggal']->translatedFormat('j M Y');
        KasRiwayat::create(['aksi' => 'rit-tambah', 'lembar' => 'Ritasi', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth($ringkas, 0, 490, '…'), 'isi' => ['kepala' => [...$kepala, 'tanggal' => $kepala['tanggal']->toDateString()], 'rit' => $rit], 'user_id' => $request->user()->id]);

        return redirect()->route('ritasi.index', ['bulan' => $kepala['tanggal']->format('Y-m')])
            ->with('success', "Tersimpan di sheet Ritasi baris {$hasil['baris_awal']}–{$hasil['baris_akhir']}: {$ringkas}.");
    }

    public function edit(int $baris): View|RedirectResponse
    {
        $r = Ritasi::where('baris', $baris)->first();
        if (! $r) {
            return redirect()->route('ritasi.index')->with('error', "Rit di baris {$baris} tidak ditemukan — mungkin sheet berubah. Klik \"Sinkron dari sheet\".");
        }
        $edit = [
            'baris' => $r->baris, 'versi' => $this->versi($r), 'tahap' => $r->tahap, 'tanggal' => $r->tanggal?->toDateString(), 'galian' => $r->galian,
            'jenis_buangan' => $r->jenis_buangan, 'jenis_tanah' => $r->jenis_tanah,
            'rit' => [['no_seri' => $r->no_seri, 'jam' => $r->jam, 'no_lambung' => $r->no_lambung, 'plat' => $r->plat, 'driver' => $r->driver,
                'jenis_kendaraan' => $r->jenis_kendaraan, 'pemilik' => $r->pemilik, 'no_do' => $r->no_do, 'harga_jual' => $r->harga_jual, 'keterangan' => $r->keterangan]],
        ];

        return $this->create()->with('edit', $edit);
    }

    public function update(Request $request, int $baris): RedirectResponse
    {
        $r = Ritasi::where('baris', $baris)->first();
        if (! $r || $request->input('versi') !== $this->versi($r)) {
            return back()->withInput()->with('error', 'Rit ini sudah berubah di sheet sejak form dibuka (atau baru disinkron). Buka Edit lagi.');
        }
        $request->validate([...$this->aturan(), 'rit' => ['required', 'array', 'size:1']], self::PESAN);
        [$kepala, $rit] = $this->rapikan($request, false);
        $rit = array_values($rit);
        $temuan = $this->wajibKonfirmasi($kepala, $rit, $baris);
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        $lama = "No Seri {$r->no_seri} {$r->tanggal?->translatedFormat('j M Y')} {$r->no_lambung}";
        try {
            (new TulisRitasiSheet(GoogleSheets::wajib()))->ubah($r, $kepala, $rit[0]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal mengubah di sheet: '.$e->getMessage());
        }
        $this->catatTemuan($temuan, $kepala, $rit, $request->user()->id);
        KasRiwayat::create(['aksi' => 'rit-ubah', 'lembar' => 'Ritasi', 'baris_awal' => $baris, 'baris_akhir' => $baris,
            'ringkasan' => mb_strimwidth("{$lama} → No Seri {$rit[0]['no_seri']} {$kepala['tanggal']->translatedFormat('j M Y')} {$rit[0]['no_lambung']}", 0, 490, '…'),
            'isi' => ['kepala' => [...$kepala, 'tanggal' => $kepala['tanggal']->toDateString()], 'rit' => $rit], 'user_id' => $request->user()->id]);

        return redirect()->route('ritasi.index', ['bulan' => $kepala['tanggal']->format('Y-m')])->with('success', "Rit baris {$baris} diperbarui di sheet Ritasi.");
    }

    public function hapus(Request $request, int $baris): RedirectResponse
    {
        $r = Ritasi::where('baris', $baris)->firstOrFail();
        $ringkas = "No Seri {$r->no_seri} · {$r->tahap} · {$r->tanggal?->translatedFormat('j M Y')} · {$r->no_lambung} · DO {$r->no_do}";
        try {
            (new TulisRitasiSheet(GoogleSheets::wajib()))->hapus($r);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal menghapus: '.$e->getMessage());
        }
        KasRiwayat::create(['aksi' => 'rit-hapus', 'lembar' => 'Ritasi', 'baris_awal' => $baris, 'baris_akhir' => $baris, 'ringkasan' => $ringkas,
            'isi' => ['rit' => $r->toArray()], 'user_id' => $request->user()->id]);

        return redirect()->route('ritasi.index', ['bulan' => $r->tanggal?->format('Y-m'), 'q' => $request->input('q') ?: null])
            ->with('success', "Dihapus dari sheet Ritasi baris {$baris}: {$ringkas}.");
    }

    public function sinkron(): RedirectResponse
    {
        try {
            $n = LembarRitasi::impor(GoogleSheets::wajib());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Sinkron gagal: '.$e->getMessage());
        }

        return back()->with('success', "Ritasi disinkron dari sheet: {$n} rit.");
    }

    private function versi(Ritasi $r): string
    {
        return sha1(json_encode([$r->baris, $r->no_seri, $r->tanggal?->toDateString(), $r->no_lambung, $r->no_do, $r->harga_jual, $r->tahap]));
    }
}
