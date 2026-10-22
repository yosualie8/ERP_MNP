<?php

namespace App\Http\Controllers;

use App\Models\KasFoto;
use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Models\UjTemuan;
use App\Models\UjTransaksi;
use App\Support\BandingPengajuan;
use App\Support\DaftarBank;
use App\Support\FotoBon;
use App\Support\GoogleSheets;
use App\Models\AsetTruk;
use App\Models\UjPengajuan;
use App\Models\UjPengajuanDetail;
use App\Models\UjPengalihan;
use App\Support\KasSeabank;
use App\Support\KategoriUj;
use App\Support\Periode;
use App\Support\ModelKodeGl;
use App\Support\NomorMobil;
use App\Support\TautanUj;
use App\Support\TulisUjSheet;
use App\Support\ValidasiUj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Kas uang jalan dump truck (lembar "Kas Seabank"): daftar per bulan, input, edit, hapus — ditulis langsung ke sheet. */
class UjController extends Controller
{
    /** Panjang minimal konfirmasi admin untuk baris ber-FLAG (sama dengan form). */
    private const MIN_KONFIRMASI = 10;

    public function index(Request $request): View
    {
        $daftarBulan = UjTransaksi::whereNotNull('tanggal')->selectRaw("DATE_FORMAT(tanggal, '%Y-%m') as bulan")->distinct()->orderBy('bulan')->pluck('bulan');
        // Periode: tahun + bulan mulai s.d. bulan akhir (tautan lama ?bulan=2026-10 tetap dikenali).
        $periode = Periode::dari($request, $daftarBulan);
        $bulan = $daftarBulan->isNotEmpty() ? $periode->label() : null;
        $q = trim((string) $request->query('q'));

        // Penanda Pengalihan UJ (owner 20 Okt 2026): UJ yang dialihkan jadi saldo sopir / dikembalikan / dibatalkan.
        // ?alih=alih|kembali|batal|semua → hanya transaksi itu (semua periode; detail lain disembunyikan).
        $pengalihan = UjPengalihan::with(['dari', 'ke'])->whereIn('jenis', UjPengalihan::MENUTUP)->whereNotNull('id_uj')->get()->keyBy('id_uj');
        $alih = in_array($request->query('alih'), [...UjPengalihan::MENUTUP, 'semua'], true) ? $request->query('alih') : null;
        $idAlih = $alih ? $pengalihan->filter(fn ($p) => $alih === 'semua' || $p->jenis === $alih)->keys()->all() : [];

        $transaksi = collect();
        if ($bulan) {
            $transaksi = UjTransaksi::with('detail')
                ->when($alih, fn ($query) => $query->whereHas('detail', fn ($d) => $d->whereIn('id_uj', $idAlih)),
                    fn ($query) => $query->whereBetween('tanggal', [$periode->awal(), $periode->akhir()]))
                ->when($q !== '', function ($query) use ($q) {
                    $like = '%'.$q.'%';
                    // Cari juga nama pemilik rekening (Daftar Rekening), mis. "Jumagar" → transfer ke rekeningnya.
                    $kunciRek = \App\Models\Rekening::where('nama', 'like', $like)->pluck('kunci')->all();
                    $query->where(fn ($w) => $w->where('nama', 'like', $like)->orWhere('rekening', 'like', $like)
                        ->when($kunciRek, fn ($x) => $x->orWhereIn(DB::raw("TRIM(LEADING '0' FROM REGEXP_REPLACE(rekening, '[^0-9]', ''))"), $kunciRek))
                        ->orWhereHas('detail', fn ($d) => $d->where('keterangan', 'like', $like)->orWhere('nama', 'like', $like)
                            ->orWhere('id_uj', 'like', $like)->orWhere('no_mobil', 'like', $like)->orWhere('no_do', 'like', $like)
                            ->orWhere('kategori', 'like', $like)));
                })
                ->orderByDesc('baris')->get();
            if ($alih) {
                $transaksi->each(function ($t) use ($idAlih) {
                    $t->setRelation('detail', $t->detail->whereIn('id_uj', $idAlih)->values());
                });
            }
        }
        // Pemilik rekening dari Daftar Rekening (kolom Nama di sheet = sopir, bukan penerima transfer).
        $pemilikRek = \App\Support\DaftarRekening::pemilik($transaksi->pluck('rekening'));
        $jumlahPengalihan = $pengalihan->countBy('jenis')->all();
        $jumlahFoto = KasFoto::uj()->whereIn('no_id', $transaksi->pluck('no_uj')->filter())
            ->selectRaw('no_id, COUNT(*) as n')->groupBy('no_id')->pluck('n', 'no_id');
        $diimpor = Cache::get('uj-diimpor-pada');

        return view('uj.index', compact('daftarBulan', 'bulan', 'q', 'transaksi', 'jumlahFoto', 'diimpor', 'periode', 'pengalihan', 'alih', 'jumlahPengalihan', 'pemilikRek')
            + ['bolehInput' => $request->user()->bolehMenu('input-uj'), 'bolehPengalihan' => $request->user()->bolehMenu('pengalihan-uj')]);
    }

