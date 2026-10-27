<?php

namespace App\Http\Controllers;

use App\Models\AkunGl;
use App\Models\AsetTruk;
use App\Support\NomorMobil;
use App\Models\KasBon;
use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\KodeGl;
use App\Models\KasBulan;
use App\Models\KasFoto;
use App\Models\KasReimburseUj;
use App\Support\PotonganReimburseUj;
use App\Support\ReimburseUjKeKas;
use App\Support\StatusReimburse;
use Illuminate\Http\JsonResponse;
use App\Support\CerminReimburse;
use App\Support\DaftarBank;
use App\Support\FotoBon;
use App\Support\ModelKodeGl;
use Illuminate\Support\Facades\Cache;
use App\Support\GoogleSheets;
use App\Support\HapusKasSheet;
use App\Support\ImporKas;
use App\Support\TautanBon;
use App\Support\TulisKasSheet;
use App\Support\UraiKodeGl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Input transfer + bon langsung dari aplikasi; ditulis ke lembar bulanan di sheet lalu lembar itu diimpor ulang. */
class KasInputController extends Controller
{
    public function create(): View
    {
        $sejak = now()->subDays(120);

        // Kode GL ditulis rapi (akun + cost center + tahap), urut dari yang paling sering dipakai.
        $kodeGl = KodeGl::with(['akun', 'costCenter'])->withCount(['bon' => fn ($q) => $q->where('tanggal', '>=', $sejak)])->get()
            ->filter->akun
            ->groupBy(fn ($k) => trim(implode(' ', array_filter([$k->akun->nama, $k->costCenter?->kode, $k->ref]))))
            ->map->sum('bon_count')->sortDesc()->keys()->values();

        $pic = KasBon::where('tanggal', '>=', $sejak)->whereNotNull('pic')
            ->select('pic', DB::raw('COUNT(*) as n'))->groupBy('pic')->orderByDesc('n')->pluck('pic');

        $rekening = $this->daftarRekening();

        $bank = KasTransfer::whereNotNull('bank_tujuan')->where('tanggal', '>=', $sejak)
            ->select('bank_tujuan', DB::raw('COUNT(*) as n'))->groupBy('bank_tujuan')->orderByDesc('n')->limit(8)->pluck('bank_tujuan')
            ->map(fn ($b) => DaftarBank::kode($b))->filter()->unique()->values();

        // Model tebak Kode GL dari semua transaksi detail; dibuat ulang tiap kali ada impor baru.
        $versi = (string) KasBulan::max('diimpor_pada');
        $modelKode = Cache::remember('model-kode-gl:'.md5($versi), now()->addDay(), fn () => ModelKodeGl::latih(ModelKodeGl::dataLatih()));

        // No Mobil per detail dipilih dari Data Aset (truk yang belum dijual).
        $aset = AsetTruk::truk()->where('status', '!=', 'dijual')->orderBy('no_lambung')->get(['no_lambung', 'plat', 'jenis']);

        // Kata kunci Kode GL tiap tahap Daftar Proyek: form memperingatkan Kode GL yang cocok ke lebih dari satu tahap (owner 22 Okt 2026).
        $namaTahap = \App\Models\ProyekTahap::pluck('nama', 'id');
        $kataGl = collect(\App\Support\DaftarProyek::kataGl())->map(fn ($x) => [$x[0], $namaTahap[$x[1]] ?? '#'.$x[1]])->values();

        return view('kas.input', compact('kodeGl', 'pic', 'rekening', 'bank', 'modelKode', 'aset', 'kataGl'));
    }

    /** Saran rekening (nama pemilik + bank + nomor) dari Daftar Rekening — sama untuk Input Kas & Input UJ. */
    private function daftarRekening(): array
    {
        return \App\Support\DaftarRekening::saran();
    }

