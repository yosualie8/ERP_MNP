<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\Ritasi;
use App\Models\RitasiTemuan;
use App\Models\UjDetail;
use App\Support\BuanganTruk;
use App\Support\GoogleSheets;
use App\Support\KasSeabank;
use App\Support\LembarRitasi;
use App\Support\MonitorRitasi;
use App\Support\NomorMobil;
use App\Support\TebakGalian;
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

    /** Monitor Ritasi: DO yang sudah ada uang jalannya di Kas UJ tetapi belum ada di data Ritasi (belum bongkar). */
    public function monitor(Request $request): View
    {
        $umur = in_array($request->query('umur'), ['7', '30', 'lama'], true) ? $request->query('umur') : null;
        $q = trim((string) $request->query('q'));
        ['do' => $semua, 'bukan_angka' => $bukanAngka] = MonitorRitasi::belumBongkar();
        // Tujuan buangan DO = tujuan buangan truk-truknya, hanya truk yang terdaftar di Buangan Truck (aktif 30 hari terakhir)
        // dan berstatus aktif di Data Aset. Truk lain (mis. DO lama dari truk yang sudah tidak jalan) tidak diberi tujuan.
        $tujuanTruk = BuanganTruk::daftar()->filter(fn ($t) => $t['status_aset'] === 'aktif' && $t['tujuan'])->pluck('tujuan', 'no_lambung');
        $semua = $semua->map(fn ($d) => [...$d, 'buangan' => collect($d['mobil'])->map(fn ($m) => $tujuanTruk[$m] ?? null)->filter()->unique()->values()->all()]);
        $tujuan = trim((string) $request->query('tujuan'));
        $cocokTujuan = fn ($d) => $tujuan === '' || ($tujuan === BuanganTruk::TANPA ? ! $d['buangan'] : in_array($tujuan, $d['buangan'], true));
        $cocokUmur = fn ($d) => match ($umur) {
            '7' => $d['umur'] !== null && $d['umur'] <= 7,
            '30' => $d['umur'] !== null && $d['umur'] > 7 && $d['umur'] <= 30,
            'lama' => $d['umur'] === null || $d['umur'] > 30,
            default => true,
        };
        $kata = array_filter(preg_split('/\s+/', mb_strtolower($q)));
        $cocokCari = fn ($d) => ! $kata || collect($kata)->every(fn ($w) => str_contains(mb_strtolower(implode(' ', [
            $d['do'], implode(' ', $d['mobil']), implode(' ', $d['driver']), (string) $d['tujuan'], implode(' ', $d['kategori']),
            collect($d['detail'])->map(fn ($x) => $x->keterangan.' '.$x->id_uj)->implode(' '),
        ])), $w));
        $hitung = fn ($f) => $semua->filter($f)->count();

        return view('ritasi.monitor', [
            'daftar' => $semua->filter($cocokUmur)->filter($cocokCari)->filter($cocokTujuan)->values(),
            'semua' => $semua, 'umur' => $umur, 'q' => $q, 'tujuan' => $tujuan, 'bukanAngka' => $bukanAngka,
            'jumlahTujuan' => $semua->filter($cocokUmur)->flatMap(fn ($d) => $d['buangan'])->countBy()->sortDesc(),
            'jumlahTanpa' => $semua->filter($cocokUmur)->filter(fn ($d) => ! $d['buangan'])->count(),
            'jumlahUmur' => [
                '7' => $hitung(fn ($d) => $d['umur'] !== null && $d['umur'] <= 7),
                '30' => $hitung(fn ($d) => $d['umur'] !== null && $d['umur'] > 7 && $d['umur'] <= 30),
                'lama' => $hitung(fn ($d) => $d['umur'] === null || $d['umur'] > 30),
            ],
            'ritasiTerakhir' => Ritasi::max('tanggal'),
        ]);
    }

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

        // Harga jual terakhir dari riwayat, dari yang paling spesifik: tahap|galian|jenis|pemilik → galian|jenis|pemilik →
        // tahap|jenis|pemilik → jenis|pemilik → tahap|galian (harga terutama ditentukan kendaraan & pemiliknya, mis. Faw MNP 2,85 jt, Engkel RUDI 425 rb).
        $harga = [];
        foreach (Ritasi::whereNotNull('harga_jual')->where('harga_jual', '>', 0)->where('tanggal', '>=', $setahun)->orderBy('tanggal')->orderBy('baris')
            ->get(['tahap', 'galian', 'jenis_kendaraan', 'pemilik', 'harga_jual']) as $r) {
            foreach (["{$r->tahap}|{$r->galian}|{$r->jenis_kendaraan}|{$r->pemilik}", "*|{$r->galian}|{$r->jenis_kendaraan}|{$r->pemilik}",
                "{$r->tahap}|*|{$r->jenis_kendaraan}|{$r->pemilik}", "*|*|{$r->jenis_kendaraan}|{$r->pemilik}", "{$r->tahap}|{$r->galian}"] as $k) {
                $harga[mb_strtolower($k)] = (int) $r->harga_jual;
            }
        }
        $hargaSering = Ritasi::where('tanggal', '>=', now()->subMonths(3))->where('harga_jual', '>', 0)
            ->select('harga_jual', DB::raw('COUNT(*) n'))->groupBy('harga_jual')->orderByDesc('n')->limit(8)->pluck('harga_jual');

        // No Seri berikutnya per tahap (angka terbesar + 1, panjang nol di depan mengikuti yang terakhir).
        $seri = Ritasi::whereNotNull('no_seri')->where('tanggal', '>=', $setahun)->orderBy('baris')->get(['tahap', 'no_seri'])
            ->groupBy('tahap')->map(fn ($g) => ['max' => $g->map(fn ($r) => (int) preg_replace('/\D/', '', $r->no_seri))->max(), 'pad' => strlen((string) $g->last()->no_seri)]);

        $tahap = Ritasi::where('tanggal', '>=', now()->subDays(120))->whereNotNull('tahap')->select('tahap', DB::raw('MAX(baris) b'))->groupBy('tahap')->orderByDesc('b')->pluck('tahap');

        return view('ritasi.input', [
            'tahap' => $tahap, 'galian' => $sering('galian'), 'jenisTanah' => $sering('jenis_tanah'), 'jenisBuangan' => $sering('jenis_buangan'),
            'jenisKendaraan' => $sering('jenis_kendaraan'), 'pemilik' => $sering('pemilik'), 'dt' => $dt, 'harga' => $harga, 'hargaSering' => $hargaSering, 'seri' => $seri,
        ]);
    }

    /** "8/10/2026", "08-10-26", "2026-10-08", "8 Okt 2026" → tanggal. */
    public static function tanggal(?string $v): ?\Carbon\Carbon
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $v, $m)) {
            $th = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $th) ? Carbon::create($th, (int) $m[2], (int) $m[1]) : null;
        }
        $t = KasSeabank::tanggal($v) ?? KasSeabank::tanggal(str_replace(' ', '-', $v));

        return $t && $t->year >= 2020 && $t->year <= 2100 ? $t : null;
    }

    /**
     * Rit yang sudah dirapikan, masing-masing berdiri sendiri (indeks dipertahankan). DT, driver & jenis kendaraan SELALU diambil
     * dari Kas UJ lewat No DO (isian dari browser diabaikan).
     *
     * @return array{0: array<int, array>, 1: array<string, array>} rit & data Kas UJ per kunci DO
     */
    private function rapikan(Request $request, bool $longgar): array
    {
        $rapi = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $rit = [];
        foreach ((array) $request->input('rit', []) as $i => $r) {
            $baris = [
                'tahap' => $rapi($r['tahap'] ?? null), 'tanggal' => self::tanggal($r['tanggal'] ?? null), 'galian' => $rapi($r['galian'] ?? null),
                'jenis_buangan' => $rapi($r['jenis_buangan'] ?? null), 'jenis_tanah' => $rapi($r['jenis_tanah'] ?? null),
                'no_seri' => $rapi($r['no_seri'] ?? null), 'jam' => LembarRitasi::jam($rapi($r['jam'] ?? null)), 'no_lambung' => null,
                'plat' => $rapi(strtoupper((string) ($r['plat'] ?? ''))), 'driver' => null, 'jenis_kendaraan' => null,
                'pemilik' => $rapi($r['pemilik'] ?? null), 'no_do' => $rapi(preg_replace('/\s+/', '', (string) ($r['no_do'] ?? ''))),
                'harga_jual' => (int) preg_replace('/\D/', '', (string) ($r['harga_jual'] ?? '')) ?: null, 'keterangan' => $rapi($r['keterangan'] ?? null),
                'konfirmasi' => $rapi($r['konfirmasi'] ?? null),
            ];
            // Baris kosong tidak ikut diperiksa saat pemeriksaan longgar (form menandai baris per baris).
            if ($longgar && ! $baris['no_do'] && ! $baris['no_seri']) {
                continue;
            }
            $rit[(int) $i] = $baris;
        }
        $uj = ValidasiRitasi::dariUj(array_column($rit, 'no_do'));
        foreach ($rit as $i => $r) {
            if ($d = $uj[LembarRitasi::kunciAngka($r['no_do'])] ?? null) {
                $rit[$i] = [...$r, 'no_lambung' => $d['no_lambung'], 'driver' => $d['driver'], 'jenis_kendaraan' => $d['jenis_kendaraan']];
            }
        }

        return [$rit, $uj];
    }

    private function aturan(): array
    {
        return [
            'rit' => ['required', 'array', 'min:1'],
            'rit.*.tanggal' => ['required', 'string', 'max:20', function ($a, $v, $gagal) {
                if (! self::tanggal($v)) {
                    $gagal('Rit baris '.((int) explode('.', $a)[1] + 1).': tanggal "'.$v.'" tidak dikenali (contoh 8/10/2026).');
                }
            }],
            'rit.*.tahap' => ['required', 'string', 'max:120'], 'rit.*.galian' => ['required', 'string', 'max:80'],
            'rit.*.jenis_buangan' => ['required', 'string', 'max:40'], 'rit.*.jenis_tanah' => ['required', 'string', 'max:60'],
            'rit.*.no_seri' => ['required', 'string', 'max:30'], 'rit.*.jam' => ['nullable', 'string', 'max:10'],
            'rit.*.plat' => ['nullable', 'string', 'max:30'], 'rit.*.pemilik' => ['required', 'string', 'max:60'],
            'rit.*.no_do' => ['required', 'string', 'max:30'], 'rit.*.harga_jual' => ['nullable', 'string', 'max:20'], 'rit.*.keterangan' => ['nullable', 'string', 'max:300'],
            'rit.*.konfirmasi' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private const PESAN = [
        'rit.required' => 'Isi minimal satu rit.',
        'rit.*.tanggal.required' => 'Rit baris :position: Tanggal belum diisi.',
        'rit.*.tahap.required' => 'Rit baris :position: Tahap belum diisi.',
        'rit.*.galian.required' => 'Rit baris :position: Galian belum diisi.',
        'rit.*.jenis_buangan.required' => 'Rit baris :position: Jenis Buangan belum diisi.',
        'rit.*.jenis_tanah.required' => 'Rit baris :position: Jenis Tanah belum diisi.',
        'rit.*.no_seri.required' => 'Rit baris :position: No Seri belum diisi.',
        'rit.*.no_do.required' => 'Rit baris :position: No DO wajib diisi.',
        'rit.*.pemilik.required' => 'Rit baris :position: Pemilik belum diisi.',
    ];

    /** Pemeriksaan saat admin mengisi: data truk dari Kas UJ per baris, galat (memblokir) & FLAG. */
    public function periksa(Request $request): JsonResponse
    {
        [$rit, $uj] = $this->rapikan($request, true);
        $truk = [];
        foreach ($rit as $i => $r) {
            // Galian hanya saran dari keterangan Kas UJ (admin boleh mengganti).
            $tempat = $uj[LembarRitasi::kunciAngka($r['no_do'])]['tempat'] ?? null;
            $truk[$i] = ['no_lambung' => $r['no_lambung'], 'driver' => $r['driver'], 'jenis_kendaraan' => $r['jenis_kendaraan'], 'galian' => TebakGalian::galian($tempat, $r['tahap'])];
        }

        return response()->json([
            'truk' => (object) $truk,
            'galat' => (object) ValidasiRitasi::galat($rit, $uj, $request->integer('baris_edit') ?: null),
            'temuan' => (object) ValidasiRitasi::periksa($rit),
        ]);
    }

    /** Penentu akhir di server: galat memblokir; rit ber-FLAG wajib dikonfirmasi (min. 10 karakter). */
    private function wajibKonfirmasi(array $rit, array $uj, ?int $kecuali): array|RedirectResponse
    {
        $pesan = [];
        foreach (ValidasiRitasi::galat($rit, $uj, $kecuali) as $i => $daftar) {
            $pesan["galat.{$i}"] = 'Rit baris '.($i + 1).': '.implode(' ', $daftar);
        }
        if ($pesan) {
            return back()->withInput()->withErrors($pesan);
        }
        $temuan = ValidasiRitasi::periksa($rit);
        foreach ($temuan as $i => $daftar) {
            if (mb_strlen((string) $rit[$i]['konfirmasi']) < self::MIN_KONFIRMASI) {
                $pesan["konfirmasi.{$i}"] = 'Rit baris '.($i + 1).' (No Seri '.$rit[$i]['no_seri'].'): '.count($daftar).' FLAG ('.implode(', ', array_unique(array_column($daftar, 'kode'))).') belum dikonfirmasi.';
            }
        }

        return $pesan ? back()->withInput()->withErrors($pesan) : $temuan;
    }

    private function catatTemuan(array $temuan, array $rit, int $userId): void
    {
        foreach ($temuan as $i => $daftar) {
            RitasiTemuan::where('tahap', $rit[$i]['tahap'])->where('no_seri', $rit[$i]['no_seri'])->delete();
            foreach ($daftar as $t) {
                RitasiTemuan::create(['tahap' => $rit[$i]['tahap'], 'no_seri' => $rit[$i]['no_seri'], 'no_do' => $rit[$i]['no_do'], 'aturan' => $t['kode'],
                    'prioritas' => $t['prioritas'], 'pesan' => mb_strimwidth($t['pesan'], 0, 1000, '…'), 'konfirmasi' => $rit[$i]['konfirmasi'], 'user_id' => $userId]);
            }
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate($this->aturan(), self::PESAN);
        [$rit, $uj] = $this->rapikan($request, false);
        $rit = array_values($rit);
        $temuan = $this->wajibKonfirmasi($rit, $uj, null);
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        try {
            $hasil = (new TulisRitasiSheet(GoogleSheets::wajib()))->tulis($rit);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        $this->catatTemuan($temuan, $rit, $request->user()->id);
        $tgl = collect($rit)->pluck('tanggal')->sort()->values();
        $ringkas = count($rit).' rit · '.collect($rit)->pluck('tahap')->unique()->implode(', ').' · '.$tgl->first()->translatedFormat('j M Y')
            .($tgl->last()->ne($tgl->first()) ? ' – '.$tgl->last()->translatedFormat('j M Y') : '');
        KasRiwayat::create(['aksi' => 'rit-tambah', 'lembar' => 'Ritasi', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth($ringkas, 0, 490, '…'), 'isi' => ['rit' => self::untukRiwayat($rit)], 'user_id' => $request->user()->id]);

        return redirect()->route('ritasi.index', ['bulan' => $tgl->last()->format('Y-m')])
            ->with('success', "Tersimpan di sheet Ritasi baris {$hasil['baris_awal']}–{$hasil['baris_akhir']}: {$ringkas}.");
    }

    public function edit(int $baris): View|RedirectResponse
    {
        $r = Ritasi::where('baris', $baris)->first();
        if (! $r) {
            return redirect()->route('ritasi.index')->with('error', "Rit di baris {$baris} tidak ditemukan — mungkin sheet berubah. Klik \"Sinkron dari sheet\".");
        }
        $edit = [
            'baris' => $r->baris, 'versi' => $this->versi($r),
            'rit' => [['tanggal' => $r->tanggal?->format('d/m/Y'), 'tahap' => $r->tahap, 'galian' => $r->galian, 'jenis_buangan' => $r->jenis_buangan, 'jenis_tanah' => $r->jenis_tanah,
                'no_seri' => $r->no_seri, 'jam' => $r->jam, 'no_lambung' => $r->no_lambung, 'plat' => $r->plat, 'driver' => $r->driver,
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
        [$rit, $uj] = $this->rapikan($request, false);
        $rit = array_values($rit);
        $temuan = $this->wajibKonfirmasi($rit, $uj, $baris);
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        $lama = "No Seri {$r->no_seri} {$r->tanggal?->translatedFormat('j M Y')} {$r->no_lambung}";
        try {
            (new TulisRitasiSheet(GoogleSheets::wajib()))->ubah($r, $rit[0]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal mengubah di sheet: '.$e->getMessage());
        }
        $this->catatTemuan($temuan, $rit, $request->user()->id);
        KasRiwayat::create(['aksi' => 'rit-ubah', 'lembar' => 'Ritasi', 'baris_awal' => $baris, 'baris_akhir' => $baris,
            'ringkasan' => mb_strimwidth("{$lama} → No Seri {$rit[0]['no_seri']} {$rit[0]['tanggal']->translatedFormat('j M Y')} {$rit[0]['no_lambung']}", 0, 490, '…'),
            'isi' => ['rit' => self::untukRiwayat($rit)], 'user_id' => $request->user()->id]);

        return redirect()->route('ritasi.index', ['bulan' => $rit[0]['tanggal']->format('Y-m')])->with('success', "Rit baris {$baris} diperbarui di sheet Ritasi.");
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

    private static function untukRiwayat(array $rit): array
    {
        return array_map(fn ($r) => [...$r, 'tanggal' => $r['tanggal']->toDateString()], $rit);
    }

    private function versi(Ritasi $r): string
    {
        return sha1(json_encode([$r->baris, $r->no_seri, $r->tanggal?->toDateString(), $r->no_lambung, $r->no_do, $r->harga_jual, $r->tahap]));
    }
}
