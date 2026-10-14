<?php

namespace App\Http\Controllers;

use App\Models\AsetTruk;
use App\Models\Bbm;
use App\Models\BbmUnitPeta;
use App\Models\KasRiwayat;
use App\Models\ProyekTahap;
use App\Support\GoogleSheets;
use App\Support\LembarBbm;
use App\Support\LembarRitasi;
use App\Support\TulisBbmSheet;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Menu Data BBM & Input BBM (lembar "Penggunaan BBM"). */
class BbmController extends Controller
{
    /** Baris yang perlu dilengkapi admin: pemakaian solar tanpa proyek yang dikenali, unit belum dikenal. */
    private function perlu()
    {
        // Alat yang bekerja (liter keluar ATAU jam HM > 0) tanpa proyek yang dikenali, dan unit yang belum dikenal.
        return Bbm::where(fn ($q) => $q->where(fn ($w) => $w->where('jenis', 'pakai')
            ->where(fn ($k) => $k->where('keluar', '>', 0)->orWhere(fn ($h) => $h->where('total_hm', '>', 0)->where('total_hm', '<=', 24)))
            ->whereNull('tahap_ids')->where(fn ($p) => $p->whereNull('proyek')->orWhere('proyek', '!=', 'Pool')))
            ->orWhere('jenis', 'cek'));
    }

    public function index(Request $request): View
    {
        $daftarBulan = Bbm::whereNotNull('tanggal')->selectRaw("DATE_FORMAT(tanggal, '%Y-%m') as bulan")->distinct()->orderBy('bulan')->pluck('bulan');
        $perlu = $request->boolean('perlu');
        $bulan = $perlu ? null : ($daftarBulan->contains($request->query('bulan')) ? $request->query('bulan') : $daftarBulan->last());
        $baris = collect();
        if ($perlu) {
            $baris = $this->perlu()->orderByDesc('baris')->get();
        } elseif ($bulan) {
            $awal = Carbon::parse($bulan.'-01');
            $baris = Bbm::whereBetween('tanggal', [$awal, $awal->copy()->endOfMonth()])->orderByDesc('baris')->get();
        }
        $terakhir = Bbm::whereNotNull('stok')->orderByDesc('baris')->first();

        return view('bbm.index', [
            'daftarBulan' => $daftarBulan, 'bulan' => $bulan, 'baris' => $baris, 'perlu' => $perlu, 'jumlahPerlu' => $this->perlu()->count(),
            'stokSekarang' => $terakhir?->stok, 'stokPada' => $terakhir?->tanggal,
            'diimpor' => Cache::get('bbm-diimpor-pada'), 'bolehInput' => $request->user()->bolehMenu('input-bbm'),
        ]);
    }

    /**
     * Data master Input BBM — isian harus PERSIS sama (huruf besar/kecil & spasi): unit = nama di Data Aset (semua status),
     * sumber solar / pindah / susut / operasional yang sudah dipakai di data BBM, "Isi Solar dari DT xxx" untuk tiap DT;
     * proyek = nama tahap di Daftar Proyek atau "Pool"; status = daftar baku.
     *
     * @return array{unit: array<string, string>, tahap: array<int, string>, status: array<int, string>}
     */
    private function master(): array
    {
        if ($this->master) {
            return $this->master;
        }
        $unit = [];
        foreach (AsetTruk::orderBy('no_lambung')->pluck('no_lambung') as $n) {
            $unit[$n] = 'pakai';
        }
        foreach (Bbm::whereIn('jenis', ['masuk', 'pindah', 'susut', 'operasional'])->whereNotNull('unit')->distinct()->get(['unit', 'jenis']) as $b) {
            $unit[$b->unit] ??= $b->jenis;
        }
        foreach (array_keys($unit) as $n) {
            if (preg_match('/^DT \d{3}$/', $n)) {
                $unit["Isi Solar dari {$n}"] ??= 'masuk';
            }
        }
        $tahap = ProyekTahap::where('nama', 'not like', '%(biaya%')->where('nama', 'not like', 'Biaya Umum%')->orderBy('nama')->pluck('nama')->prepend('Pool')->all();

        return $this->master = ['unit' => $unit, 'tahap' => $tahap, 'status' => LembarBbm::STATUS];
    }