    /**
     * Validasi form Input/Edit Kas → data siap ditulis ke sheet, atau redirect kembali bila ditolak
     * (mis. jumlah transaksi detail tidak sama dengan nominal transfer).
     */
    private function bacaInput(Request $request): array|RedirectResponse
    {
        // Nominal tampil bertitik ribuan di form ("50.000"); simpan sebagai angka bulat.
        $polos = fn ($v) => is_string($v) ? preg_replace('/\D/', '', $v) : $v;
        $request->merge([
            'nominal_masuk' => $polos($request->input('nominal_masuk')),
            'nominal_transfer' => $polos($request->input('nominal_transfer')),
            'nominal_biaya' => $polos($request->input('nominal_biaya')),
            // Baris potongan reimburse Kas UJ (PotonganReimburseUj) bernominal minus: tanda minusnya dipertahankan.
            'bon' => array_map(fn ($b) => is_array($b) ? [...$b, 'nominal' => PotonganReimburseUj::dariKunci($b['uj'] ?? null) && preg_match('/^\s*-/', (string) ($b['nominal'] ?? ''))
                ? '-'.$polos($b['nominal']) : $polos($b['nominal'] ?? null)] : $b, (array) $request->input('bon', [])),
        ]);

        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'arah' => ['required', 'in:keluar,masuk'],
            'nama_tujuan' => ['required', 'string', 'max:200'],
            'no_rek' => ['nullable', 'string', 'max:40'],
            'bank' => ['nullable', 'string', 'max:60', DaftarBank::aturan()],
            'keterangan' => ['nullable', 'string', 'max:300'],
            'nominal_masuk' => ['required_if:arah,masuk', 'nullable', 'integer', 'min:1'],
            'kode_gl_masuk' => ['nullable', 'string', 'max:120'],
            'nominal_transfer' => ['required_if:arah,keluar', 'nullable', 'integer', 'min:1'],
            'biaya_transfer' => ['nullable', 'boolean'],
            'nominal_biaya' => ['exclude_unless:biaya_transfer,1', 'required', 'integer', 'min:1', 'max:'.TulisKasSheet::BIAYA_TRANSFER_MAKS],
            'bon' => ['required_if:arah,keluar', 'array'],
            'bon.*.nominal' => ['required', 'integer', function ($atribut, $nilai, $gagal) use ($request) {
                // Minus hanya untuk baris potongan reimburse Kas UJ, dan harus persis sebesar potongannya.
                $p = PotonganReimburseUj::dariKunci($request->input(str_replace('.nominal', '.uj', $atribut)));
                if ((int) $nilai < 1 && ! ($p && (int) $nilai === -$p['nominal'])) {
                    $gagal('Nominal setiap transaksi detail minimal 1.');
                }
            }],
            'bon.*.pic' => ['nullable', 'string', 'max:60'],
            'bon.*.keterangan' => ['required', 'string', 'max:300'],
            'bon.*.kode_gl' => ['required', 'string', 'max:120'],
            'bon.*.uj' => ['nullable', 'string', 'max:40'],
            'bon.*.no_mobil' => ['nullable', 'string', 'max:20'],
            'reimburse_uj' => ['nullable', 'string', 'max:20', 'regex:/^(r\d+|\d{4}-\d{2}-\d{2})$/'],
            // Transaksi yang baru diinput/diedit tetapi kenyataannya sudah direimburse.
            'sudah_reimburse' => ['nullable', 'boolean'],
            'tanggal_reimburse' => ['exclude_unless:sudah_reimburse,1', 'required', 'date', 'before_or_equal:today'],
            'foto' => ['nullable', 'array', 'max:'.KasFotoController::MAKS_FOTO],
            'foto.*' => KasFotoController::ATURAN['foto.*'],
        ], [
            ...KasFotoController::PESAN,
            'bon.required_if' => 'Isi minimal satu transaksi detail untuk transfer keluar.',
            'bon.*.keterangan.required' => 'Keterangan setiap transaksi detail wajib diisi.',
            'bon.*.kode_gl.required' => 'Kode GL setiap transaksi detail wajib diisi.',
            'bon.*.nominal.required' => 'Nominal setiap transaksi detail wajib diisi.',
            'nominal_transfer.required_if' => 'Nominal transfer wajib diisi.',
            'nominal_biaya.required' => 'Isi nominal biaya transfer, atau hilangkan centangnya.',
            'nominal_biaya.max' => 'Biaya transfer maksimal '.rp(TulisKasSheet::BIAYA_TRANSFER_MAKS).'.',
            'tanggal_reimburse.required' => 'Isi tanggal reimburse, atau hilangkan centang "Sudah reimburse".',
            'tanggal_reimburse.before_or_equal' => 'Tanggal reimburse tidak boleh setelah hari ini.',
        ]);

        // Jumlah bon wajib sama persis dengan nominal transfer; bila tidak, transaksi ditolak dan tidak ditulis ke sheet.
        if ($data['arah'] === 'keluar') {
            $jumlahBon = array_sum(array_map(fn ($b) => (int) $b['nominal'], $data['bon']));
            $nominal = (int) $data['nominal_transfer'];
            if ($jumlahBon !== $nominal) {
                return back()->withInput()->withErrors(['nominal_transfer' => 'Ditolak: jumlah transaksi detail '.rp($jumlahBon).' tidak sama dengan nominal transfer '.rp($nominal)
                    .' (selisih '.rp(abs($nominal - $jumlahBon)).'). Transaksi tidak ditulis ke sheet.']);
            }
        }

        // No Mobil: dibakukan ("dt34" → "DT 034") dan harus terdaftar di Data Aset.
        if ($data['arah'] === 'keluar') {
            $aset = AsetTruk::truk()->pluck('no_lambung')->flip();
            $salah = [];
            foreach ($data['bon'] as $i => $b) {
                $data['bon'][$i]['no_mobil'] = NomorMobil::rapikan($b['no_mobil'] ?? null);
                if ($data['bon'][$i]['no_mobil'] && $aset->isNotEmpty() && ! isset($aset[$data['bon'][$i]['no_mobil']])) {
                    $salah[] = 'detail baris '.((int) $i + 1).': "'.$data['bon'][$i]['no_mobil'].'"';
                }
            }
            if ($salah) {
                return back()->withInput()->withErrors(['bon' => 'No Mobil tidak terdaftar di Data Aset ('.implode(', ', $salah).'). Pilih dari daftar, atau kosongkan bila bukan biaya truk.']);
            }
        }

        $tanggal = Carbon::parse($data['tanggal']);
        $input = [
            'tanggal' => $tanggal,
            'arah' => $data['arah'],
            'nama_tujuan' => $data['nama_tujuan'],
            'no_rek' => $data['no_rek'] ?? null,
            'bank' => DaftarBank::kode($data['bank'] ?? null),
            'keterangan' => $data['keterangan'] ?? null,
            'nominal_masuk' => (int) ($data['nominal_masuk'] ?? 0),
            'kode_gl_masuk' => $data['arah'] === 'masuk' ? (trim(preg_replace('/\s+/', ' ', (string) ($data['kode_gl_masuk'] ?? ''))) ?: null) : null,
            'nominal_transfer' => $data['arah'] === 'keluar' ? (int) $data['nominal_transfer'] : null,
            'bon' => $data['arah'] === 'keluar' ? array_values($data['bon']) : [],
            'biaya_transfer' => $data['arah'] === 'keluar' && $request->boolean('biaya_transfer'),
            'nominal_biaya' => (int) ($data['nominal_biaya'] ?? TulisKasSheet::BIAYA_TRANSFER),
            'reimburse_uj' => $data['arah'] === 'keluar' ? ($data['reimburse_uj'] ?? null) : null,
            'tanggal_reimburse' => $data['arah'] === 'keluar' && $request->boolean('sudah_reimburse') ? Carbon::parse($data['tanggal_reimburse']) : null,
        ];

        return $input;
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $this->bacaInput($request);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        $tanggal = $input['tanggal'];
        // Reimburse Kas UJ: satu reimburse (batch RUJ / tanggal tanpa batch) hanya boleh dicatat sekali.
        $labelRui = $input['reimburse_uj'] ? $this->labelReimburseUj($input['reimburse_uj']) : null;
        if ($input['reimburse_uj'] && ($ada = ReimburseUjKeKas::sudahDiKasKunci($input['reimburse_uj']))) {
            return back()->withInput()->with('error', "Reimburse Kas UJ {$labelRui} sudah dicatat di Kas Harian (NO ID {$ada['no_id']}). Transaksi tidak ditulis.");
        }

        try {
            $hasil = (new TulisKasSheet(GoogleSheets::wajib()))->tulis($input);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }
        // Tautan detail ↔ baris UJ dicatat sebelum Mutasi Reimburse ditulis (kolom Id Transaksi UJ, Jenis Mobil, No DO, Galian, Kategori).
        $tertaut = $input['reimburse_uj'] ? ReimburseUjKeKas::catat($input['reimburse_uj'], $input['bon'], $hasil['no_id'], $request->user()->id) : 0;
        // Sesudah halaman terkirim, sebelum chip Bon: baris-barisnya ikut ditulis di Mutasi Reimburse.
        CerminReimburse::tambahSegera($hasil['no_id']);

        // Foto bon ditautkan ke NO ID baris transfer (baris pertama yang ditulis).
        $jumlahFoto = 0;
        foreach ($request->file('foto', []) as $file) {
            FotoBon::simpan($file, $hasil['no_id'][0], $hasil['lembar'], $request->user()->id);
            $jumlahFoto++;
        }
        // Sesudah halaman terkirim: folder Drive transaksi dibuat & link-nya ditulis di Kode Bon, lalu foto diunggah ke sana.
        if ($jumlahFoto) {
            TautanBon::pastikanSegera($hasil['no_id'][0]);
            FotoBon::unggahSegera();
        }

        $nilai = $input['arah'] === 'masuk' ? $input['nominal_masuk'] : array_sum(array_column($input['bon'], 'nominal'));
        KasRiwayat::create([
            'aksi' => 'tambah', 'lembar' => $hasil['lembar'], 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => ($input['arah'] === 'masuk' ? 'Uang masuk ' : 'Transfer ').rp($nilai).' '.$tanggal->translatedFormat('j M Y').' '
                .trim($input['nama_tujuan'].' — '.$input['keterangan'], ' —').' ('.count($input['bon']).' detail)',
            'isi' => ['input' => [...$input, 'tanggal' => $tanggal->toDateString()], 'no_id' => $hasil['no_id']],
            'user_id' => $request->user()->id,
        ]);
        // Rekening tujuan baru masuk Daftar Rekening (nama Kas Harian = nama pemilik rekening).
        if ($input['arah'] === 'keluar') {
            \App\Support\DaftarRekening::catat($input['no_rek'], $input['bank'], $input['nama_tujuan'], $request->user()->id, true);
        }
        // Dicek sebelum impor ulang, karena impor itulah yang membuat akun barunya.
        $akunBaru = collect($input['bon'])->pluck('kode_gl')
            ->map(fn ($k) => UraiKodeGl::urai($k)['akun'])->filter()
            ->reject(fn ($a) => AkunGl::where('nama', $a)->exists())->unique();
        $pesanImpor = $this->imporUlang($hasil['lembar']);
        $pesanImpor .= $this->tandaiSudahReimburse($input, $hasil['no_id'][0], $request->user()->id);

        return redirect()->route('kas.index', ['lembar' => $hasil['lembar'], 'tgl' => $tanggal->day])->with(
            $pesanImpor ? 'error' : 'success',
            ($input['arah'] === 'masuk' ? 'Uang masuk ' : 'Transfer ').rp($nilai).' tersimpan di sheet lembar '.$hasil['lembar']
            .' baris '.$hasil['baris_awal'].($hasil['baris_akhir'] > $hasil['baris_awal'] ? '–'.$hasil['baris_akhir'] : '')
            .' (NO ID '.reset($hasil['no_id']).(count($hasil['no_id']) > 1 ?'–'.end($hasil['no_id']) : '').').'
            .($akunBaru->isNotEmpty() ? ' Akun baru: '.$akunBaru->implode(', ').'.' : '')
            .($jumlahFoto ? " {$jumlahFoto} foto bon terlampir." : '')
            .($tertaut ? " {$tertaut} detail tertaut ke Kas UJ (reimburse {$labelRui})" : '')
            .$pesanImpor
        );
    }

    /** Daftar reimburse Kas UJ per tanggal (untuk panel "Input reimburse Kas UJ"). */
    public function reimburseUj(): JsonResponse
    {
        return response()->json(['daftar' => ReimburseUjKeKas::daftar()]);
    }

    /** Isi form satu reimburse (batch r{id}, atau tanggal untuk data tanpa batch): master + semua detail UJ-nya. */
    public function reimburseUjIsi(string $tanggal): JsonResponse
    {
        abort_unless(preg_match('/^(r\d+|\d{4}-\d{2}-\d{2})$/', $tanggal), 404);
        abort_if(str_starts_with($tanggal, 'r') && ! ReimburseUjKeKas::batch($tanggal), 404);

        return response()->json(ReimburseUjKeKas::untukForm($tanggal));
    }

    private function labelReimburseUj(string $kunci): string
    {
        return ($r = ReimburseUjKeKas::batch($kunci))
            ? ReimburseUjKeKas::kode($r).' ('.$r->tanggal->translatedFormat('j M Y').')'
            : 'tanggal '.Carbon::parse($kunci)->translatedFormat('j M Y');
    }

    /** Hapus transfer + seluruh bonnya dari sheet (opsional beserta baris biaya transfernya), lalu impor ulang lembarnya. */
    public function hapus(Request $request, KasTransfer $transfer): RedirectResponse
    {
        $transfer->load('bon', 'kasBulan');
        if ($transfer->kasBulan->arsip) {
            return back()->with('error', 'Transaksi arsip (Kas Harian 2024–2025) tidak bisa dihapus.');
        }
        $lembar = $transfer->kasBulan->lembar;
        $ringkasan = ($transfer->debet ? 'Uang masuk ' : 'Transfer ').rp($transfer->debet ?: $transfer->kredit)
            .' '.$transfer->tanggal->translatedFormat('j M Y').' '.trim($transfer->nama_tujuan.' — '.$transfer->keterangan, ' —')
            .' ('.$transfer->bon->count().' detail)';

        // Baris yang sudah direimburse tidak boleh hilang dari Mutasi Reimburse → hapus ditolak.
        $biaya = $request->boolean('dengan_biaya') ? HapusKasSheet::biayaTransferMilik($transfer) : null;
        $idReimburse = [...CerminReimburse::idMilik($transfer), ...($biaya ? CerminReimburse::idMilik($biaya) : [])];
        if ($tolak = $this->cekReimburse($idReimburse)) {
            return back()->with('error', $tolak);
        }

        try {
            $hasil = (new HapusKasSheet(GoogleSheets::wajib()))->hapus($transfer, $request->boolean('dengan_biaya'));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal menghapus: '.$e->getMessage());
        }

        KasRiwayat::create([
            'aksi' => 'hapus', 'lembar' => $lembar, 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => $ringkasan, 'isi' => $hasil['isi'], 'user_id' => $request->user()->id,
        ]);
        CerminReimburse::hapusSegera($idReimburse);
        // Reimburse Kas UJ yang transfernya dihapus bisa dicatat ulang.
        if ($transfer->no_id) {
            KasReimburseUj::where('no_id_transfer', $transfer->no_id)->delete();
        }
        $fotoDihapus = $transfer->no_id ? FotoBon::hapusMilik($transfer->no_id) : 0;
        if ($fotoDihapus) {
            $ringkasan .= ", {$fotoDihapus} foto bon ikut dihapus";
        }
        $pesanImpor = $this->imporUlang($lembar);

        return redirect()->route('kas.index', ['lembar' => $lembar, 'tgl' => $request->integer('tgl') ?: null, 'q' => $request->input('q') ?: null])->with(
            $pesanImpor ? 'error' : 'success',
            "Dihapus dari sheet lembar {$lembar} baris {$hasil['baris_awal']}".($hasil['baris_akhir'] > $hasil['baris_awal'] ? "–{$hasil['baris_akhir']}" : '')
            .": {$ringkasan}.".$pesanImpor
        );
    }

    /** Form Input Kas berisi transaksi yang sudah ada (dicari lewat NO ID, kunci yang tidak berubah saat impor ulang). */
    public function edit(int $noId): View|RedirectResponse
    {
        $t = KasTransfer::where('no_id', $noId)->with('bon.kodeGl', 'kodeGl', 'kasBulan')->first();
        if (! $t) {
            return redirect()->route('kas.index')->with('error', "Transaksi NO ID {$noId} tidak ditemukan. Mungkin sudah dihapus atau sheet berubah — tunggu beberapa menit (data diperbarui otomatis tiap 5 menit) lalu muat ulang.");
        }
        $biaya = HapusKasSheet::biayaTransferMilik($t);
        // Hanya transaksi yang belum direimburse yang boleh diedit (dicek juga saat disimpan).
        if ($tolak = $this->cekReimburse([...CerminReimburse::idMilik($t), ...($biaya ? CerminReimburse::idMilik($biaya) : [])])) {
            return redirect()->route('kas.index', ['lembar' => $t->kasBulan->lembar, 'tgl' => $t->tanggal->day])->with('error', $tolak);
        }

        $edit = [
            'no_id' => $noId,
            'versi' => $this->versi($t),
            'lembar' => $t->kasBulan->lembar,
            'baris' => $t->baris,
            'tanggal' => $t->tanggal->toDateString(),
            'arah' => $t->debet ? 'masuk' : 'keluar',
            'nama_tujuan' => $t->nama_tujuan,
            'no_rek' => $t->no_rek_tujuan,
            'bank' => $t->bank_tujuan,
            'keterangan' => $t->keterangan,
            'nominal_masuk' => $t->debet ?: null,
            'kode_gl_masuk' => $t->debet ? $t->kodeGl?->kode_asli : null,
            'nominal_transfer' => $t->kredit ?: null,
            'biaya_transfer' => $biaya ? '1' : '0',
            'nominal_biaya' => $biaya?->kredit ?: TulisKasSheet::BIAYA_TRANSFER,
            'bon' => $t->bon->map(fn ($b) => ['nominal' => $b->nominal, 'pic' => $b->pic, 'keterangan' => $b->keterangan, 'kode_gl' => $b->kodeGl?->kode_asli, 'no_mobil' => $b->no_mobil])->all(),
            'foto' => KasFoto::kas()->where('no_id', $noId)->orderBy('id')->get()
                ->map(fn ($f) => ['penuh' => route('kas.foto', $f), 'kecil' => route('kas.foto', ['foto' => $f, 'ukuran' => 'kecil'])])->all(),
        ];

        return $this->create()->with('edit', $edit);
    }

    public function update(Request $request, int $noId): RedirectResponse
    {
        $t = KasTransfer::where('no_id', $noId)->with('bon', 'kasBulan')->first();
        if (! $t || $request->input('versi') !== $this->versi($t)) {
            return back()->withInput()->with('error', 'Transaksi ini sudah berubah di sheet sejak form dibuka (atau baru disinkron). Buka Edit lagi supaya perubahan tidak menimpa data terbaru.');
        }
        $input = $this->bacaInput($request);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        $ringkasLama = $this->ringkasan($t);
        // Sudah direimburse → tidak boleh diubah (Mutasi Reimburse ikut berubah).
        $biayaLama = HapusKasSheet::biayaTransferMilik($t);
        $idReimburse = [...CerminReimburse::idMilik($t), ...($biayaLama ? CerminReimburse::idMilik($biayaLama) : [])];
        if ($tolak = $this->cekReimburse($idReimburse)) {
            return back()->withInput()->with('error', $tolak);
        }

        try {
            $adaFoto = $request->file('foto') || KasFoto::kas()->where('no_id', $noId)->exists();
            // Link folder yang sudah ada tetap di Kode Bon (tidak ditimpa kode biasa).
            $hasil = (new TulisKasSheet(GoogleSheets::wajib()))->ubah($t, $input, $adaFoto ? TautanBon::pembuat() : null);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal mengubah di sheet: '.$e->getMessage());
        }

        CerminReimburse::gantiSegera($idReimburse, $hasil['no_id']);

        // Foto lama ikut pindah bila NO ID baris transfer berubah (pindah bulan); foto baru ditautkan ke NO ID transfer.
        $noIdBaru = $hasil['no_id'][0];
        if ($noIdBaru !== $noId) {
            KasFoto::kas()->where('no_id', $noId)->update(['no_id' => $noIdBaru, 'lembar' => $hasil['lembar']]);
        }
        $jumlahFoto = 0;
        foreach ($request->file('foto', []) as $file) {
            FotoBon::simpan($file, $noIdBaru, $hasil['lembar'], $request->user()->id);
            $jumlahFoto++;
        }
        // Sesudah halaman terkirim (berurutan): folder & link Kode Bon, pindah foto lama bila NO ID berubah, unggah foto baru.
        if ($adaFoto) {
            TautanBon::pastikanSegera($noIdBaru);
            if ($noIdBaru !== $noId) {
                FotoBon::pindahFolderSegera($noId, $noIdBaru);
            }
            if ($jumlahFoto) {
                FotoBon::unggahSegera();
            }
        }

        KasRiwayat::create([
            'aksi' => 'ubah', 'lembar' => $hasil['lembar'], 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => mb_strimwidth("{$ringkasLama} → ".$this->ringkasanInput($input), 0, 490, '…'),
            'isi' => ['sebelum' => $hasil['sebelum'], 'input' => [...$input, 'tanggal' => $input['tanggal']->toDateString()], 'no_id' => $hasil['no_id']],
            'user_id' => $request->user()->id,
        ]);
        if ($input['arah'] === 'keluar') {
            \App\Support\DaftarRekening::catat($input['no_rek'], $input['bank'], $input['nama_tujuan'], $request->user()->id, true);
        }
        $pesanImpor = $this->imporUlang($hasil['lembar']);
        if ($hasil['lembar_lama'] !== $hasil['lembar']) {
            $pesanImpor .= $this->imporUlang($hasil['lembar_lama']);
        }
        $pesanImpor .= $this->tandaiSudahReimburse($input, $noIdBaru, $request->user()->id);

        return redirect()->route('kas.index', ['lembar' => $hasil['lembar'], 'tgl' => $input['tanggal']->day])->with(
            $pesanImpor ? 'error' : 'success',
            'Perubahan tersimpan di sheet lembar '.$hasil['lembar'].' baris '.$hasil['baris_awal']
            .($hasil['baris_akhir'] > $hasil['baris_awal'] ? '–'.$hasil['baris_akhir'] : '')
            .($hasil['lembar_lama'] !== $hasil['lembar'] ? " (dipindah dari lembar {$hasil['lembar_lama']})" : '')
            .': '.$this->ringkasanInput($input).'.'
            .($jumlahFoto ? " {$jumlahFoto} foto bon ditambahkan." : '')
            .$pesanImpor
        );
    }

    /**
     * Centang "Sudah reimburse" di Input/Edit Kas: semua detail transfer ini (termasuk baris biaya transfernya) ditandai
     * Sudah Reimburse pada tanggal yang dipilih — jalur yang sama dengan menu reimburse (aplikasi + lembar "Sudah Reimburse").
     * Dipanggil sesudah impor ulang lembarnya (ID transaksi baru terbentuk). Mengembalikan teks untuk pesan hasil.
     */
    private function tandaiSudahReimburse(array $input, int $noIdTransfer, int $userId): string
    {
        if (! $input['tanggal_reimburse']) {
            return '';
        }
        $t = KasTransfer::where('no_id', $noIdTransfer)->with('bon')->first();
        if (! $t) {
            return ' ⚠ Status Sudah Reimburse belum bisa ditandai karena transaksi belum terbaca dari sheet — tandai lewat Validasi Reimburse setelah sinkron.';
        }
        $biaya = HapusKasSheet::biayaTransferMilik($t);
        $ids = array_values(array_unique([...CerminReimburse::idMilik($t), ...($biaya ? CerminReimburse::idMilik($biaya) : [])]));
        $n = StatusReimburse::tandai($ids, $input['tanggal_reimburse'], $userId);
        KasRiwayat::create(['aksi' => 'tandai-reimburse', 'lembar' => $t->kasBulan?->lembar ?? 'Kas', 'baris_awal' => (int) $t->baris, 'baris_akhir' => (int) $t->baris,
            'ringkasan' => "NO ID {$noIdTransfer}: {$n} detail ditandai Sudah Reimburse tgl ".$input['tanggal_reimburse']->translatedFormat('j M Y').' dari form Input/Edit Kas',
            'isi' => ['id_transaksi' => $ids], 'user_id' => $userId]);

        return " {$n} detail ditandai Sudah Reimburse tanggal ".$input['tanggal_reimburse']->translatedFormat('j M Y').'.';
    }

    /** Pesan penolakan bila ada baris transaksi ini yang sudah direimburse (status milik aplikasi). */
    private function cekReimburse(array $ids): ?string
    {
        return StatusReimburse::pesanTolak($ids);
    }

    /** Sidik transaksi saat form dibuka; bila berbeda saat disimpan, berarti sheet sudah berubah di antaranya. */
    private function versi(KasTransfer $t): string
    {
        $t->loadMissing('bon');

        return sha1(json_encode([$t->baris, $t->tanggal->toDateString(), $t->debet, $t->kredit, $t->keterangan, $t->nama_tujuan,
            $t->bon->map(fn ($b) => [$b->baris, $b->nominal, $b->keterangan, $b->pic, $b->kode_gl_id])->all()]));
    }

    private function ringkasan(KasTransfer $t): string
    {
        return ($t->debet ? 'Uang masuk ' : 'Transfer ').rp($t->debet ?: $t->kredit).' '.$t->tanggal->translatedFormat('j M Y')
            .' '.trim($t->nama_tujuan.' — '.$t->keterangan, ' —').' ('.$t->bon->count().' detail)';
    }

    private function ringkasanInput(array $input): string
    {
        $nilai = $input['arah'] === 'masuk' ? $input['nominal_masuk'] : array_sum(array_column($input['bon'], 'nominal'));

        return ($input['arah'] === 'masuk' ? 'Uang masuk ' : 'Transfer ').rp($nilai).' '.$input['tanggal']->translatedFormat('j M Y')
            .' '.trim($input['nama_tujuan'].' — '.$input['keterangan'], ' —').' ('.count($input['bon']).' detail)';
    }

    /** @return string kosong bila berhasil, atau pesan kegagalan untuk ditampilkan */
    private function imporUlang(string $lembar): string
    {
        try {
            ImporKas::imporUlang([$lembar]);

            return '';
        } catch (\Throwable $e) {
            report($e);

            return ' Sheet sudah diubah, tetapi impor ulang ke aplikasi gagal ('.$e->getMessage().'); tunggu beberapa menit (data diperbarui otomatis tiap 5 menit) lalu muat ulang.';
        }
    }
}