    public function create(): View
    {
        $sejak = now()->subDays(180);
        $hitung = fn (string $kolom, int $batas) => UjDetail::where('tanggal', '>=', $sejak)->whereNotNull($kolom)->where($kolom, '!=', '')
            ->select($kolom, DB::raw('COUNT(*) as n'))->groupBy($kolom)->orderByDesc('n')->limit($batas)->pluck($kolom);

        // Kategori: ejaan yang paling sering dipakai per kategori (mis. "Uang Makan" mengalahkan "Uang makan").
        $kategori = UjDetail::whereNotNull('kategori')->where('biaya_transfer', false)->select('kategori', DB::raw('COUNT(*) as n'))->groupBy('kategori')->get()
            ->groupBy(fn ($r) => strtolower(preg_replace('/\s+/', ' ', trim($r->kategori))))
            ->map(fn ($g) => ['nama' => trim(preg_replace('/\s+/', ' ', $g->sortByDesc('n')->first()->kategori)), 'n' => $g->sum('n')])
            ->sortByDesc('n')->pluck('nama')->values();

        // No mobil → jenis kendaraan, dari Data Aset (truk yang belum dijual); cadangan: histori Kas UJ.
        $mobil = AsetTruk::truk()->where('status', '!=', 'dijual')->orderBy('no_lambung')->pluck('jenis', 'no_lambung');
        if ($mobil->isEmpty()) {
            $mobil = UjDetail::whereNotNull('no_mobil')->where('no_mobil', '!=', '')->orderBy('baris')->get(['no_mobil', 'jenis_kendaraan'])
                ->mapWithKeys(fn ($d) => [NomorMobil::rapikan($d->no_mobil) => $d->jenis_kendaraan])->filter()->sortKeys();
        }
        $kategori = $kategori->map(fn ($k) => KategoriUj::baku($k))->unique()->values();

        $bank = UjTransaksi::whereNotNull('bank')->where('tanggal', '>=', $sejak)->select('bank', DB::raw('COUNT(*) as n'))->groupBy('bank')->orderByDesc('n')->limit(8)->pluck('bank')
            ->map(fn ($b) => DaftarBank::kode($b))->filter()->unique()->values();

        // Master sopir: nama sopir aktif (baku) disarankan dulu; ejaan lain sopir terdaftar tidak disarankan lagi.
        $sopir = \App\Support\DaftarSopir::untukForm();

        return view('uj.input', [
            'rekening' => $this->daftarRekening(),
            'nama' => collect($sopir['aktif'])->pluck('nama')->merge($hitung('nama', 300)->reject(fn ($n) => isset($sopir['peta'][\App\Support\DaftarSopir::kunci($n)])))
                ->unique(fn ($n) => \App\Support\DaftarSopir::kunci($n))->values(),
            'sopirForm' => $sopir,
            'kategori' => $kategori,
            'mobil' => $mobil,
            'jenis' => $hitung('jenis_kendaraan', 10),
            'bank' => $bank,
            'modelKategori' => $this->modelKategori(),
            // Pengajuan UJ: saldo per SOPIR (UJ yang dijadikan saldo, belum terpakai) dipotong otomatis; DO lama tidak boleh dipotong lagi
            'saldoSopir' => \App\Support\SaldoSopir::perSopir()->map(fn ($s) => (int) round($s['saldo']))->filter(fn ($v) => $v > 0)->all(),
            'doLamaSopir' => \App\Support\SaldoSopir::doLama(),
            // Input UJ: detail pengajuan yang menunggu realisasi — No DO + Kategori diketik/ditempel → kolom lain terisi & tertaut otomatis.
            'pjMenunggu' => $this->tawarkanTautan() ? $this->pengajuanMenunggu() : [],
            ...$this->dariPengajuan(),
            ...$this->dariBayarTanah(),
        ]);
    }

    /**
     * Input UJ → panel "Input bayar tanah" (tampilan sama dengan Input reimburse Kas UJ di Kas Harian): rekap bayar tanah
     * (RBT) 30 hari terakhir, terbaru dulu, beserta rinciannya; RBT yang sudah dibayar menyebut UJ-nya.
     */
    public function daftarBayarTanah(): JsonResponse
    {
        $rekap = \App\Models\BayarTanahRekap::with(['user', 'pengajuan'])->where('status', '!=', 'batal')->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('id')->get();
        $semuaDo = $rekap->flatMap(fn ($r) => array_column($r->isi, 'do'))->unique()->values();
        // DO → baris uang jalannya (DT, driver, keterangan) untuk rincian.
        $uj = UjDetail::where('biaya_transfer', false)->whereIn('no_do', $semuaDo->all() ?: ['-'])->orderBy('baris')->get(['no_do', 'no_mobil', 'nama', 'keterangan', 'kategori'])
            ->groupBy(fn ($d) => (string) $d->no_do)
            ->map(fn ($g) => $g->first(fn ($d) => \App\Support\ValidasiUj::jenisKategori($d->kategori) === 'uj') ?? $g->first());
        $belum = \App\Support\MonitorRitasi::belumBayarTanah()['do']->keyBy('do');

        return response()->json(['rekap' => $rekap->map(fn ($r) => [
            'id' => $r->id, 'kode' => $r->kode(), 'judul' => $r->created_at->translatedFormat('l, j M Y'), 'jam' => $r->created_at->format('H:i'),
            'jumlah' => $r->jumlah, 'total' => (int) $r->total, 'status' => $r->status, 'no_uj' => $r->no_uj, 'catatan' => $r->catatan, 'pengajuan' => $r->pengajuan?->kode(),
            // Sudah direalisasikan = pernah dibayar lewat Input UJ, atau semua DO-nya sudah punya Uang Tanah di Kas UJ.
            'terealisasi' => self::rekapTerealisasi($r, $belum),
            'oleh' => $r->user?->name,
            'detail' => collect($r->isi)->map(fn ($x) => [
                'do' => (string) $x['do'], 'harga' => (int) $x['harga'], 'mobil' => $uj[(string) $x['do']]->no_mobil ?? null,
                'driver' => $uj[(string) $x['do']]->nama ?? null, 'keterangan' => $uj[(string) $x['do']]->keterangan ?? null,
            ])->values(),
        ])->values()]);
    }

    /** Rekap bayar tanah sudah direalisasikan: dibayar lewat Input UJ, atau tidak ada lagi DO-nya yang belum punya Uang Tanah di Kas UJ. */
    private static function rekapTerealisasi(\App\Models\BayarTanahRekap $r, \Illuminate\Support\Collection $belum): bool
    {
        return $r->status === 'dibayar' || collect($r->isi)->every(fn ($x) => ! isset($belum[(string) $x['do']]));
    }