    private ?array $master = null;

    /** Pesan bila isian tidak persis sama dengan master (null bila cocok / kosong). */
    private static function bedaMaster(?string $v, array $daftar, string $label): ?string
    {
        if ($v === null || $v === '' || in_array($v, $daftar, true)) {
            return null;
        }
        $kunci = fn ($x) => mb_strtolower(preg_replace('/\s+/', '', (string) $x));
        $mirip = collect($daftar)->first(fn ($d) => $kunci($d) === $kunci($v));

        return $mirip !== null
            ? "{$label} \"{$v}\" harus ditulis persis \"{$mirip}\" (beda huruf besar/kecil atau spasi)."
            : "{$label} \"{$v}\" tidak ada di data master — pilih dari daftar.";
    }

    /**
     * Dashboard BBM & HM: tren liter keluar, jam HM & rasio liter/jam per bulan (keseluruhan & per alat), konsumsi per proyek,
     * dan kelengkapan data HM. Rasio dihitung dari JUMLAH sebulan (isian sebagian-sebagian saling menutup), hanya dari
     * alat-bulan yang HM-nya tercatat; bulan dengan liter tetapi tanpa HM ditandai "HM kosong".
     */
    public function dashboard(Request $request): View
    {
        $bulan = in_array($request->integer('bulan'), [3, 6, 12, 24], true) ? $request->integer('bulan') : 12;
        $mulai = now()->startOfMonth()->subMonths($bulan - 1);
        $daftarBulan = collect(range(0, $bulan - 1))->map(fn ($i) => $mulai->copy()->addMonths($i)->format('Y-m'))->all();
        $baris = Bbm::where('tanggal', '>=', $mulai)->whereIn('jenis', ['pakai', 'masuk'])
            ->get(['tanggal', 'jenis', 'unit', 'masuk', 'keluar', 'total_hm', 'tahap_ids']);
        $hm = fn ($b) => $b->total_hm !== null && $b->total_hm > 0 && $b->total_hm <= 24 ? (float) $b->total_hm : 0.0;
        $pakai = $baris->where('jenis', 'pakai');

        // per alat per bulan
        $alat = [];
        foreach ($pakai as $b) {
            $m = $b->tanggal->format('Y-m');
            $alat[$b->unit][$m]['l'] = ($alat[$b->unit][$m]['l'] ?? 0) + (float) $b->keluar;
            $alat[$b->unit][$m]['h'] = ($alat[$b->unit][$m]['h'] ?? 0) + $hm($b);
        }
        $kategori = AsetTruk::pluck('kategori', 'no_lambung');
        $ringkasAlat = [];
        foreach ($alat as $unit => $perBulan) {
            // rasio hanya dari bulan yang liter & HM-nya sama-sama tercatat
            $ok = array_filter($perBulan, fn ($x) => $x['h'] > 0 && $x['l'] > 0);
            $rasioBulan = array_map(fn ($x) => round($x['l'] / $x['h'], 2), $ok);
            $median = $rasioBulan ? (float) collect($rasioBulan)->median() : null;
            $hOk = array_sum(array_column($ok, 'h'));
            $ringkasAlat[$unit] = [
                'unit' => $unit, 'kategori' => AsetTruk::KATEGORI[$kategori[$unit] ?? ''] ?? '',
                'liter' => round(array_sum(array_column($perBulan, 'l')), 1), 'hm' => round(array_sum(array_column($perBulan, 'h')), 1),
                'rasio' => $hOk > 0 ? round(array_sum(array_column($ok, 'l')) / $hOk, 2) : null, 'median' => $median !== null ? round($median, 2) : null,
                'bulan' => array_combine($daftarBulan, array_map(fn ($m) => ['l' => round($perBulan[$m]['l'] ?? 0, 1), 'h' => round($perBulan[$m]['h'] ?? 0, 1),
                    'r' => $rasioBulan[$m] ?? null], $daftarBulan)),
                'hm_kosong' => count(array_filter($perBulan, fn ($x) => $x['l'] > 0 && $x['h'] <= 0)),
                // bulan yang rasionya jauh dari median (> 1,6× atau < 1/1,6) — biasanya HM tidak lengkap / salah ketik
                'janggal' => $median ? count(array_filter($rasioBulan, fn ($r) => $r > $median * 1.6 || $r < $median / 1.6)) : 0,
            ];
        }
        uasort($ringkasAlat, fn ($a, $b) => $b['liter'] <=> $a['liter']);

        // keseluruhan per bulan (rasio dari alat-bulan yang HM-nya tercatat)
        $total = [];
        foreach ($daftarBulan as $m) {
            $l = $h = $lOk = $hOk = 0;
            foreach ($ringkasAlat as $a) {
                $x = $a['bulan'][$m];
                $l += $x['l'];
                $h += $x['h'];
                if ($x['r'] !== null) {
                    $lOk += $x['l'];
                    $hOk += $x['h'];
                }
            }
            $total[$m] = ['l' => round($l, 1), 'h' => round($h, 1), 'r' => $hOk > 0 ? round($lOk / $hOk, 2) : null, 'lOk' => $lOk, 'hOk' => $hOk,
                'masuk' => round((float) $baris->where('jenis', 'masuk')->filter(fn ($b) => $b->tanggal->format('Y-m') === $m)->sum('masuk'), 1)];
        }

        // per tahap & per proyek (baris berproyek gabungan dibagi rata)
        $tahap = ProyekTahap::with('proyek')->get()->keyBy('id');
        $perTahap = [];
        foreach ($pakai as $b) {
            $ids = $b->tahap_ids ?: [];
            foreach ($ids as $id) {
                if (! $t = $tahap[$id] ?? null) {
                    continue;
                }
                $perTahap[$id]['tahap'] = $t->nama;
                $perTahap[$id]['proyek'] = $t->proyek?->nama ?? '–';
                $perTahap[$id]['l'] = ($perTahap[$id]['l'] ?? 0) + (float) $b->keluar / count($ids);
                $perTahap[$id]['h'] = ($perTahap[$id]['h'] ?? 0) + $hm($b) / count($ids);
            }
        }
        uasort($perTahap, fn ($a, $b) => $b['l'] <=> $a['l']);
        $perProyek = collect($perTahap)->groupBy('proyek')->map(fn ($g) => ['l' => $g->sum('l'), 'h' => $g->sum('h'), 'tahap' => $g->count()])->sortByDesc('l');

        $lOk = array_sum(array_column($total, 'lOk'));
        $hOk = array_sum(array_column($total, 'hOk'));

        return view('bbm.dashboard', [
            'bulan' => $bulan, 'daftarBulan' => $daftarBulan, 'total' => $total, 'alat' => array_values($ringkasAlat),
            'perTahap' => array_values($perTahap), 'perProyek' => $perProyek,
            'kartu' => [
                'liter' => $pakai->sum('keluar'), 'hm' => $pakai->sum($hm), 'masuk' => $baris->where('jenis', 'masuk')->sum('masuk'),
                'rasio' => $hOk > 0 ? round($lOk / $hOk, 2) : null,
                'stok' => Bbm::whereNotNull('stok')->orderByDesc('baris')->value('stok'),
                'tanpa_hm' => $pakai->filter(fn ($b) => $b->keluar > 0 && $hm($b) <= 0)->count(),
                'isi' => $pakai->where('keluar', '>', 0)->count(),
            ],
        ]);
    }

