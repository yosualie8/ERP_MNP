<?php

namespace App\Http\Controllers;

use App\Models\KasFoto;
use App\Models\KasRiwayat;
use App\Models\UjDetail;
use App\Models\UjTemuan;
use App\Models\UjTransaksi;
use App\Support\DaftarBank;
use App\Support\FotoBon;
use App\Support\GoogleSheets;
use App\Support\KasSeabank;
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
        $bulan = $daftarBulan->contains($request->query('bulan')) ? $request->query('bulan') : $daftarBulan->last();
        $q = trim((string) $request->query('q'));

        $transaksi = collect();
        if ($bulan) {
            $awal = Carbon::parse($bulan.'-01');
            $transaksi = UjTransaksi::with('detail')
                ->whereBetween('tanggal', [$awal, $awal->copy()->endOfMonth()])
                ->when($q !== '', function ($query) use ($q) {
                    $like = '%'.$q.'%';
                    $query->where(fn ($w) => $w->where('nama', 'like', $like)->orWhere('rekening', 'like', $like)
                        ->orWhereHas('detail', fn ($d) => $d->where('keterangan', 'like', $like)->orWhere('nama', 'like', $like)
                            ->orWhere('id_uj', 'like', $like)->orWhere('no_mobil', 'like', $like)->orWhere('no_do', 'like', $like)
                            ->orWhere('kategori', 'like', $like)));
                })
                ->orderByDesc('baris')->get();
        }
        $jumlahFoto = KasFoto::uj()->whereIn('no_id', $transaksi->pluck('no_uj')->filter())
            ->selectRaw('no_id, COUNT(*) as n')->groupBy('no_id')->pluck('n', 'no_id');
        $diimpor = Cache::get('uj-diimpor-pada');

        return view('uj.index', compact('daftarBulan', 'bulan', 'q', 'transaksi', 'jumlahFoto', 'diimpor'));
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

        // No mobil → jenis kendaraan terakhir yang dipakai (DT 062 → Faw).
        $mobil = UjDetail::whereNotNull('no_mobil')->where('no_mobil', '!=', '')->orderBy('baris')->get(['no_mobil', 'jenis_kendaraan'])
            ->mapWithKeys(fn ($d) => [NomorMobil::rapikan($d->no_mobil) => $d->jenis_kendaraan])
            ->filter()->sortKeys();

        $bank = UjTransaksi::whereNotNull('bank')->where('tanggal', '>=', $sejak)->select('bank', DB::raw('COUNT(*) as n'))->groupBy('bank')->orderByDesc('n')->limit(8)->pluck('bank')
            ->map(fn ($b) => DaftarBank::kode($b))->filter()->unique()->values();

        return view('uj.input', [
            'rekening' => $this->daftarRekening(),
            'nama' => $hitung('nama', 300),
            'kategori' => $kategori,
            'mobil' => $mobil,
            'jenis' => $hitung('jenis_kendaraan', 10),
            'bank' => $bank,
            'modelKategori' => $this->modelKategori(),
        ]);
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

    /** Rekening tujuan yang pernah dipakai (nama + bank + nomor), untuk saran dua arah seperti Input Kas. */
    private function daftarRekening(): array
    {
        return UjTransaksi::whereNotNull('rekening')->where('rekening', '!=', '')
            ->select('nama', 'rekening', 'bank', DB::raw('COUNT(*) as n'), DB::raw('MAX(tanggal) as terakhir'))
            ->groupBy('nama', 'rekening', 'bank')->get()
            ->map(fn ($r) => ['nama' => trim((string) $r->nama), 'bank' => $r->bank, 'no_rek' => preg_replace('/\D/', '', $r->rekening),
                'kunci' => ltrim(preg_replace('/\D/', '', $r->rekening), '0'), 'dipakai' => (int) $r->n, 'urut' => $r->terakhir,
                'terakhir' => $r->terakhir ? Carbon::parse($r->terakhir)->translatedFormat('j M Y') : '-'])
            ->filter(fn ($r) => $r['kunci'] !== '')
            ->groupBy(fn ($r) => mb_strtolower($r['nama']).'|'.mb_strtolower((string) $r['bank']).'|'.$r['kunci'])
            ->map(fn ($g) => [...$g->sortByDesc('dipakai')->first(), 'dipakai' => $g->sum('dipakai'), 'urut' => $g->max('urut')])
            ->sortByDesc('urut')->map(fn ($r) => collect($r)->except('urut')->all())->values()->all();
    }

    private function bacaInput(Request $request): array|RedirectResponse
    {
        $polos = fn ($v) => is_string($v) ? preg_replace('/\D/', '', $v) : $v;
        $request->merge([
            'nominal' => $polos($request->input('nominal')),
            'nominal_biaya' => $polos($request->input('nominal_biaya')),
            'detail' => array_map(fn ($d) => is_array($d) ? [...$d, 'nominal' => $polos($d['nominal'] ?? null)] : $d, (array) $request->input('detail', [])),
        ]);
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'nama' => ['required', 'string', 'max:150'],
            'bank' => ['nullable', 'string', 'max:60', DaftarBank::aturan()],
            'rekening' => ['nullable', 'string', 'max:40'],
            'nominal' => ['required', 'integer', 'min:1'],
            'biaya_transfer' => ['nullable', 'boolean'],
            'nominal_biaya' => ['exclude_unless:biaya_transfer,1', 'required', 'integer', 'min:1', 'max:100000'],
            'detail' => ['required', 'array', 'min:1'],
            'detail.*.nominal' => ['required', 'integer', 'min:1'],
            'detail.*.nama' => ['nullable', 'string', 'max:150'],
            'detail.*.keterangan' => ['required', 'string', 'max:500'],
            'detail.*.kategori' => ['required', 'string', 'max:60'],
            'detail.*.jenis_kendaraan' => ['nullable', 'string', 'max:40'],
            'detail.*.no_mobil' => ['nullable', 'string', 'max:40'],
            'detail.*.no_do' => ['nullable', 'string', 'max:40'],
            'detail.*.konfirmasi' => ['nullable', 'string', 'max:1000'],
            'foto' => ['nullable', 'array', 'max:'.KasFotoController::MAKS_FOTO],
            'foto.*' => KasFotoController::ATURAN['foto.*'],
        ], [
            ...KasFotoController::PESAN,
            'detail.required' => 'Isi minimal satu transaksi detail.',
            'detail.*.keterangan.required' => 'Transaksi detail baris :position: Keterangan belum diisi.',
            'detail.*.kategori.required' => 'Transaksi detail baris :position: Kategori belum diisi.',
            'detail.*.nominal.required' => 'Transaksi detail baris :position: Nominal belum diisi.',
            'detail.*.nominal.min' => 'Transaksi detail baris :position: Nominal harus lebih dari 0.',
            'nominal_biaya.required' => 'Isi nominal biaya transfer, atau hilangkan centangnya.',
            'nominal_biaya.max' => 'Biaya transfer maksimal 100.000.',
        ]);
        $jumlah = array_sum(array_map(fn ($d) => (int) $d['nominal'], $data['detail']));
        if ($jumlah !== (int) $data['nominal']) {
            return back()->withInput()->withErrors(['nominal' => 'Ditolak: jumlah detail '.rp($jumlah).' tidak sama dengan nominal master '.rp((int) $data['nominal'])
                .' (selisih '.rp(abs((int) $data['nominal'] - $jumlah)).'). Tidak ditulis ke sheet.']);
        }
        $rapi = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;

        return [
            'tanggal' => Carbon::parse($data['tanggal']),
            'nama' => trim($data['nama']),
            'bank' => DaftarBank::kode($data['bank'] ?? null),
            'rekening' => $rapi($data['rekening'] ?? null),
            'nominal' => (int) $data['nominal'],
            'detail' => array_values(array_map(fn ($d) => [
                'nama' => $rapi($d['nama'] ?? null), 'keterangan' => trim($d['keterangan']), 'nominal' => (int) $d['nominal'],
                'kategori' => $rapi($d['kategori']), 'jenis_kendaraan' => NomorMobil::rapikanJenis($d['jenis_kendaraan'] ?? null),
                'no_mobil' => NomorMobil::rapikan($d['no_mobil'] ?? null), 'no_do' => $rapi($d['no_do'] ?? null),
                'konfirmasi' => $rapi($d['konfirmasi'] ?? null),
            ], $data['detail'])),
            'biaya_transfer' => $request->boolean('biaya_transfer'),
            'nominal_biaya' => (int) ($data['nominal_biaya'] ?? TulisUjSheet::BIAYA_TRANSFER),
        ];
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
                'nama' => $rapi($d['nama'] ?? null), 'keterangan' => trim($d['keterangan']), 'nominal' => $nominal, 'kategori' => $rapi($d['kategori']),
                'jenis_kendaraan' => NomorMobil::rapikanJenis($d['jenis_kendaraan'] ?? null), 'no_mobil' => NomorMobil::rapikan($d['no_mobil'] ?? null),
                'no_do' => $rapi($d['no_do'] ?? null), 'konfirmasi' => null,
            ];
        }
        $kecuali = $request->filled('no_uj') ? UjTransaksi::where('no_uj', $request->integer('no_uj'))->value('id') : null;
        $input = ['tanggal' => $tanggal, 'nama' => trim((string) $request->input('nama')), 'detail' => $detail];

        return response()->json(['temuan' => (object) ValidasiUj::periksa($input, $kecuali)]);
    }

    /**
     * Pemeriksaan ulang di server (penentu akhir): baris ber-FLAG wajib punya konfirmasi admin.
     *
     * @return array|RedirectResponse temuan per indeks detail, atau penolakan bila ada yang belum dikonfirmasi
     */
    private function wajibKonfirmasi(array $input, ?int $kecuali): array|RedirectResponse
    {
        $temuan = ValidasiUj::periksa($input, $kecuali);
        $pesan = [];
        foreach ($temuan as $i => $daftar) {
            if (mb_strlen((string) $input['detail'][$i]['konfirmasi']) >= self::MIN_KONFIRMASI) {
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
        $temuan = $this->wajibKonfirmasi($input, null);
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
        $pesanImpor = $this->perbarui(fn () => KasSeabank::perbaruiBlok(null, 0, 0, $hasil['mentah'], $hasil['baris_awal']));
        $this->catatTemuan($temuan, $input, $hasil['ids'], $request->user()->id);
        $jumlahFoto = $this->simpanFoto($request, $hasil['no_uj'], $input['tanggal']);

        KasRiwayat::create([
            'aksi' => 'uj-tambah', 'lembar' => 'Seabank', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth($this->ringkasanInput($input), 0, 490, '…'),
            'isi' => ['input' => [...$input, 'tanggal' => $input['tanggal']->toDateString()], 'id_uj' => $hasil['ids']], 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('uj.index', ['bulan' => $input['tanggal']->format('Y-m')])->with($pesanImpor ? 'error' : 'success',
            'Tersimpan di Kas Seabank baris '.$hasil['baris_awal'].'–'.$hasil['baris_akhir'].' (UJ-'.reset($hasil['ids']).(count($hasil['ids']) > 1 ? ' s/d UJ-'.end($hasil['ids']) : '').'): '
            .$this->ringkasanInput($input).'.'.($jumlahFoto ? " {$jumlahFoto} foto bon terlampir." : '').$pesanImpor);
    }

    public function edit(int $noUj): View|RedirectResponse
    {
        $t = UjTransaksi::with('detail')->where('no_uj', $noUj)->first();
        if (! $t) {
            return redirect()->route('uj.index')->with('error', "Transaksi UJ-{$noUj} tidak ditemukan. Mungkin sudah dihapus atau sheet berubah — klik \"Sinkron dari sheet\".");
        }
        if ($t->sudahReimburse()) {
            return redirect()->route('uj.index', ['bulan' => $t->tanggal?->format('Y-m')])->with('error', "UJ-{$noUj} sudah direimburse, tidak bisa diubah lewat aplikasi.");
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

        KasRiwayat::create([
            'aksi' => 'uj-ubah', 'lembar' => 'Seabank', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth("{$ringkasLama} → ".$this->ringkasanInput($input), 0, 490, '…'),
            'isi' => ['sebelum' => $hasil['sebelum'], 'input' => [...$input, 'tanggal' => $input['tanggal']->toDateString()], 'id_uj' => $hasil['ids']],
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('uj.index', ['bulan' => $input['tanggal']->format('Y-m')])->with($pesanImpor ? 'error' : 'success',
            'Perubahan tersimpan di Kas Seabank baris '.$hasil['baris_awal'].'–'.$hasil['baris_akhir'].': '.$this->ringkasanInput($input).'.'
            .($jumlahFoto ? " {$jumlahFoto} foto bon ditambahkan." : '').$pesanImpor);
    }

    public function hapus(Request $request, int $noUj): RedirectResponse
    {
        $t = UjTransaksi::with('detail')->where('no_uj', $noUj)->firstOrFail();
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
        KasRiwayat::create([
            'aksi' => 'uj-hapus', 'lembar' => 'Seabank', 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => $ringkasan, 'isi' => ['sebelum' => $hasil['sebelum']], 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('uj.index', ['bulan' => $t->tanggal?->format('Y-m'), 'q' => $request->input('q') ?: null])->with($pesanImpor ? 'error' : 'success',
            "Dihapus dari Kas Seabank baris {$hasil['baris_awal']}–{$hasil['baris_akhir']}: {$ringkasan}".($foto ? ", {$foto} foto bon ikut dihapus" : '').'.'.$pesanImpor);
    }

    public function sinkron(): RedirectResponse
    {
        try {
            $h = KasSeabank::impor(GoogleSheets::wajib());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Sinkron gagal: '.$e->getMessage());
        }

        return back()->with('success', "Kas Seabank disinkron dari sheet: {$h['transaksi']} transaksi, {$h['detail']} detail.");
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

            return ' Sheet sudah diubah, tetapi data aplikasi belum diperbarui ('.$e->getMessage().'); klik "Sinkron dari sheet".';
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