    /**
     * Menu Bayar Tanah → tombol "Bayar tanah": /uj/input?bayar_tanah=15790,15791 membuka form berisi satu detail Uang Tanah per DO
     * (lihat BayarTanah::untukForm). Admin memeriksa lalu menyimpan seperti input biasa.
     *
     * @return array{dariBayarTanah: ?array}
     */
    private function dariBayarTanah(): array
    {
        // Rekap Bayar Tanah (RBT) = catatan DO & harganya; bisa dipakai kapan saja (dobel bayar dicegah validasi Uang Tanah per DO).
        if ($id = request()->integer('rbt')) {
            $rekap = \App\Models\BayarTanahRekap::where('status', '!=', 'batal')->find($id);
            if (! $rekap) {
                return ['dariBayarTanah' => ['detail' => [], 'master' => [], 'galian' => [], 'catatan' => ['Rekap itu dibatalkan atau tidak ada.'], 'rekap' => null]];
            }
            // Ajukan Uang Jalan: rekap yang sudah direalisasikan tidak bisa dipakai lagi (owner 21 Okt 2026).
            if ($this instanceof PengajuanUjController && self::rekapTerealisasi($rekap, \App\Support\MonitorRitasi::belumBayarTanah()['do']->keyBy('do'))) {
                return ['dariBayarTanah' => ['detail' => [], 'master' => [], 'galian' => [],
                    'catatan' => [$rekap->kode().' sudah direalisasikan'.($rekap->no_uj ? ' (UJ-'.$rekap->no_uj.')' : '').' — tidak bisa dipakai lagi untuk pengajuan.'], 'rekap' => null]];
            }

            return ['dariBayarTanah' => [...\App\Support\BayarTanah::untukForm(array_column($rekap->isi, 'do'), $rekap->harga()), 'rekap' => ['id' => $rekap->id, 'kode' => $rekap->kode()]]];
        }
        // Format: DO atau DO:harga (harga tanah yang diisi admin di menu Bayar Tanah).
        $do = [];
        $harga = [];
        foreach (explode(',', (string) request()->query('bayar_tanah')) as $x) {
            [$no, $h] = array_pad(explode(':', trim($x), 2), 2, null);
            if (ctype_digit((string) $no)) {
                $do[] = $no;
                if ($h !== null && ctype_digit($h) && (int) $h > 0) {
                    $harga[$no] = (int) $h;
                }
            }
        }

        return ['dariBayarTanah' => $do ? \App\Support\BayarTanah::untukForm(array_values(array_unique($do)), $harga) : null];
    }

    /**
     * Menu Pengajuan UJ → tombol "Input UJ" per detail: /uj/input?pengajuan=12 (bisa beberapa, dipisah koma) membuka form
     * yang sudah berisi detail itu persis seperti pengajuannya dan sudah tertaut (setelah disimpan tercatat terealisasi).
     *
     * @return array{dariPengajuan: array, pesanPengajuan: ?string}
     */
    private function dariPengajuan(): array
    {
        $ids = collect(explode(',', (string) request()->query('pengajuan')))->map(fn ($v) => (int) $v)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return ['dariPengajuan' => [], 'pesanPengajuan' => null];
        }
        $detail = UjPengajuanDetail::with('pengajuan')->whereIn('id', $ids)->where('status', 'menunggu')->orderBy('uj_pengajuan_id')->orderBy('urut')->get();
        $baris = $detail->map(fn ($d) => [
            // yang direalisasikan (ditransfer) = nominal − bagian yang dibayar dari saldo sopir
            'pengajuan' => $d->id, 'kode' => $d->pengajuan->kode(), 'nama' => $d->nama, 'keterangan' => $d->keterangan, 'nominal' => (int) $d->nominal - (int) $d->saldo,
            'kategori' => $d->kategori, 'jenis_kendaraan' => $d->jenis_kendaraan, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do,
        ])->values()->all();
        $hilang = $ids->count() - $detail->count();