    /** Nama unit master + info terakhirnya (jenis, HM akhir, proyek) untuk mengisi otomatis. */
    private function infoUnit(): array
    {
        $info = array_map(fn ($j) => ['jenis' => $j], $this->master()['unit']);
        // HM akhir & proyek terakhir per unit (dari data setahun terakhir).
        foreach (Bbm::where('tanggal', '>=', now()->subYear())->whereNotNull('unit')->orderBy('baris')->get(['unit', 'jenis', 'hm_akhir', 'proyek', 'tanggal']) as $b) {
            $k = $b->unit;
            if (! isset($info[$k]) || $b->jenis === 'cek' || $b->jenis === 'opname') {
                continue;
            }
            $info[$k] = [...($info[$k] ?? []), 'jenis' => $b->jenis, 'terakhir' => $b->tanggal?->toDateString()];
            if ($b->hm_akhir !== null) {
                $info[$k]['hm'] = $b->hm_akhir;
            }
            // Saran proyek hanya dari catatan 30 hari terakhir (proyek lama sudah tidak relevan).
            if ($b->proyek && ! str_contains($b->proyek, ' + ') && $b->tanggal?->gte(now()->subDays(30))) {
                $info[$k]['proyek'] = $b->proyek;
            }
        }
        // Yang paling baru dipakai di atas.
        uksort($info, fn ($a, $b) => [$info[$b]['terakhir'] ?? '', $a] <=> [$info[$a]['terakhir'] ?? '', $b]);

        return $info;
    }

