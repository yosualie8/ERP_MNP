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
use App\Support\PerformaRitasi;
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

    /** Performa Ritasi: jumlah rit per truk per bulan/tanggal (setahun), bisa disaring per tujuan buangan & dibagikan sebagai gambar. */
    public function performa(Request $request): View
    {
        $daftarTahun = PerformaRitasi::daftarTahun();
        $tahun = in_array($request->integer('tahun'), $daftarTahun, true) ? $request->integer('tahun') : ($daftarTahun[0] ?? (int) now()->year);
        // Tujuan buangan = catatan menu Buangan Truck (hanya dibaca): truk aktif & berstatus aktif di Data Aset.
        $tujuanTruk = BuanganTruk::daftar()->filter(fn ($t) => $t['status_aset'] === 'aktif' && $t['tujuan']);
        $daftarTujuan = $tujuanTruk->countBy('tujuan')->sortDesc();
        $tujuan = $daftarTujuan->has($request->query('tujuan')) ? (string) $request->query('tujuan') : null;
        $data = PerformaRitasi::data($tahun, $tujuan ? $tujuanTruk->where('tujuan', $tujuan)->pluck('no_lambung')->all() : null);

        return view('ritasi.performa', compact('daftarTahun', 'tahun', 'daftarTujuan', 'tujuan', 'data'));
    }

    /** Monitor Ritasi: DO yang sudah ada uang jalannya di Kas UJ tetapi belum ada di data Ritasi (belum bongkar). */
    public function monitor(Request $request): View
    {
        return $this->tampilMonitor($request, 'ritasi', MonitorRitasi::belumBongkar());
    }

    /**
     * Monitor Ritasi → simpan bongkar: admin mengisi Tanggal Bongkar, Tujuan Bongkar (tahap), Jenis Tanah & No Surat Jalan dari
     * surat jalan untuk DO yang belum bongkar; tiap baris yang diisi menjadi satu rit (jalur & validasi sama dengan Input Ritasi).
     * Truk, driver & jenis dari Kas UJ; jenis buangan "Ritasi"; pemilik & plat dari rit terakhir truk; harga jual dari riwayat.
     */
    public function simpanBongkar(Request $request): RedirectResponse
    {
        $data = $request->validate(['bongkar' => ['required', 'array'], 'bongkar.*' => ['array']]);
        $rapi = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $isian = collect($data['bongkar'])->map(fn ($b) => collect(['tanggal', 'jam', 'tahap', 'galian', 'jenis_tanah', 'no_seri', 'keterangan', 'konfirmasi'])
            ->mapWithKeys(fn ($k) => [$k => $rapi($b[$k] ?? null)])->all())
            // Baris yang tidak diisi sama sekali dilewati (galian terisi otomatis, jadi tidak dihitung). Jam bongkar tidak wajib.
            ->filter(fn ($b) => $b['tanggal'] || $b['jam'] || $b['tahap'] || $b['jenis_tanah'] || $b['no_seri'] || $b['keterangan']);
        if ($isian->isEmpty()) {
            return back()->with('error', 'Belum ada DO yang diisi data bongkarnya.');
        }
        $kurang = [];
        foreach ($isian as $do => $b) {
            $label = ['tanggal' => 'Tanggal Bongkar', 'tahap' => 'Tujuan Bongkar', 'galian' => 'Galian', 'jenis_tanah' => 'Jenis Tanah', 'no_seri' => 'No Surat Jalan'];
            $tidak = array_values(array_map(fn ($k) => $label[$k], array_filter(array_keys($label), fn ($k) => ! $b[$k])));
            if ($b['tanggal'] && ! self::tanggal($b['tanggal'])) {
                $tidak[] = 'Tanggal Bongkar tidak dikenali';
            }
            if ($tidak) {
                $kurang["bongkar.{$do}"] = "DO {$do}: ".implode(', ', $tidak).(count($tidak) > 1 || ! str_contains($tidak[0], 'dikenali') ? ' belum diisi.' : '.');
            }
        }
        if ($kurang) {
            return back()->withInput()->withErrors($kurang);
        }

        // Susun seperti isian form Input Ritasi, lalu lewat jalur yang sama (rapikan → galat/FLAG → tulis sheet).
        $dt = $this->infoDt();
        $harga = $this->petaHarga();
        $dariUj = ValidasiRitasi::dariUj($isian->keys()->map(fn ($k) => (string) $k)->all());
        $urutDo = $isian->keys()->map(fn ($k) => (string) $k)->values()->all();
        $baris = $isian->values()->map(function ($b, $i) use ($urutDo, $dt, $harga, $dariUj) {
            $do = $urutDo[$i];
            $uj = $dariUj[LembarRitasi::kunciAngka($do)] ?? [];
            $info = $dt[$uj['no_lambung'] ?? ''] ?? [];
            $pemilik = $info['pemilik'] ?? 'MNP';

            return [
                'tanggal' => $b['tanggal'], 'jam' => $b['jam'], 'tahap' => $b['tahap'], 'galian' => $b['galian'], 'jenis_buangan' => 'Ritasi', 'jenis_tanah' => $b['jenis_tanah'],
                'no_seri' => $b['no_seri'], 'plat' => $info['plat'] ?? null, 'pemilik' => $pemilik, 'no_do' => $do,
                'harga_jual' => (string) (self::cariHarga($harga, $b['tahap'], $b['galian'], $uj['jenis_kendaraan'] ?? null, $pemilik) ?? ''),
                'keterangan' => $b['keterangan'], 'konfirmasi' => $b['konfirmasi'],
            ];
        })->all();
        [$rit, $uj] = $this->rapikan(new Request(['rit' => $baris]), false);
        $rit = array_values($rit);
        $keDo = fn (int $i) => 'DO '.$urutDo[$i];
        $pesan = [];
        foreach (ValidasiRitasi::galat($rit, $uj, null) as $i => $daftar) {
            $pesan["bongkar.{$urutDo[$i]}"] = $keDo($i).': '.implode(' ', $daftar);
        }
        if ($pesan) {
            return back()->withInput()->withErrors($pesan);
        }
        $temuan = ValidasiRitasi::periksa($rit);
        foreach ($temuan as $i => $daftar) {
            if (mb_strlen((string) $rit[$i]['konfirmasi']) < self::MIN_KONFIRMASI) {
                $pesan["konfirmasi.{$urutDo[$i]}"] = $keDo($i).': '.collect($daftar)->pluck('pesan')->implode(' ').' — tulis konfirmasi (min. '.self::MIN_KONFIRMASI.' karakter) di baris DO itu.';
            }
        }
        if ($pesan) {
            return back()->withInput()->withErrors($pesan);
        }
        try {
            $hasil = (new TulisRitasiSheet(GoogleSheets::wajib()))->tulis($rit);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        $this->catatTemuan($temuan, $rit, $request->user()->id);
        $ringkas = count($rit).' rit dari Monitor Ritasi · DO '.implode(', ', $urutDo);
        KasRiwayat::create(['aksi' => 'rit-tambah', 'lembar' => 'Ritasi', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth($ringkas, 0, 490, '…'), 'isi' => ['rit' => self::untukRiwayat($rit)], 'user_id' => $request->user()->id]);
        $tanpaHarga = collect($rit)->filter(fn ($r) => ! $r['harga_jual'])->pluck('no_do');

        return back()->with('success', 'Tercatat bongkar & ditulis ke sheet Ritasi baris '.$hasil['baris_awal'].'–'.$hasil['baris_akhir'].': '.$ringkas.'.'
            .($tanpaHarga->isNotEmpty() ? ' Harga jual belum ditemukan dari riwayat untuk DO '.$tanpaHarga->implode(', ').' — lengkapi lewat menu Ritasi → Edit.' : ''));
    }

    /** Bayar Tanah: DO yang sudah ada Uang Jalan-nya di Kas UJ tetapi belum ada transaksi Uang Tanah-nya. */
    public function bayarTanah(Request $request): View
    {
        return $this->tampilMonitor($request, 'tanah', MonitorRitasi::belumBayarTanah());
    }

    /** Halaman monitor DO bersama (Monitor Ritasi & Bayar Tanah): saringan umur, tujuan buangan, galian & pencarian. */
    private function tampilMonitor(Request $request, string $mode, array $hasil): View
    {
        // Umur DO: ≤ 7 hari, ≤ 30 hari, atau all time (bawaan).
        $umur = in_array($request->query('umur'), ['7', '30'], true) ? $request->query('umur') : null;
        $q = trim((string) $request->query('q'));
        ['do' => $semua, 'bukan_angka' => $bukanAngka] = $hasil;
        $galian = trim((string) $request->query('galian'));
        $namaGalian = fn ($d) => $d['tujuan'] ? ucwords($d['tujuan']) : '(tidak diketahui)';
        $cocokGalian = fn ($d) => $galian === '' || $namaGalian($d) === $galian;
        // Tujuan buangan DO = tujuan buangan truk-truknya, hanya truk yang terdaftar di Buangan Truck (aktif 30 hari terakhir)
        // dan berstatus aktif di Data Aset. Truk lain (mis. DO lama dari truk yang sudah tidak jalan) tidak diberi tujuan.
        $tujuanTruk = BuanganTruk::daftar()->filter(fn ($t) => $t['status_aset'] === 'aktif' && $t['tujuan'])->pluck('tujuan', 'no_lambung');
        $semua = $semua->map(fn ($d) => [...$d, 'buangan' => collect($d['mobil'])->map(fn ($m) => $tujuanTruk[$m] ?? null)->filter()->unique()->values()->all()]);
        $tujuan = trim((string) $request->query('tujuan'));
        $cocokTujuan = fn ($d) => $tujuan === '' || ($tujuan === BuanganTruk::TANPA ? ! $d['buangan'] : in_array($tujuan, $d['buangan'], true));
        $cocokUmur = fn ($d) => $umur === null || ($d['umur'] !== null && $d['umur'] <= (int) $umur);
        $kata = array_filter(preg_split('/\s+/', mb_strtolower($q)));
        $cocokCari = fn ($d) => ! $kata || collect($kata)->every(fn ($w) => str_contains(mb_strtolower(implode(' ', [
            $d['do'], implode(' ', $d['mobil']), implode(' ', $d['driver']), (string) $d['tujuan'], implode(' ', $d['kategori']),
            collect($d['detail'])->map(fn ($x) => $x->keterangan.' '.$x->id_uj)->implode(' '),
        ])), $w));
        $hitung = fn ($f) => $semua->filter($f)->count();

        // Monitor Ritasi = tempat mengisi data bongkar: saran galian dari keterangan UJ & daftar saran isian.
        $saran = [];
        if ($mode === 'ritasi') {
            $semua = $semua->map(fn ($d) => [...$d, 'galian_saran' => TebakGalian::galian($d['tujuan'], null) ?? ($d['tujuan'] ? ucwords($d['tujuan']) : null)]);
            $sering = fn (string $kolom, int $hari) => Ritasi::where('tanggal', '>=', now()->subDays($hari))->whereNotNull($kolom)->where($kolom, '!=', '')
                ->select($kolom, DB::raw('COUNT(*) n'))->groupBy($kolom)->orderByDesc('n')->limit(60)->pluck($kolom);
            $saran = ['tahap' => $sering('tahap', 120), 'jenis_tanah' => $sering('jenis_tanah', 365), 'galian' => $sering('galian', 365)];
        }

        return view('ritasi.monitor', [
            'mode' => $mode, 'rute' => $mode === 'tanah' ? 'ritasi.bayar-tanah' : 'ritasi.monitor', 'saran' => $saran,
            'daftar' => $semua->filter($cocokUmur)->filter($cocokCari)->filter($cocokTujuan)->filter($cocokGalian)->values(),
            'semua' => $semua, 'umur' => $umur, 'q' => $q, 'tujuan' => $tujuan, 'galian' => $galian, 'bukanAngka' => $bukanAngka,
            'jumlahTujuan' => $semua->filter($cocokUmur)->filter($cocokGalian)->flatMap(fn ($d) => $d['buangan'])->countBy()->sortDesc(),
            'jumlahTanpa' => $semua->filter($cocokUmur)->filter($cocokGalian)->filter(fn ($d) => ! $d['buangan'])->count(),
            'jumlahGalian' => $semua->filter($cocokUmur)->filter($cocokTujuan)->map($namaGalian)->countBy()->sortDesc(),
            'jumlahUmur' => [
                '7' => $hitung(fn ($d) => $d['umur'] !== null && $d['umur'] <= 7),
                '30' => $hitung(fn ($d) => $d['umur'] !== null && $d['umur'] <= 30),
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

    /** DT → plat, driver, jenis, pemilik dari rit terakhir setahun (cadangan: driver & jenis dari Kas UJ). */
    private function infoDt(): array
    {
        $setahun = now()->subYear()->toDateString();
        $dt = [];
        foreach (UjDetail::whereNotNull('no_mobil')->orderBy('baris')->get(['no_mobil', 'nama', 'jenis_kendaraan']) as $d) {
            $dt[$d->no_mobil] = ['plat' => null, 'driver' => $d->nama, 'jenis' => $d->jenis_kendaraan, 'pemilik' => 'MNP'];
        }
        foreach (Ritasi::whereNotNull('no_lambung')->where('tanggal', '>=', $setahun)->orderBy('baris')->get(['no_lambung', 'no_polisi', 'plat', 'jenis_kendaraan', 'pemilik']) as $r) {
            $dt[$r->no_lambung] = ['plat' => $r->plat, 'driver' => $r->driver ?? ($dt[$r->no_lambung]['driver'] ?? null), 'jenis' => $r->jenis_kendaraan, 'pemilik' => $r->pemilik];
        }
        ksort($dt);

        return $dt;
    }

    /**
     * Harga jual terakhir dari riwayat, dari yang paling spesifik: tahap|galian|jenis|pemilik → galian|jenis|pemilik →
     * tahap|jenis|pemilik → jenis|pemilik → tahap|galian (harga terutama ditentukan kendaraan & pemiliknya, mis. Faw MNP 2,85 jt, Engkel RUDI 425 rb).
     */
    private function petaHarga(): array
    {
        $harga = [];
        foreach (Ritasi::whereNotNull('harga_jual')->where('harga_jual', '>', 0)->where('tanggal', '>=', now()->subYear()->toDateString())->orderBy('tanggal')->orderBy('baris')
            ->get(['tahap', 'galian', 'jenis_kendaraan', 'pemilik', 'harga_jual']) as $r) {
            foreach (["{$r->tahap}|{$r->galian}|{$r->jenis_kendaraan}|{$r->pemilik}", "*|{$r->galian}|{$r->jenis_kendaraan}|{$r->pemilik}",
                "{$r->tahap}|*|{$r->jenis_kendaraan}|{$r->pemilik}", "*|*|{$r->jenis_kendaraan}|{$r->pemilik}", "{$r->tahap}|{$r->galian}"] as $k) {
                $harga[mb_strtolower($k)] = (int) $r->harga_jual;
            }
        }

        return $harga;
    }

    /** Harga jual dari peta riwayat (urutan sama dengan saran di form Input Ritasi). */
    private static function cariHarga(array $harga, ?string $tahap, ?string $galian, ?string $jenis, ?string $pemilik): ?int
    {
        foreach (["{$tahap}|{$galian}|{$jenis}|{$pemilik}", "*|{$galian}|{$jenis}|{$pemilik}", "{$tahap}|*|{$jenis}|{$pemilik}", "*|*|{$jenis}|{$pemilik}", "{$tahap}|{$galian}"] as $k) {
            if (isset($harga[mb_strtolower($k)])) {
                return $harga[mb_strtolower($k)];
            }
        }

        return null;
    }

    public function create(): View
    {
        $setahun = now()->subYear()->toDateString();
        $sering = fn (string $kolom) => Ritasi::where('tanggal', '>=', $setahun)->whereNotNull($kolom)->where($kolom, '!=', '')
            ->select($kolom, DB::raw('COUNT(*) n'))->groupBy($kolom)->orderByDesc('n')->limit(60)->pluck($kolom);
        $dt = $this->infoDt();
        $harga = $this->petaHarga();
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
                // Truk bukan milik MNP: tidak ada di Kas UJ, jadi No Lambung, Driver & Jenis diisi manual.
                ...(ValidasiRitasi::milikMnp($r['pemilik'] ?? null) ? [] : [
                    'no_lambung' => $rapi(strtoupper((string) ($r['no_lambung'] ?? ''))), 'driver' => $rapi($r['driver'] ?? null),
                    'jenis_kendaraan' => NomorMobil::rapikanJenis($rapi($r['jenis_kendaraan'] ?? null)),
                ]),
                'harga_jual' => (int) preg_replace('/\D/', '', (string) ($r['harga_jual'] ?? '')) ?: null, 'keterangan' => $rapi($r['keterangan'] ?? null),
                'konfirmasi' => $rapi($r['konfirmasi'] ?? null),
            ];
            // Baris kosong tidak ikut diperiksa saat pemeriksaan longgar (form menandai baris per baris).
            if ($longgar && ! $baris['no_do'] && ! $baris['no_seri']) {
                continue;
            }
            $rit[(int) $i] = $baris;
        }
        $uj = ValidasiRitasi::dariUj(array_column(array_filter($rit, fn ($r) => ValidasiRitasi::milikMnp($r['pemilik'])), 'no_do'));
        foreach ($rit as $i => $r) {
            if (ValidasiRitasi::milikMnp($r['pemilik']) && ($d = $uj[LembarRitasi::kunciAngka($r['no_do'])] ?? null)) {
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
            // No DO wajib hanya untuk truk milik MNP (dicek di ValidasiRitasi::galat); truk mitra boleh tanpa DO.
            'rit.*.no_do' => ['nullable', 'string', 'max:30'],
            'rit.*.no_lambung' => ['nullable', 'string', 'max:20'], 'rit.*.driver' => ['nullable', 'string', 'max:60'], 'rit.*.jenis_kendaraan' => ['nullable', 'string', 'max:40'], 'rit.*.harga_jual' => ['nullable', 'string', 'max:20'], 'rit.*.keterangan' => ['nullable', 'string', 'max:300'],
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