        return ['dariPengajuan' => $baris, 'pesanPengajuan' => $hilang
            ? ($detail->isEmpty() ? 'Detail pengajuan itu sudah direalisasikan atau dibatalkan — tidak ada yang dimasukkan ke form.' : "{$hilang} detail pengajuan sudah direalisasikan/dibatalkan dan tidak dimasukkan.")
            : null];
    }

    /**
     * Detail Pengajuan UJ ber-No DO yang masih menunggu realisasi, untuk isi otomatis Input UJ (kunci: No DO + jenis kategori,
     * sama dengan tawaran "Tautkan" di ValidasiUj). Nominal = yang ditransfer (nominal − bagian yang dibayar dari saldo sopir).
     */
    private function pengajuanMenunggu(): array
    {
        return UjPengajuanDetail::with('pengajuan')->where('status', 'menunggu')->whereNotNull('no_do')->where('no_do', '!=', '')
            ->orderBy('uj_pengajuan_id')->orderBy('urut')->get()
            ->map(fn ($d) => ['id' => $d->id, 'kode' => $d->pengajuan->kode(), 'tanggal' => $d->pengajuan->tanggal?->translatedFormat('j M'),
                'nama' => $d->nama, 'keterangan' => $d->keterangan, 'nominal' => (int) $d->nominal - (int) $d->saldo, 'penuh' => (int) $d->nominal, 'saldo' => (int) $d->saldo,
                'kategori' => KategoriUj::baku($d->kategori), 'jenis_kat' => ValidasiUj::jenisKategori($d->kategori),
                'jenis_kendaraan' => $d->jenis_kendaraan, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do])
            ->values()->all();
    }

    /**
     * Model tebak Kategori dari Keterangan (Naive Bayes per kata, sama dengan tebak Kode GL; dijalankan di browser lewat
     * public/js/tebak-kode-gl.js). Dilatih dari histori Kas Seabank; ejaan kategori dibakukan ke yang paling sering dipakai.
     * Uji 7 Okt 2026 (latih histori lama, uji 1.500 baris terbaru): tebakan pertama tepat 99,2%.
     */
    private function modelKategori(): array
    {
        $versi = UjDetail::count().'-'.UjDetail::max('baris').'-'.Cache::get('uj-diimpor-pada');

        return Cache::remember('model-kategori-uj:'.md5($versi), now()->addDay(), function () {
            $baris = UjDetail::where('biaya_transfer', false)->whereNotNull('kategori')->whereNotNull('keterangan')->get(['keterangan', 'kategori']);
            $kunci = fn ($k) => strtolower(preg_replace('/\s+/', ' ', trim($k)));
            $baku = $baris->groupBy(fn ($r) => $kunci($r->kategori))->map(fn ($g) => trim(preg_replace('/\s+/', ' ', $g->countBy('kategori')->sortDesc()->keys()->first())));

            return ModelKodeGl::latih($baris->map(fn ($r) => [$r->keterangan, null, $baku[$kunci($r->kategori)]])->all());
        });
    }

    /** Saran rekening (nama pemilik + bank + nomor) dari Daftar Rekening — sama untuk Input Kas & Input UJ. */
    private function daftarRekening(): array
    {
        return \App\Support\DaftarRekening::saran();
    }

    /**
     * @param  bool  $pengajuan  Pengajuan UJ: tanpa data master (penerima/rekening/nominal) — hanya tanggal + daftar transaksi,
     *                           nama driver tiap baris wajib, nominal = jumlah detail.
     */
    protected function bacaInput(Request $request, bool $pengajuan = false): array|RedirectResponse
    {
        $polos = fn ($v) => is_string($v) ? preg_replace('/\D/', '', $v) : $v;
        $request->merge([
            'nominal' => $polos($request->input('nominal')),
            'nominal_biaya' => $polos($request->input('nominal_biaya')),
            'detail' => array_map(fn ($d) => is_array($d) ? [...$d, 'nominal' => $polos($d['nominal'] ?? null), 'saldo' => $polos($d['saldo'] ?? null)] : $d, (array) $request->input('detail', [])),
        ]);
        if ($pengajuan) {
            $request->merge(['nama' => null, 'bank' => null, 'rekening' => null, 'biaya_transfer' => '0',
                'nominal' => (string) array_sum(array_map(fn ($d) => (int) ($d['nominal'] ?? 0), (array) $request->input('detail', [])))]);
        }
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'nama' => [$pengajuan ? 'nullable' : 'required', 'string', 'max:150'],
            'bank' => ['nullable', 'string', 'max:60', DaftarBank::aturan()],
            'rekening' => ['nullable', 'string', 'max:40'],
            'nominal' => ['required', 'integer', 'min:1'],
            'detail.*.nama' => [$pengajuan ? 'required' : 'nullable', 'string', 'max:150'],
            'biaya_transfer' => ['nullable', 'boolean'],
            'nominal_biaya' => ['exclude_unless:biaya_transfer,1', 'required', 'integer', 'min:1', 'max:100000'],
            'detail' => ['required', 'array', 'min:1'],
            'detail.*.nominal' => ['required', 'integer', 'min:1'],
            'detail.*.keterangan' => ['required', 'string', 'max:500'],
            'detail.*.kategori' => ['required', 'string', 'max:60'],
            'detail.*.jenis_kendaraan' => ['nullable', 'string', 'max:40'],
            'detail.*.no_mobil' => ['nullable', 'string', 'max:40'],
            'detail.*.no_do' => ['nullable', 'string', 'max:40'],
            'detail.*.konfirmasi' => ['nullable', 'string', 'max:1000'],
            'detail.*.pengajuan' => ['nullable', 'integer'],
            'detail.*.alasan_pengajuan' => ['nullable', 'string', 'max:1000'],
            'detail.*.duluan' => ['nullable', 'string', 'max:30'],
            'detail.*.saldo' => ['nullable', 'integer', 'min:0'],
            'detail.*.sopir_baru' => ['nullable', 'string', 'max:30'],
            'detail.*.sopir_pindah' => ['nullable', 'string', 'max:100'],
            'bayar_tanah_id' => ['nullable', 'integer'],
            'foto' => ['nullable', 'array', 'max:'.KasFotoController::MAKS_FOTO],
            'foto.*' => KasFotoController::ATURAN['foto.*'],
        ], [
            ...KasFotoController::PESAN,
            'detail.required' => 'Isi minimal satu transaksi detail.',
            'detail.*.keterangan.required' => 'Transaksi detail baris :position: Keterangan belum diisi.',
            'detail.*.kategori.required' => 'Transaksi detail baris :position: Kategori belum diisi.',
            'detail.*.nominal.required' => 'Transaksi detail baris :position: Nominal belum diisi.',
            'detail.*.nominal.min' => 'Transaksi detail baris :position: Nominal harus lebih dari 0.',
            'detail.*.nama.required' => 'Transaksi baris :position: Nama driver belum diisi.',
            'nominal_biaya.required' => 'Isi nominal biaya transfer, atau hilangkan centangnya.',
            'nominal_biaya.max' => 'Biaya transfer maksimal 100.000.',
        ]);
        $jumlah = array_sum(array_map(fn ($d) => (int) $d['nominal'], $data['detail']));
        if ($jumlah !== (int) $data['nominal']) {
            return back()->withInput()->withErrors(['nominal' => 'Ditolak: jumlah detail '.rp($jumlah).' tidak sama dengan nominal master '.rp((int) $data['nominal'])
                .' (selisih '.rp(abs((int) $data['nominal'] - $jumlah)).'). Tidak ditulis ke sheet.']);
        }
        $rapi = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;

        $hasil = [
            'tanggal' => Carbon::parse($data['tanggal']),
            'nama' => trim((string) ($data['nama'] ?? '')),
            'bank' => DaftarBank::kode($data['bank'] ?? null),
            'rekening' => $rapi($data['rekening'] ?? null),
            'nominal' => (int) $data['nominal'],
            'detail' => array_values(array_map(fn ($d) => [
                'nama' => $rapi($d['nama'] ?? null), 'keterangan' => trim($d['keterangan']), 'nominal' => (int) $d['nominal'],
                'kategori' => KategoriUj::baku($rapi($d['kategori'])), 'jenis_kendaraan' => NomorMobil::rapikanJenis($d['jenis_kendaraan'] ?? null),
                'no_mobil' => NomorMobil::rapikan($d['no_mobil'] ?? null), 'no_do' => $rapi($d['no_do'] ?? null),
                'konfirmasi' => $rapi($d['konfirmasi'] ?? null),
                // Detail pengajuan UJ yang direalisasikan oleh baris ini (ditautkan lewat tombol "Tautkan" di Input UJ).
                'pengajuan' => ! empty($d['pengajuan']) ? (int) $d['pengajuan'] : null,
                // Alasan bila realisasinya berbeda dari pengajuan (penyesuaian / dialihkan).
                'alasan_pengajuan' => $rapi($d['alasan_pengajuan'] ?? null),
                // Pengajuan UJ: ID UJ transaksi yang sudah terjadi duluan (jawaban "Ya" atas pertanyaan validasi D).
                'duluan' => $pengajuan ? $rapi($d['duluan'] ?? null) : null,
                // Pengajuan UJ: bagian nominal yang dibayar dari saldo sopir (UJ yang dialihkan) — tidak ditransfer.
                'saldo' => $pengajuan ? (int) ($d['saldo'] ?? 0) : 0,
                // Jawaban admin bila nama sopir belum terdaftar (App\Support\DaftarSopir): ganti / tambah / alias:{id} / bukan.
                'sopir_baru' => $rapi($d['sopir_baru'] ?? null),
                // Sopir terdaftar di truk lain / nonaktif: sementara / pindah[|id..] / aktif[|id..] (DaftarSopir::pindah).
                'sopir_pindah' => $rapi($d['sopir_pindah'] ?? null),
            ], $data['detail'])),
            'biaya_transfer' => $request->boolean('biaya_transfer'),
            'nominal_biaya' => (int) ($data['nominal_biaya'] ?? TulisUjSheet::BIAYA_TRANSFER),
            // Rekap Bayar Tanah (RBT) yang dibayar oleh transaksi ini (dari Input bayar tanah).
            'bayar_tanah_id' => $data['bayar_tanah_id'] ?? null, // Pengajuan UJ juga (rekap jadi "diajukan")
        ];
        // Master sopir (owner 20 Okt 2026): nama sopir baru di baris dump truck wajib dijawab admin; nama sopir ditulis dengan nama bakunya.
        if ($belum = \App\Support\DaftarSopir::belumDijawab($hasil['detail'])) {
            return back()->withInput()->withErrors(['sopir_baru' => implode(' ', $belum)]);
        }
        $hasil['sopir_asli'] = $hasil['detail']; // untuk DaftarSopir::terapkan() setelah tersimpan
        $hasil['detail'] = \App\Support\DaftarSopir::rapikanNama($hasil['detail']);
        if ($hasil['nama'] !== '' && ($s = \App\Support\DaftarSopir::cari($hasil['nama']))) {
            $hasil['nama'] = $s['nama'];
        }

        return $hasil;
    }

    /** Setelah transaksi tersimpan: catat jawaban sopir baru (karyawan baru / ganti sopir / nama lain). Teks tambahan untuk pesan sukses. */
    protected function terapkanSopir(array $input, Request $request): string
    {
        $r = \App\Support\DaftarSopir::terapkan($input['sopir_asli'] ?? [], $request->user()->id);

        return $r ? ' · '.implode('; ', $r) : '';
    }

    /**
     * Dipanggil form saat tombol Simpan ditekan (sebelum benar-benar dikirim): periksa setiap transaksi detail
     * terhadap Standar Aturan Validasi Kas Uang Jalan; baris ber-FLAG ditampilkan alasannya + kotak konfirmasi.
     */
    public function periksa(Request $request): JsonResponse
    {
        // Longgar: baris yang sudah lengkap (nominal, keterangan, kategori) langsung diperiksa walaupun baris lain belum,
        // supaya form bisa menandai hijau baris yang sudah valid satu per satu. Indeks baris dipertahankan.
        try {
            $tanggal = Carbon::parse((string) $request->input('tanggal'));
        } catch (\Throwable) {
            return response()->json(['galat' => ['Tanggal belum diisi dengan benar.']], 422);
        }
        $rapi = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $detail = [];
        foreach ((array) $request->input('detail', []) as $i => $d) {
            $nominal = (int) preg_replace('/\D/', '', (string) ($d['nominal'] ?? ''));
            if (! $nominal || ! $rapi($d['keterangan'] ?? null) || ! $rapi($d['kategori'] ?? null)) {
                continue;
            }
            $detail[(int) $i] = [
                'nama' => $rapi($d['nama'] ?? null), 'keterangan' => trim($d['keterangan']), 'nominal' => $nominal, 'kategori' => KategoriUj::baku($rapi($d['kategori'])),
                'jenis_kendaraan' => NomorMobil::rapikanJenis($d['jenis_kendaraan'] ?? null), 'no_mobil' => NomorMobil::rapikan($d['no_mobil'] ?? null),
                'no_do' => $rapi($d['no_do'] ?? null), 'konfirmasi' => null, 'duluan' => $this->tanyaDuluan() ? $rapi($d['duluan'] ?? null) : null,
            ];
        }
        $kecuali = $request->filled('no_uj') ? UjTransaksi::where('no_uj', $request->integer('no_uj'))->value('id') : null;
        $input = ['tanggal' => $tanggal, 'nama' => trim((string) $request->input('nama')), 'detail' => $detail];

        // Baris yang ditautkan ke pengajuan: bandingkan dengan pengajuan aslinya (berbeda = wajib alasan).
        $pengajuan = [];
        $rawDetail = (array) $request->input('detail', []);
        foreach ($detail as $i => $d) {
            if (! empty($rawDetail[$i]['pengajuan']) && ($p = UjPengajuanDetail::with('pengajuan')->find((int) $rawDetail[$i]['pengajuan']))) {
                $b = BandingPengajuan::banding($p, $d);
                if ($b['jenis'] !== BandingPengajuan::SESUAI) {
                    $pengajuan[$i] = [...$b, 'kode' => $p->pengajuan->kode()];
                }
            }
        }

        return response()->json(['temuan' => (object) ValidasiUj::periksa($input, $kecuali, $this->kecualiPengajuan($request), $this->tawarkanTautan() && ! $kecuali, $this->tanyaDuluan()),
            'pengajuan' => (object) $pengajuan]);
    }

    /** Form Pengajuan UJ menanyakan "sudah terjadi duluan?" untuk DO yang transaksinya sudah ada di Kas UJ; Input UJ tidak. */
    protected function tanyaDuluan(): bool
    {
        return false;
    }

    /** Input UJ menawarkan "Tautkan ke pengajuan" untuk baris yang cocok dengan pengajuan menunggu; form Pengajuan UJ tidak. */
    protected function tawarkanTautan(): bool
    {
        return true;
    }

    /**
     * Perbandingan setiap baris realisasi dengan pengajuannya. Baris yang berbeda wajib beralasan (min. 10 karakter).
     *
     * @return array<int, array{jenis: string, beda: array}>|RedirectResponse indeks detail => hasil banding
     */
    protected function bandingPengajuan(array $input): array|RedirectResponse
    {
        $hasil = [];
        $kurang = [];
        $p = UjPengajuanDetail::whereIn('id', collect($input['detail'])->pluck('pengajuan')->filter())->get()->keyBy('id');
        foreach ($input['detail'] as $i => $d) {
            if (! $d['pengajuan'] || ! isset($p[$d['pengajuan']])) {
                continue;
            }
            $hasil[$i] = BandingPengajuan::banding($p[$d['pengajuan']], $d);
            if ($hasil[$i]['jenis'] !== BandingPengajuan::SESUAI && mb_strlen((string) $d['alasan_pengajuan']) < 10) {
                $kurang[] = 'baris '.($i + 1).' ('.BandingPengajuan::ringkas($hasil[$i]['beda']).')';
            }
        }

        return $kurang ? back()->withInput()->with('error', 'Ditolak: realisasi berbeda dari pengajuannya tetapi alasannya belum diisi (min. 10 karakter): '.implode('; ', $kurang).'.') : $hasil;
    }

    /**
     * Detail pengajuan yang tidak dijadikan pembanding validasi: yang sedang direalisasikan oleh form ini, dan seluruh detail
     * pengajuan yang sedang diedit (supaya tidak dianggap dobel dengan dirinya sendiri).
     */
    protected function kecualiPengajuan(Request $request): array
    {
        $ids = collect((array) $request->input('detail', []))->pluck('pengajuan')->filter()->map(fn ($v) => (int) $v);
        if ($request->filled('pengajuan_id')) {
            $ids = $ids->merge(UjPengajuanDetail::where('uj_pengajuan_id', $request->integer('pengajuan_id'))->pluck('id'));
        }

        return $ids->unique()->values()->all();
    }

    /**
     * Pemeriksaan ulang di server (penentu akhir): baris ber-FLAG wajib punya konfirmasi admin.
     *
     * @return array|RedirectResponse temuan per indeks detail, atau penolakan bila ada yang belum dikonfirmasi
     */
    protected function wajibKonfirmasi(array $input, ?int $kecuali, array $kecualiPengajuan = []): array|RedirectResponse
    {
        $temuan = ValidasiUj::periksa($input, $kecuali, $kecualiPengajuan, $this->tawarkanTautan() && ! $kecuali, $this->tanyaDuluan());
        $pesan = [];
        foreach ($temuan as $i => $daftar) {
            // Pertanyaan "sudah terjadi duluan?" belum dijawab Ya = ditolak (konfirmasi teks tidak cukup).
            if ($duluan = collect($daftar)->firstWhere('kode', 'D')) {
                $pesan["duluan.{$i}"] = 'Ditolak — transaksi detail baris '.($i + 1).': DO '.$duluan['duluan']['no_do'].' sudah ada transaksinya di Kas UJ ('.$duluan['duluan']['id_uj']
                    .'). Bila transaksi itu memang terjadi duluan sebelum pengajuan, jawab "Ya" di pertanyaannya supaya langsung tertaut; bila bukan, data ini tidak bisa diajukan.';

                continue;
            }
            // FLAG "info" (mis. UJ terbuka lewat batas wajar) hanya peringatan — tidak wajib dikonfirmasi.
            if (mb_strlen((string) $input['detail'][$i]['konfirmasi']) >= self::MIN_KONFIRMASI || collect($daftar)->every(fn ($t) => ($t['prioritas'] ?? '') === 'info')) {
                continue;
            }
            $d = $input['detail'][$i];
            $pesan["konfirmasi.{$i}"] = 'Transaksi detail baris '.($i + 1).' ('.trim(($d['nama'] ?: ($i === 0 ? $input['nama'] : '')).' · '.rp($d['nominal']).' · '.$d['keterangan'], ' ·').'): '
                .count($daftar).' FLAG (Aturan '.implode(', ', array_unique(array_column($daftar, 'kode'))).') belum dikonfirmasi (min. '.self::MIN_KONFIRMASI.' karakter). Klik Simpan lagi untuk melihat alasannya.';
        }
        if ($pesan) {
            return back()->withInput()->withErrors($pesan);
        }

        return $temuan;
    }

    /** Catat temuan + konfirmasi admin per ID UJ (bahan Review Admin). */
    private function catatTemuan(array $temuan, array $input, array $ids, int $userId): void
    {
        UjTemuan::whereIn('id_uj', array_map(fn ($n) => 'UJ-'.$n, $ids))->delete();
        foreach ($temuan as $i => $daftar) {
            foreach ($daftar as $t) {
                UjTemuan::create(['id_uj' => 'UJ-'.$ids[$i], 'aturan' => $t['kode'], 'prioritas' => $t['prioritas'], 'pesan' => mb_strimwidth($t['pesan'], 0, 1000, '…'),
                    'konfirmasi' => $input['detail'][$i]['konfirmasi'], 'user_id' => $userId]);
            }
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $this->bacaInput($request);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        // Realisasi pengajuan: detail pengajuan yang dipilih harus masih menunggu (belum direalisasikan/dibatalkan).
        $idPengajuan = collect($input['detail'])->pluck('pengajuan')->filter()->values();
        if ($idPengajuan->count() !== $idPengajuan->unique()->count()
            || UjPengajuanDetail::whereIn('id', $idPengajuan)->where('status', 'menunggu')->count() !== $idPengajuan->count()) {
            return back()->withInput()->with('error', 'Sebagian detail pengajuan yang dipilih sudah direalisasikan atau dibatalkan (mungkin oleh admin lain). Muat ulang, lalu pilih lagi.');
        }
        $banding = $this->bandingPengajuan($input);
        if ($banding instanceof RedirectResponse) {
            return $banding;
        }
        // Rekap bayar tanah = catatan saja: tidak memblokir; setelah tersimpan diberi keterangan "dibayar" + nomor UJ-nya.
        $rekapTanah = $input['bayar_tanah_id'] ? \App\Models\BayarTanahRekap::where('status', '!=', 'batal')->find($input['bayar_tanah_id']) : null;
        $temuan = $this->wajibKonfirmasi($input, null, $idPengajuan->all());
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        try {
            $sheets = GoogleSheets::wajib();
            $hasil = (new TulisUjSheet($sheets))->tulis($input);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        // Detail ke-i = ID UJ ke-i (biaya transfer tanpa ID ada di bawahnya).
        $terealisasi = 0;
        $berbeda = 0;
        foreach ($input['detail'] as $i => $d) {
            if ($d['pengajuan'] && isset($hasil['ids'][$i])) {
                $b = $banding[$i] ?? ['jenis' => BandingPengajuan::SESUAI, 'beda' => []];
                $sesuai = $b['jenis'] === BandingPengajuan::SESUAI;
                // Pengajuan asli tidak diubah; isi realisasinya disimpan di samping (dialihkan = pengajuan selesai, DO tetap dihitung dibiayai).
                UjPengajuanDetail::where('id', $d['pengajuan'])->update([
                    'status' => $b['jenis'] === BandingPengajuan::DIALIHKAN ? 'dialihkan' : 'terealisasi', 'id_uj' => 'UJ-'.$hasil['ids'][$i],
                    'jenis_realisasi' => $b['jenis'], 'alasan' => $sesuai ? null : $d['alasan_pengajuan'],
                    'realisasi' => $sesuai ? null : json_encode([
                        ...collect($d)->only(['nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do'])->all(),
                        'id_uj' => 'UJ-'.$hasil['ids'][$i], 'beda' => $b['beda'],
                    ]),
                    'realisasi_pada' => now(), 'realisasi_oleh' => $request->user()->id,
                ]);
                $terealisasi++;
                $berbeda += $sesuai ? 0 : 1;
            }
        }
        $pjTerkait = UjPengajuanDetail::whereIn('id', $idPengajuan)->pluck('uj_pengajuan_id')->unique();
        UjPengajuan::whereIn('id', $pjTerkait)->get()->each->hitungStatus();
        $pesanImpor = $this->perbarui(fn () => KasSeabank::perbaruiBlok(null, 0, 0, $hasil['mentah'], $hasil['baris_awal']));
        $this->catatTemuan($temuan, $input, $hasil['ids'], $request->user()->id);
        $jumlahFoto = $this->simpanFoto($request, $hasil['no_uj'], $input['tanggal']);
        $rekapTanah?->update(['status' => 'dibayar', 'no_uj' => $hasil['no_uj'], 'dibayar_pada' => now(), 'dibayar_oleh' => $request->user()->id]);

        // Rekening baru masuk Daftar Rekening (nama penerima Input UJ dicatat "perlu dicek": sering diisi nama sopir).
        \App\Support\DaftarRekening::catat($input['rekening'] ?? null, $input['bank'] ?? null, $input['nama'] ?? null, $request->user()->id, false);
        KasRiwayat::create([
            'aksi' => 'uj-tambah', 'lembar' => 'Seabank', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth($this->ringkasanInput($input), 0, 490, '…'),
            'isi' => ['input' => [...$input, 'tanggal' => $input['tanggal']->toDateString()], 'id_uj' => $hasil['ids'], 'id_biaya' => $hasil['id_biaya']], 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('uj.index', ['bulan' => $input['tanggal']->format('Y-m')])->with($pesanImpor ? 'error' : 'success',
            'Tersimpan di Kas Seabank baris '.$hasil['baris_awal'].'–'.$hasil['baris_akhir'].' (UJ-'.reset($hasil['ids']).(count($hasil['ids']) > 1 ? ' s/d UJ-'.end($hasil['ids']) : '')
            .($hasil['id_biaya'] ? ', biaya transfer UJ-'.$hasil['id_biaya'] : '').'): '
            .$this->ringkasanInput($input).'.'.($jumlahFoto ? " {$jumlahFoto} foto bon terlampir." : '').($rekapTanah ? ' '.$rekapTanah->kode().' tercatat dibayar.' : '')
            .($terealisasi ? " {$terealisasi} detail pengajuan tercatat terealisasi".($berbeda ? " ({$berbeda} berbeda dari pengajuannya, tercatat beserta alasannya)" : '').'.' : '').$pesanImpor
            .$this->terapkanSopir($input, $request));
    }

    public function edit(int $noUj): View|RedirectResponse
    {
        $t = UjTransaksi::with('detail')->where('no_uj', $noUj)->first();
        if (! $t) {
            return redirect()->route('uj.index')->with('error', "Transaksi UJ-{$noUj} tidak ditemukan. Mungkin sudah dihapus atau sheet berubah — tunggu beberapa menit (data diperbarui otomatis tiap 5 menit) lalu muat ulang.");
        }
        if ($tolak = $this->tolakReimburse($t)) {
            return redirect()->route('uj.index', ['bulan' => $t->tanggal?->format('Y-m')])->with('error', $tolak);
        }
        $biaya = $t->detail->firstWhere('biaya_transfer', true);
        $edit = [
            'no_uj' => $noUj, 'versi' => $this->versi($t), 'baris' => $t->baris,
            'tanggal' => $t->tanggal?->toDateString(), 'nama' => $t->nama, 'bank' => $t->bank, 'rekening' => $t->rekening, 'nominal' => $t->nominal,
            'biaya_transfer' => $biaya ? '1' : '0', 'nominal_biaya' => $biaya?->nominal ?: TulisUjSheet::BIAYA_TRANSFER,
            'detail' => $t->detail->reject->biaya_transfer->map(fn (UjDetail $d) => $d->only(['nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do']))->values()->all(),
            'foto' => KasFoto::uj()->where('no_id', $noUj)->orderBy('id')->get()
                ->map(fn ($f) => ['id' => $f->id, 'penuh' => route('kas.foto', $f), 'kecil' => route('kas.foto', ['foto' => $f, 'ukuran' => 'kecil']), 'hapus' => route('kas.foto.destroy', $f)])->all(),
        ];

        return $this->create()->with('edit', $edit);
    }

    public function update(Request $request, int $noUj): RedirectResponse
    {
        $t = UjTransaksi::with('detail')->where('no_uj', $noUj)->first();
        if (! $t || $request->input('versi') !== $this->versi($t)) {
            return back()->withInput()->with('error', 'Transaksi ini sudah berubah di sheet sejak form dibuka (atau baru disinkron). Buka Edit lagi supaya perubahan tidak menimpa data terbaru.');
        }
        // Hanya transaksi yang belum direimburse (satu detail pun) yang boleh diubah — dicek dari data aplikasi dulu.
        if ($tolak = $this->tolakReimburse($t)) {
            return redirect()->route('uj.index', ['bulan' => $t->tanggal?->format('Y-m')])->with('error', $tolak);
        }
        $input = $this->bacaInput($request);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        $temuan = $this->wajibKonfirmasi($input, $t->id);
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        $ringkasLama = $this->ringkasan($t);
        try {
            $sheets = GoogleSheets::wajib();
            $hasil = (new TulisUjSheet($sheets))->ubah($t, $input);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal mengubah di sheet: '.$e->getMessage());
        }
        $pesanImpor = $this->perbarui(fn () => KasSeabank::perbaruiBlok($t, $hasil['sampai_lama'] + 1, $hasil['geser'], $hasil['mentah'], $hasil['baris_awal']));
        $this->catatTemuan($temuan, $input, $hasil['ids'], $request->user()->id);
        $jumlahFoto = $this->simpanFoto($request, $hasil['no_uj'], $input['tanggal']);
        if (! $jumlahFoto && KasFoto::uj()->where('no_id', $hasil['no_uj'])->exists()) {
            TautanUj::pastikanSegera($hasil['no_uj']); // kolom Bon dikosongkan saat ditulis ulang → chip ditulis lagi
        }

        // Rekening baru masuk Daftar Rekening (nama penerima Input UJ dicatat "perlu dicek": sering diisi nama sopir).
        \App\Support\DaftarRekening::catat($input['rekening'] ?? null, $input['bank'] ?? null, $input['nama'] ?? null, $request->user()->id, false);
        KasRiwayat::create([
            'aksi' => 'uj-ubah', 'lembar' => 'Seabank', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth("{$ringkasLama} → ".$this->ringkasanInput($input), 0, 490, '…'),
            'isi' => ['sebelum' => $hasil['sebelum'], 'input' => [...$input, 'tanggal' => $input['tanggal']->toDateString()], 'id_uj' => $hasil['ids']],
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('uj.index', ['bulan' => $input['tanggal']->format('Y-m')])->with($pesanImpor ? 'error' : 'success',
            'Perubahan tersimpan di Kas Seabank baris '.$hasil['baris_awal'].'–'.$hasil['baris_akhir'].': '.$this->ringkasanInput($input).'.'
            .($jumlahFoto ? " {$jumlahFoto} foto bon ditambahkan." : '').$pesanImpor.$this->terapkanSopir($input, $request));
    }

    /** Pesan penolakan Edit/Hapus bila ada detail transaksi ini yang sudah direimburse (sama seperti Kas Harian). */
    private function tolakReimburse(UjTransaksi $t): ?string
    {
        $sudah = $t->detail->filter(fn ($d) => $d->tanggal_reimburse !== null);
        if ($sudah->isEmpty()) {
            return null;
        }

        return 'Ditolak: UJ-'.$t->no_uj.' sudah direimburse ('.$sudah->count().' dari '.$t->detail->count().' detail, mis. '
            .$sudah->pluck('id_uj')->filter()->take(3)->implode(', ').'). Hanya transaksi yang belum direimburse yang bisa diubah atau dihapus.';
    }

    public function hapus(Request $request, int $noUj): RedirectResponse
    {
        $t = UjTransaksi::with('detail')->where('no_uj', $noUj)->firstOrFail();
        if ($tolak = $this->tolakReimburse($t)) {
            return back()->with('error', $tolak);
        }
        $ringkasan = $this->ringkasan($t);
        try {
            $sheets = GoogleSheets::wajib();
            $hasil = (new TulisUjSheet($sheets))->hapus($t);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal menghapus: '.$e->getMessage());
        }
        $jumlah = $hasil['baris_akhir'] - $hasil['baris_awal'] + 1;
        $pesanImpor = $this->perbarui(fn () => KasSeabank::perbaruiBlok($t, $hasil['baris_akhir'] + 1, -$jumlah));
        $foto = FotoBon::hapusMilik($noUj, 'uj');
        UjTemuan::whereIn('id_uj', $t->detail->pluck('id_uj')->filter())->delete();
        // Detail pengajuan yang direalisasikan oleh transaksi ini kembali "menunggu" (bisa direalisasikan ulang).
        $kembali = UjPengajuanDetail::whereIn('id_uj', $t->detail->pluck('id_uj')->filter())->get();
        if ($kembali->isNotEmpty()) {
            UjPengajuanDetail::whereIn('id', $kembali->pluck('id'))->update(['status' => 'menunggu', 'id_uj' => null, 'jenis_realisasi' => null, 'alasan' => null,
                'realisasi' => null, 'realisasi_pada' => null, 'realisasi_oleh' => null]);
            UjPengajuan::whereIn('id', $kembali->pluck('uj_pengajuan_id')->unique())->get()->each->hitungStatus();
        }
        KasRiwayat::create([
            'aksi' => 'uj-hapus', 'lembar' => 'Seabank', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => $ringkasan, 'isi' => ['sebelum' => $hasil['sebelum']], 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('uj.index', ['bulan' => $t->tanggal?->format('Y-m'), 'q' => $request->input('q') ?: null])->with($pesanImpor ? 'error' : 'success',
            "Dihapus dari Kas Seabank baris {$hasil['baris_awal']}–{$hasil['baris_akhir']}: {$ringkasan}".($foto ? ", {$foto} foto bon ikut dihapus" : '').'.'.$pesanImpor);
    }

    private function simpanFoto(Request $request, int $noUj, Carbon $tanggal): int
    {
        $n = 0;
        foreach ($request->file('foto', []) as $file) {
            FotoBon::simpan($file, $noUj, TautanUj::lembarFoto($tanggal), $request->user()->id, 'uj');
            $n++;
        }
        // Sesudah halaman terkirim: folder Drive & chip Bon, lalu foto diunggah ke sana.
        if ($n) {
            TautanUj::pastikanSegera($noUj);
            FotoBon::unggahSegera();
        }

        return $n;
    }

    /** Perbarui data aplikasi dari blok yang baru ditulis; bila gagal, sheet tetap benar dan pesan menyarankan sinkron. */
    private function perbarui(callable $fn): string
    {
        try {
            $fn();

            return '';
        } catch (\Throwable $e) {
            report($e);

            return ' Sheet sudah diubah, tetapi data aplikasi belum diperbarui ('.$e->getMessage().'); tunggu beberapa menit (data diperbarui otomatis tiap 5 menit) lalu muat ulang.';
        }
    }

    private function versi(UjTransaksi $t): string
    {
        return sha1(json_encode([$t->baris, $t->baris_akhir, $t->detail->map(fn ($d) => [$d->baris, $d->id_uj, $d->nominal, $d->keterangan])->all()]));
    }

    private function ringkasan(UjTransaksi $t): string
    {
        return 'UJ '.rp((int) $t->nominal).' '.$t->tanggal?->translatedFormat('j M Y').' '.$t->nama.' ('.$t->detail->reject->biaya_transfer->count().' detail)';
    }

    private function ringkasanInput(array $input): string
    {
        return 'UJ '.rp($input['nominal']).' '.$input['tanggal']->translatedFormat('j M Y').' '.$input['nama'].' ('.count($input['detail']).' detail)';
    }
}