    public function create(): View
    {
        $tahap = ProyekTahap::where('nama', 'not like', '%(biaya%')->where('nama', 'not like', 'Biaya Umum%')
            ->whereIn('status', ['belum', 'progress'])->orderBy('nama')->pluck('nama')->prepend('Pool');
        $hariTerakhir = Bbm::where('jenis', 'pakai')->max('tanggal');

        return view('bbm.input', [
            'unit' => $this->infoUnit(), 'tahap' => $tahap, 'tahapSemua' => $this->master()['tahap'], 'status' => LembarBbm::STATUS,
            'unitTerakhir' => $hariTerakhir ? Bbm::where('jenis', 'pakai')->where('tanggal', $hariTerakhir)->orderBy('baris')->pluck('unit')->unique()->values() : collect(),
            'hariTerakhir' => $hariTerakhir,
        ]);
    }

    /**
     * Baris yang sudah dirapikan & galatnya.
     *
     * @return array{0: array<int, array>, 1: array<string, string>}
     */
    private function rapikan(Request $request): array
    {
        $rapi = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        // Isian ber-master (unit, proyek, status) TIDAK dirapikan: harus persis sama dengan master, beda spasi pun ditolak.
        $mentah = fn ($v) => ($v = (string) $v) === '' ? null : $v;
        $master = $this->master();
        $angka = fn ($v) => ($v = trim(str_replace(' ', '', (string) $v))) === '' ? null : (is_numeric($x = str_replace(',', '.', $v)) ? (float) $x : false);
        $tanggal = RitasiController::tanggal($request->input('tanggal'));
        $galat = [];
        if (! $tanggal) {
            $galat['tanggal'] = 'Tanggal belum diisi / tidak dikenali.';
        } elseif ($tanggal->gt(now()->endOfDay())) {
            $galat['tanggal'] = 'Tanggal tidak boleh lewat dari hari ini.';
        }
        $peta = BbmUnitPeta::get()->keyBy('kunci')->all();
        $hasil = [];
        foreach ((array) $request->input('baris', []) as $i => $r) {
            $b = [
                'tanggal' => $tanggal, 'unit' => $mentah($r['unit'] ?? null), 'hm_awal' => $angka($r['hm_awal'] ?? null), 'hm_akhir' => $angka($r['hm_akhir'] ?? null),
                'total_hm' => $angka($r['total_hm'] ?? null), 'masuk' => $angka($r['masuk'] ?? null), 'keluar' => $angka($r['keluar'] ?? null),
                'jam_mulai' => LembarRitasi::jam($rapi($r['jam_mulai'] ?? null)), 'istirahat' => null, // kolom Rest tidak dipakai lagi
                'jam_selesai' => LembarRitasi::jam($rapi($r['jam_selesai'] ?? null)), 'status' => $mentah($r['status'] ?? null),
                // Kolom Rental & Keterangan kerja tidak diisi lagi dari form (data lama di sheet dipertahankan saat Edit).
                'keterangan' => $rapi($r['keterangan'] ?? null), 'rental' => null, 'proyek' => $mentah($r['proyek'] ?? null),
                'keterangan_kerja' => null, 'jenis' => $rapi($r['jenis'] ?? null),
            ];
            $isi = array_filter(array_diff_key($b, ['tanggal' => 1, 'jenis' => 1]), fn ($v) => $v !== null);
            if (! $isi) {
                continue;
            }
            $no = 'Baris '.($i + 1).($b['unit'] ? " ({$b['unit']})" : '').': ';
            $g = [];
            foreach (['hm_awal' => 'HM awal', 'hm_akhir' => 'HM akhir', 'total_hm' => 'Total HM', 'masuk' => 'Masuk', 'keluar' => 'Keluar'] as $k => $nama) {
                if ($b[$k] === false) {
                    $g[] = "$nama harus angka.";
                    $b[$k] = null;
                } elseif ($b[$k] !== null && $b[$k] < 0) {
                    $g[] = "$nama tidak boleh minus.";
                }
            }
            // Jenis dipilih admin. Unit dicocokkan PERSIS ke master jenis itu; jenis Solar masuk boleh nama sumber bebas
            // (asal bukan nama aset / unit jenis lain), dan didaftarkan sebagai sumber solar saat disimpan.
            $jenis = $b['jenis'];
            $kunci = fn ($x) => mb_strtolower(preg_replace('/\s+/', '', (string) $x));
            $lain = $b['unit'] === null ? null : collect($master['unit'])->first(fn ($j, $n) => $kunci($n) === $kunci($b['unit']) && $j !== $jenis);
            $namaLain = $b['unit'] === null ? null : collect(array_keys($master['unit']))->first(fn ($n) => $kunci($n) === $kunci($b['unit']) && $master['unit'][$n] !== $jenis);
            if (! isset(LembarBbm::JENIS_INPUT[$jenis])) {
                $g[] = 'Pilih Jenis dulu.';
            } elseif ($b['unit'] === null) {
                $g[] = 'Unit belum diisi.';
            } elseif ($kunci($b['unit']) === $kunci('Stock Opname')) {
                $g[] = 'Stock opname dicatat lewat tombol Stock Opname di menu Data BBM.';
            } elseif ($jenis === 'masuk') {
                if ($lain) {
                    $g[] = "\"{$b['unit']}\" adalah ".(LembarBbm::JENIS_INPUT[$lain] ?? $lain).", bukan sumber solar — tulis sumbernya, mis. \"Isi Solar dari {$namaLain}\".";
                } elseif ($b['unit'] !== trim($b['unit']) || preg_match('/\s{2,}/', $b['unit'])) {
                    $g[] = 'Unit tidak boleh diawali/diakhiri spasi atau berisi spasi ganda.';
                } elseif (mb_strlen($b['unit']) > 100) {
                    $g[] = 'Unit terlalu panjang (maks. 100 huruf).';
                }
            } elseif ($pesan = self::bedaMaster($b['unit'], array_keys(array_filter($master['unit'], fn ($j) => $j === $jenis)), 'Unit')) {
                $g[] = $lain ? "Unit \"{$b['unit']}\" berjenis ".(LembarBbm::JENIS_INPUT[$lain] ?? $lain).', bukan '.LembarBbm::JENIS_INPUT[$jenis].' — ganti Jenis-nya.' : $pesan;
            }
            if ($pesan = self::bedaMaster($b['proyek'], $master['tahap'], 'Proyek')) {
                $g[] = $pesan;
            }
            if ($pesan = self::bedaMaster($b['status'], $master['status'], 'Status')) {
                $g[] = $pesan;
            }
            foreach (['jam_mulai' => 'Jam mulai', 'jam_selesai' => 'Jam selesai'] as $k => $nama) {
                $asli = trim((string) ($r[$k] ?? ''));
                if ($asli !== '' && ! preg_match('/^\d{1,2}[:.]\d{2}$/', $asli)) {
                    $g[] = "$nama harus format jam, mis. 8:00 — sekarang \"$asli\".";
                }
            }
            if (($tj = LembarBbm::jamKerja($b['jam_mulai'], $b['istirahat'], $b['jam_selesai'])) !== null && $tj <= 0) {
                $g[] = 'Total jam kerja 0 — periksa jam Mulai & Selesai.';
            }
            if ($b['hm_awal'] !== null && $b['hm_akhir'] !== null) {
                if ($b['hm_akhir'] < $b['hm_awal']) {
                    $g[] = 'HM akhir lebih kecil dari HM awal.';
                } elseif ($b['hm_akhir'] - $b['hm_awal'] > 24) {
                    $g[] = 'Total HM lebih dari 24 jam — periksa HM awal/akhir.';
                }
            }
            if ($jenis === 'pakai') {
                if ($b['masuk']) {
                    $g[] = 'Solar masuk tidak dicatat di baris alat — buat baris sendiri dengan unit sumber solar (mis. "Isi Solar dari Luar").';
                }
                // Baris alat harus berisi transaksi: liter Keluar atau jam HM (> 0). Tanpa itu = tidak jelas tujuannya.
                $hm = $b['hm_awal'] !== null && $b['hm_akhir'] !== null ? $b['hm_akhir'] - $b['hm_awal'] : $b['total_hm'];
                if (! $b['keluar'] && ! ($hm > 0)) {
                    $g[] = 'Isi liter Keluar — atau HM awal & akhir bila alat bekerja tanpa isi solar. Baris tanpa transaksi tidak bisa disimpan.';
                } elseif (! $b['proyek']) {
                    $g[] = 'Pemakaian solar / jam kerja alat wajib diisi Proyek.';
                }
            } elseif ($jenis === 'masuk') {
                if (! $b['masuk']) {
                    $g[] = 'Isi jumlah liter di kolom Masuk.';
                }
                if ($b['keluar']) {
                    $g[] = 'Baris solar masuk tidak boleh berisi Keluar.';
                }
            } elseif (in_array($jenis, ['pindah', 'susut', 'operasional'], true)) {
                if (! $b['keluar']) {
                    $g[] = 'Isi jumlah liter di kolom Keluar.';
                }
                if ($b['masuk']) {
                    $g[] = 'Baris ini tidak boleh berisi Masuk.';
                }
            }
            if ($g) {
                $galat["baris.{$i}"] = $no.implode(' ', $g);
            }
            $hasil[(int) $i] = $b;
        }
        if (! $hasil && ! $galat) {
            $galat['baris'] = 'Isi minimal satu baris.';
        }

        return [array_values($hasil), $galat];
    }

    /** Unit solar masuk yang baru diketik admin didaftarkan sebagai sumber solar (supaya impor mengenalinya). */
    private static function daftarkanSumber(array $baris): void
    {
        foreach ($baris as $b) {
            if ($b['jenis'] === 'masuk' && ! BbmUnitPeta::where('kunci', BbmUnitPeta::kunci($b['unit']))->exists()) {
                BbmUnitPeta::create(['nama' => $b['unit'], 'kunci' => BbmUnitPeta::kunci($b['unit']), 'jenis' => 'masuk', 'sumber' => $b['unit'],
                    'catatan' => 'Dibuat dari Input BBM & HM', 'user_id' => auth()->id()]);
            }
        }
    }

    public function store(Request $request): RedirectResponse
    {
        [$baris, $galat] = $this->rapikan($request);
        if ($galat) {
            return back()->withInput()->withErrors($galat);
        }
        self::daftarkanSumber($baris);
        try {
            $hasil = (new TulisBbmSheet(GoogleSheets::wajib()))->tulis($baris);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        $ringkas = count($baris).' baris · '.$baris[0]['tanggal']->translatedFormat('j M Y').' · keluar '.self::liter(array_sum(array_column($baris, 'keluar')))
            .' · masuk '.self::liter(array_sum(array_column($baris, 'masuk')));
        KasRiwayat::create(['aksi' => 'bbm-tambah', 'lembar' => 'BBM', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => $ringkas, 'isi' => ['baris' => self::untukRiwayat($baris)], 'user_id' => $request->user()->id]);

        return redirect()->route('bbm.index', ['bulan' => $baris[0]['tanggal']->format('Y-m')])
            ->with('success', "Tersimpan di sheet Penggunaan BBM baris {$hasil['baris_awal']}–{$hasil['baris_akhir']}: {$ringkas}.");
    }

    public function edit(int $baris): View|RedirectResponse
    {
        $b = Bbm::where('baris', $baris)->first();
        if (! $b || $b->jenis === 'opname') {
            return redirect()->route('bbm.index')->with('error', $b ? 'Baris Stock Opname tidak bisa diedit; hapus lalu catat ulang.' : "Baris {$baris} tidak ditemukan — mungkin sheet berubah. Tunggu beberapa menit, lalu muat ulang.");
        }
        $a = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

        return $this->create()->with('edit', [
            'baris' => $b->baris, 'versi' => $this->versi($b), 'tanggal' => $b->tanggal?->format('d-m-Y'),
            'isi' => [['jenis' => $b->jenis, 'unit' => $b->unit, 'hm_awal' => $a($b->hm_awal), 'hm_akhir' => $a($b->hm_akhir), 'total_hm' => $b->hm_awal === null || $b->hm_akhir === null ? $a($b->total_hm) : '',
                'masuk' => $a($b->masuk), 'keluar' => $a($b->keluar), 'jam_mulai' => $b->jam_mulai, 'istirahat' => $b->istirahat, 'jam_selesai' => $b->jam_selesai,
                'status' => $b->status, 'keterangan' => $b->keterangan, 'rental' => $a($b->rental), 'proyek' => $b->proyek, 'keterangan_kerja' => $b->keterangan_kerja]],
        ]);
    }

    public function update(Request $request, int $baris): RedirectResponse
    {
        $b = Bbm::where('baris', $baris)->first();
        if (! $b || $request->input('versi') !== $this->versi($b)) {
            return back()->withInput()->with('error', 'Baris ini sudah berubah di sheet sejak form dibuka. Buka Edit lagi.');
        }
        [$isi, $galat] = $this->rapikan($request);
        if (! $galat && count($isi) !== 1) {
            $galat['baris'] = 'Edit hanya untuk satu baris.';
        }
        if ($galat) {
            return back()->withInput()->withErrors($galat);
        }
        self::daftarkanSumber($isi);

        $lama = "{$b->unit} {$b->tanggal?->translatedFormat('j M Y')} keluar ".self::liter($b->keluar).' masuk '.self::liter($b->masuk);
        try {
            (new TulisBbmSheet(GoogleSheets::wajib()))->ubah($b, $isi[0]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal mengubah di sheet: '.$e->getMessage());
        }
        KasRiwayat::create(['aksi' => 'bbm-ubah', 'lembar' => 'BBM', 'baris_awal' => $baris, 'baris_akhir' => $baris,
            'ringkasan' => mb_strimwidth("{$lama} → {$isi[0]['unit']} {$isi[0]['tanggal']->translatedFormat('j M Y')} keluar ".self::liter($isi[0]['keluar']).' masuk '.self::liter($isi[0]['masuk']), 0, 490, '…'),
            'isi' => ['lama' => $b->toArray(), 'baru' => self::untukRiwayat($isi)], 'user_id' => $request->user()->id]);

        return redirect()->route('bbm.index', ['bulan' => $isi[0]['tanggal']->format('Y-m')])->with('success', "Baris {$baris} diperbarui di sheet Penggunaan BBM.");
    }

    public function hapus(Request $request, int $baris): RedirectResponse
    {
        $b = Bbm::where('baris', $baris)->firstOrFail();
        $ringkas = "{$b->unit} · {$b->tanggal?->translatedFormat('j M Y')} · keluar ".self::liter($b->keluar).' · masuk '.self::liter($b->masuk).($b->proyek ? " · {$b->proyek}" : '');
        try {
            (new TulisBbmSheet(GoogleSheets::wajib()))->hapus($b);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal menghapus: '.$e->getMessage());
        }
        KasRiwayat::create(['aksi' => 'bbm-hapus', 'lembar' => 'BBM', 'baris_awal' => $baris, 'baris_akhir' => $baris, 'ringkasan' => mb_strimwidth($ringkas, 0, 490, '…'),
            'isi' => ['baris' => $b->toArray()], 'user_id' => $request->user()->id]);

        return redirect()->route('bbm.index', $request->boolean('perlu') ? ['perlu' => 1] : ['bulan' => $b->tanggal?->format('Y-m')])
            ->with('success', "Dihapus dari sheet Penggunaan BBM baris {$baris}: {$ringkas}.");
    }

    /** Stock opname: stok fisik → baris "Stock Opname" dengan selisih terhadap stok catatan (Masuk bila lebih, Keluar bila kurang). */
    public function opname(Request $request): RedirectResponse
    {
        $data = $request->validate(['tanggal' => ['required', 'string', 'max:20'], 'stok_fisik' => ['required', 'string', 'max:20']],
            ['tanggal.required' => 'Tanggal opname belum diisi.', 'stok_fisik.required' => 'Stok fisik belum diisi.']);
        $tanggal = RitasiController::tanggal($data['tanggal']);
        $fisik = str_replace(',', '.', str_replace('.', '', trim($data['stok_fisik'])));
        if (! $tanggal || $tanggal->gt(now()->endOfDay()) || ! is_numeric($fisik) || $fisik < 0) {
            return back()->withInput()->withErrors(['opname' => 'Tanggal atau stok fisik tidak valid.']);
        }
        $fisik = (float) $fisik;
        $stok = (float) (Bbm::whereNotNull('stok')->orderByDesc('baris')->value('stok') ?? 0);
        $selisih = round($fisik - $stok, 2);
        $b = ['tanggal' => $tanggal, 'jenis' => 'opname', 'unit' => 'Stock Opname', 'hm_awal' => null, 'hm_akhir' => null, 'total_hm' => null,
            'masuk' => $selisih > 0 ? $selisih : null, 'keluar' => $selisih < 0 ? -$selisih : null,
            'keterangan' => 'Stok fisik '.rtrim(rtrim(number_format($fisik, 2, '.', ''), '0'), '.').' liter',
            'keterangan_kerja' => $request->input('catatan') ? mb_substr(trim($request->input('catatan')), 0, 300) : null];
        try {
            $hasil = (new TulisBbmSheet(GoogleSheets::wajib()))->tulis([$b]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        $ringkas = "Stock opname {$tanggal->translatedFormat('j M Y')}: catatan ".self::liter($stok).', fisik '.self::liter($fisik).', selisih '.($selisih >= 0 ? '+' : '').self::liter($selisih);
        KasRiwayat::create(['aksi' => 'bbm-opname', 'lembar' => 'BBM', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => $ringkas, 'isi' => ['baris' => self::untukRiwayat([$b])], 'user_id' => $request->user()->id]);

        return redirect()->route('bbm.index', ['bulan' => $tanggal->format('Y-m')])->with('success', "{$ringkas} (sheet baris {$hasil['baris_awal']}).");
    }

    public static function liter(?float $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',').' L';
    }

    private static function untukRiwayat(array $baris): array
    {
        return array_map(fn ($b) => [...$b, 'tanggal' => $b['tanggal']->toDateString()], $baris);
    }

    private function versi(Bbm $b): string
    {
        return sha1(json_encode([$b->baris, $b->tanggal?->toDateString(), $b->unit, $b->masuk, $b->keluar, $b->hm_awal, $b->hm_akhir, $b->proyek]));
    }
}
