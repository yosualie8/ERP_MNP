<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\UjPengajuan;
use App\Models\UjPengajuanDetail;
use App\Models\UjPengajuanTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengajuan Uang Jalan: form & validasi sama persis dengan Input UJ (memakai bacaInput/wajibKonfirmasi milik UjController),
 * tetapi disimpan di aplikasi saja (tidak ditulis ke sheet). Direalisasikan per detail lewat Input UJ: admin mengetik transaksinya,
 * lalu baris yang DO & kategorinya cocok ditawari "Tautkan ke PUJ-xxxx" (ValidasiUj).
 */
class PengajuanUjController extends UjController
{
    public function daftar(Request $request): View
    {
        $status = array_key_exists($request->query('status'), UjPengajuan::STATUS) ? $request->query('status') : null;
        $beda = $request->boolean('beda');
        $semua = UjPengajuan::with(['detail', 'user', 'transfer', 'tanda'])->orderByDesc('tanggal')->orderByDesc('id')->get();
        // Keabsahan tautan transfer: transaksi Kas Harian dengan NO ID itu saat ini (untuk menandai yang berubah/terhapus).
        $kasKini = KasTransfer::whereIn('no_id', $semua->flatMap->transfer->pluck('kas_no_id')->unique())->get()->keyBy('no_id');
        // Pengajuan yang dibayar dalam satu transfer yang sama (berbagi NO ID Kas Harian) dihitung bersama:
        // total transfer (tiap NO ID sekali) dibandingkan total semua pengajuan dalam kelompok itu.
        $induk = [];
        $akar = function ($x) use (&$induk, &$akar) {
            return ($induk[$x] ?? $x) === $x ? $x : ($induk[$x] = $akar($induk[$x]));
        };
        foreach ($semua->flatMap->transfer->groupBy('kas_no_id') as $tf) {
            $pjIds = $tf->pluck('uj_pengajuan_id')->unique()->values();
            foreach ($pjIds as $id) {
                $induk[$akar($id)] = $akar($pjIds[0]);
            }
        }
        $kelompokTf = [];
        foreach ($semua->filter(fn ($p) => $p->transfer->isNotEmpty())->groupBy(fn ($p) => $akar($p->id)) as $g) {
            $info = ['kode' => $g->map->kode()->values()->all(), 'total_pj' => (int) $g->sum('nominal'),
                'total_tf' => (int) $g->flatMap->transfer->unique('kas_no_id')->sum('nominal')];
            foreach ($g as $p) {
                $kelompokTf[$p->id] = $info;
            }
        }
        $terbuka = fn ($p) => in_array($p->status, ['diajukan', 'sebagian'], true);
        $adaBeda = fn ($p) => $p->detail->contains(fn ($d) => $d->berbeda());
        $detailBeda = $semua->flatMap->detail->filter(fn ($d) => $d->berbeda());

        return view('uj.pengajuan', [
            'pengajuan' => match (true) {
                $beda => $semua->filter($adaBeda)->values(),
                (bool) $status => $semua->where('status', $status)->values(),
                // semua pengajuan terbuka + 50 yang sudah selesai terakhir, diurutkan tanggal pengajuan terbaru dulu (owner 19 Okt 2026:
                // yang selesai jangan ditaruh di bawah yang terbuka, supaya mudah dicari saat menautkan transfer)
                default => $semua->filter($terbuka)->merge($semua->reject($terbuka)->take(50))
                    ->sortBy([fn ($a, $b) => $b->tanggal <=> $a->tanggal, fn ($a, $b) => $b->id <=> $a->id])->values(),
            },
            'semua' => $semua, 'status' => $status, 'beda' => $beda, 'detailBeda' => $detailBeda, 'kasKini' => $kasKini, 'kelompokTf' => $kelompokTf,
            'menunggu' => UjPengajuanDetail::where('status', 'menunggu')->selectRaw('COUNT(*) n, SUM(nominal) s')->first(),
            'bolehRealisasi' => $request->user()->bolehMenu('input-uj'),
            // Penanda transfer yang belum ditautkan ke transaksi Kas Harian (owner menandai saat mentransfer, menautkan H+1)
            'tandaTerbuka' => \App\Models\UjTransferTanda::with(['pengajuan.transfer', 'user'])->whereNull('kas_no_id')->orderBy('waktu')->get(),
            // Menautkan transfer Kas Harian ke pengajuan hanya untuk Super Admin (rutenya juga dijaga can:super-admin).
            'bolehTautkan' => $request->user()->isSuperAdmin(),
        ]);
    }

    /** Mengajukan lagi DO yang sudah diajukan memang dobel — tidak ditawari tautan. */
    protected function tanyaDuluan(): bool
    {
        return true;
    }

    protected function tawarkanTautan(): bool
    {
        return false;
    }

    public function buat(): View
    {
        return $this->create()->with('mode', 'pengajuan');
    }

    public function simpan(Request $request): RedirectResponse
    {
        $input = $this->bacaInput($request, true);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        $temuan = $this->wajibKonfirmasi($input, null, []);
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        if ($tolak = $this->periksaSaldo($input, [])) {
            return $tolak;
        }
        $p = DB::transaction(function () use ($input, $temuan, $request) {
            // Pengajuan = daftar transaksi (tanpa penerima/rekening); nominal = jumlah yang DITRANSFER (detail − bagian dari saldo sopir).
            $p = UjPengajuan::create(['tanggal' => $input['tanggal']->toDateString(), 'nama' => null, 'bank' => null, 'rekening' => null,
                'nominal' => $input['nominal'] - array_sum(array_column($input['detail'], 'saldo')), 'status' => 'diajukan', 'user_id' => $request->user()->id]);
            $this->simpanDetail($p, $input, $temuan);

            return $p;
        });
        KasRiwayat::create(['aksi' => 'uj-ajukan', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($p->kode().' '.$this->ringkas($p), 0, 490, '…'), 'isi' => ['pengajuan_id' => $p->id], 'user_id' => $request->user()->id]);

        return redirect()->route('pengajuan-uj.daftar')->with('success', "Pengajuan {$p->kode()} tersimpan: ".$this->ringkas($p).'.');
    }

    public function ubah(UjPengajuan $pengajuan): View|RedirectResponse
    {
        if ($tolak = $this->tolakUbah($pengajuan)) {
            return redirect()->route('pengajuan-uj.daftar')->with('error', $tolak);
        }
        // Hanya detail yang MENUNGGU yang bisa diubah; yang sudah terealisasi / dialihkan / dibatalkan tampil terkunci.
        [$tunggu, $kunci] = $pengajuan->detail->sortBy('urut')->partition(fn ($d) => $d->status === 'menunggu');
        $edit = [
            'pengajuan_id' => $pengajuan->id, 'kode' => $pengajuan->kode(), 'tanggal' => $pengajuan->tanggal->toDateString(),
            'nominal' => (int) $tunggu->sum('nominal'), 'biaya_transfer' => '0',
            'detail' => $tunggu->map(fn ($d) => $d->only(['nama', 'keterangan', 'nominal', 'saldo', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do', 'konfirmasi']))->values()->all(),
            'menunggu' => $tunggu->pluck('id')->sort()->implode(','),
            'terkunci' => $kunci->values(),
        ];
        // saldo sopir yang dipakai detail yang sedang diubah dihitung tersedia lagi
        $saldo = \App\Support\SaldoSopir::perMobil()->map(fn ($m) => (int) round(\App\Support\SaldoSopir::sisa($m['no_mobil'], $tunggu->pluck('id')->all())))->filter(fn ($v) => $v > 0)->all();

        return $this->create()->with(['mode' => 'pengajuan', 'edit' => $edit, 'saldoSopir' => $saldo]);
    }

    public function simpanUbah(Request $request, UjPengajuan $pengajuan): RedirectResponse
    {
        if ($tolak = $this->tolakUbah($pengajuan)) {
            return redirect()->route('pengajuan-uj.daftar')->with('error', $tolak);
        }
        $input = $this->bacaInput($request, true);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        $tunggu = $pengajuan->detail()->where('status', 'menunggu')->pluck('id');
        // Detail yang sedang diedit harus sama dengan saat form dibuka (mungkin baru saja direalisasikan di Input UJ).
        if ($tunggu->sort()->implode(',') !== (string) $request->input('menunggu')) {
            return redirect()->route('pengajuan-uj.ubah', $pengajuan)->with('error', "Ada detail {$pengajuan->kode()} yang baru saja direalisasikan / dibatalkan — form dimuat ulang, periksa lagi perubahan Anda.");
        }
        $temuan = $this->wajibKonfirmasi($input, null, $tunggu->all());
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        if ($tolak = $this->periksaSaldo($input, $tunggu->all())) {
            return $tolak;
        }
        DB::transaction(function () use ($pengajuan, $input, $temuan, $tunggu) {
            // Detail terkunci (terealisasi / dialihkan / dibatalkan) tidak disentuh; hanya yang menunggu diganti isi form.
            // Pemakaian saldo sopir detail yang diganti ikut terhapus (cascade uj_pengalihan.uj_pengajuan_detail_id).
            $tetap = $pengajuan->detail()->whereNotIn('id', $tunggu)->get();
            $pengajuan->update(['tanggal' => $input['tanggal']->toDateString(),
                'nominal' => $input['nominal'] - array_sum(array_column($input['detail'], 'saldo')) + (int) $tetap->sum(fn ($d) => $d->nominal - $d->saldo)]);
            \App\Models\UjPengalihan::whereIn('uj_pengajuan_detail_id', $tunggu)->delete();
            $pengajuan->detail()->whereIn('id', $tunggu)->delete();
            $this->simpanDetail($pengajuan, $input, $temuan, (int) $tetap->max('urut'));
        });
        KasRiwayat::create(['aksi' => 'uj-ajukan-ubah', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($pengajuan->kode().' diubah: '.$this->ringkas($pengajuan->fresh()), 0, 490, '…'), 'isi' => ['pengajuan_id' => $pengajuan->id], 'user_id' => $request->user()->id]);

        return redirect()->route('pengajuan-uj.daftar')->with('success', "Pengajuan {$pengajuan->kode()} diperbarui.");
    }

    /** Batalkan detail yang masih menunggu (detail yang sudah terealisasi tidak berubah). */
    public function batal(Request $request, UjPengajuan $pengajuan): RedirectResponse
    {
        // pemakaian saldo sopir detail yang dibatalkan dikembalikan ke saldo
        \App\Models\UjPengalihan::whereIn('uj_pengajuan_detail_id', $pengajuan->detail()->where('status', 'menunggu')->pluck('id'))->delete();
        $n = $pengajuan->detail()->where('status', 'menunggu')->update(['status' => 'batal']);
        $pengajuan->hitungStatus();
        KasRiwayat::create(['aksi' => 'uj-ajukan-batal', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "{$pengajuan->kode()} dibatalkan ({$n} detail)", 'isi' => ['pengajuan_id' => $pengajuan->id], 'user_id' => $request->user()->id]);

        return back()->with('success', "Pengajuan {$pengajuan->kode()}: {$n} detail yang menunggu dibatalkan.");
    }

    /**
     * Kandidat transfer Kas Harian untuk pengajuan-pengajuan terpilih (?pj=1,2,3): transaksi keluar (tanpa baris biaya transfer)
     * di sekitar tanggal pengajuan, atau hasil pencarian (keterangan, tujuan, NO ID, nominal).
     */
    public function kandidatTransfer(Request $request): JsonResponse
    {
        $pj = UjPengajuan::whereIn('id', $this->idPengajuan($request->query('pj')))->get();
        abort_if($pj->isEmpty(), 422, 'Pilih pengajuan dulu.');
        $q = trim((string) $request->query('q'));
        $angka = preg_replace('/\D/', '', $q);
        $data = KasTransfer::where('kredit', '>', 0)->where(fn ($w) => $w->whereNull('keterangan')->orWhere('keterangan', 'not like', 'biaya transfer%'))
            ->when($q === '', fn ($w) => $w->whereBetween('tanggal', [$pj->min('tanggal')->copy()->subDays(7), $pj->max('tanggal')->copy()->addDays(30)]))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('keterangan', 'like', "%{$q}%")->orWhere('nama_tujuan', 'like', "%{$q}%")
                ->when($angka !== '', fn ($y) => $y->orWhere('no_id', $angka)->orWhere('kredit', $angka))))
            ->orderByDesc('tanggal')->orderByDesc('baris')->limit(200)->get();
        $dipakai = UjPengajuanTransfer::with('pengajuan')->whereIn('kas_no_id', $data->pluck('no_id'))->get()->groupBy('kas_no_id');

        return response()->json(['transfer' => $data->map(fn (KasTransfer $t) => [
            'no_id' => (int) $t->no_id, 'tanggal' => $t->tanggal->toDateString(), 'tgl' => $t->tanggal->translatedFormat('j M Y'),
            'id_kas' => $t->tanggal->format('ymd').'-Jago-'.$t->no_id, 'nama' => $t->nama_tujuan, 'bank' => $t->bank_tujuan,
            'keterangan' => $t->keterangan, 'nominal' => (int) $t->kredit,
            'dipakai' => ($dipakai[$t->no_id] ?? collect())->map(fn ($x) => $x->pengajuan->kode())->values(),
            // Sudah tertaut ke SEMUA pengajuan terpilih → tidak perlu dipilih lagi.
            'terpasang' => $pj->every(fn ($p) => ($dipakai[$t->no_id] ?? collect())->contains('uj_pengajuan_id', $p->id)),
        ])->values()]);
    }

    /**
     * Tautkan transfer Kas Harian (NO ID) ke satu atau beberapa pengajuan sekaligus (beberapa pengajuan bisa dibayar dalam
     * satu kali transfer); data transfernya disalin dari Kas Harian saat ini.
     */
    public function tautkanTransfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pj' => ['required', 'array', 'min:1'], 'pj.*' => ['integer'],
            'no_id' => ['required', 'array', 'min:1'], 'no_id.*' => ['integer'],
        ], ['pj.required' => 'Pilih minimal satu pengajuan.', 'no_id.required' => 'Pilih minimal satu transaksi Kas Harian.']);
        $pj = UjPengajuan::whereIn('id', $data['pj'])->orderBy('tanggal')->orderBy('id')->get();
        $kas = KasTransfer::where('kredit', '>', 0)->whereIn('no_id', $data['no_id'])->get()->keyBy('no_id');
        // Ditautkan dari penanda transfer → penanda itu selesai (tercatat NO ID Kas Harian-nya)
        $tanda = $request->filled('tanda') ? \App\Models\UjTransferTanda::whereNull('kas_no_id')->find($request->integer('tanda')) : null;
        $baru = collect();
        foreach ($pj as $p) {
            foreach (array_unique($data['no_id']) as $no) {
                if (! ($t = $kas[$no] ?? null) || UjPengajuanTransfer::where('uj_pengajuan_id', $p->id)->where('kas_no_id', $no)->exists()) {
                    continue;
                }
                $baru->push(UjPengajuanTransfer::create(['uj_pengajuan_id' => $p->id, 'kas_no_id' => $no, 'kas_tanggal' => $t->tanggal->toDateString(),
                    'nominal' => (int) $t->kredit, 'nama_tujuan' => $t->nama_tujuan, 'keterangan' => mb_substr((string) $t->keterangan, 0, 500), 'user_id' => $request->user()->id]));
            }
        }
        if ($baru->isEmpty()) {
            return back()->with('error', 'Tidak ada transfer baru yang ditautkan (sudah tertaut atau tidak ditemukan di Kas Harian).');
        }
        $tanda?->update(['kas_no_id' => (int) $baru->first()->kas_no_id]);
        $kode = $pj->filter(fn ($p) => $baru->contains('uj_pengajuan_id', $p->id))->map->kode()->implode(', ');
        $ringkas = $baru->unique('kas_no_id')->map(fn ($x) => $x->idKas().' '.rp($x->nominal))->implode(', ');
        KasRiwayat::create(['aksi' => 'uj-ajukan-transfer', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($kode.' ← transfer Kas Harian '.$ringkas, 0, 490, '…'),
            'isi' => ['pengajuan_id' => $pj->pluck('id')->all(), 'kas_no_id' => $baru->pluck('kas_no_id')->unique()->values()->all()], 'user_id' => $request->user()->id]);

        return back()->with('success', "{$kode} ditautkan ke transfer Kas Harian: {$ringkas}.");
    }

    /**
     * Tandai transfer (owner 19 Okt 2026): saat mentransfer, owner menandai pengajuan mana yang dibayar dengan nominal & jam
     * transfer itu. Transaksi Kas Harian-nya baru ada H+1 — saat itu penanda dipakai untuk menautkan transfer yang sebenarnya.
     */
    public function simpanTanda(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'pj' => ['required', 'array', 'min:1'], 'pj.*' => ['integer'],
            'nominal' => ['required', 'string', 'max:20'], 'waktu' => ['required', 'date'], 'catatan' => ['nullable', 'string', 'max:300'],
        ], ['pj.required' => 'Pilih minimal satu pengajuan.', 'nominal.required' => 'Isi nominal yang ditransfer.', 'waktu.required' => 'Isi tanggal & jam transfer.']);
        $nominal = (int) preg_replace('/\D/', '', $d['nominal']);
        $pj = UjPengajuan::whereIn('id', $d['pj'])->orderBy('id')->get();
        if ($nominal <= 0 || $pj->isEmpty()) {
            return back()->with('error', 'Nominal transfer harus lebih dari 0 dan minimal satu pengajuan dipilih.');
        }
        $t = DB::transaction(function () use ($d, $nominal, $pj, $request) {
            $t = \App\Models\UjTransferTanda::create(['waktu' => $d['waktu'], 'nominal' => $nominal, 'catatan' => $d['catatan'] ?? null, 'user_id' => $request->user()->id]);
            $t->pengajuan()->sync($pj->pluck('id'));

            return $t;
        });
        $selisih = $nominal - (int) $pj->sum('nominal');
        $ket = $t->kode().' '.$t->waktu->translatedFormat('j M H:i').' '.rp($nominal).' untuk '.$pj->map->kode()->implode(', ').' ('.rp((int) $pj->sum('nominal')).($selisih ? ($selisih > 0 ? ', lebih ' : ', kurang ').rp(abs($selisih)) : ', pas').')';
        KasRiwayat::create(['aksi' => 'uj-ajukan-tanda', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth('Tandai transfer '.$ket, 0, 490, '…'),
            'isi' => ['tanda_id' => $t->id, 'pengajuan_id' => $pj->pluck('id'), 'nominal' => $nominal, 'waktu' => $d['waktu']], 'user_id' => $request->user()->id]);

        return back()->with('success', "Transfer ditandai: {$ket}. Tautkan ke Kas Harian setelah Kas Harian direkap.");
    }

    public function hapusTanda(Request $request, \App\Models\UjTransferTanda $tanda): RedirectResponse
    {
        $kode = $tanda->kode();
        KasRiwayat::create(['aksi' => 'uj-ajukan-tanda', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Penanda transfer {$kode} (".rp($tanda->nominal).') dihapus: '.$tanda->pengajuan->map->kode()->implode(', '),
            'isi' => ['hapus' => $tanda->only(['id', 'waktu', 'nominal', 'catatan', 'kas_no_id']), 'pengajuan_id' => $tanda->pengajuan->pluck('id')], 'user_id' => $request->user()->id]);
        $tanda->delete();

        return back()->with('success', "Penanda transfer {$kode} dihapus.");
    }

    /** "1,2,3" / [1, 2] → id pengajuan. @return int[] */
    private function idPengajuan(mixed $v): array
    {
        return array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string) $v)))));
    }

    public function lepasTransfer(Request $request, UjPengajuanTransfer $transfer): RedirectResponse
    {
        $kode = $transfer->pengajuan->kode();
        $idKas = $transfer->idKas();
        KasRiwayat::create(['aksi' => 'uj-ajukan-transfer', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "{$kode}: tautan transfer {$idKas} (".rp($transfer->nominal).') dilepas',
            'isi' => ['pengajuan_id' => $transfer->uj_pengajuan_id, 'lepas' => $transfer->only(['kas_no_id', 'kas_tanggal', 'nominal'])], 'user_id' => $request->user()->id]);
        $transfer->delete();

        return back()->with('success', "Tautan transfer {$idKas} dari {$kode} dilepas.");
    }

    private function simpanDetail(UjPengajuan $p, array $input, array $temuan, int $urutAwal = 0): void
    {
        foreach ($input['detail'] as $i => $d) {
            $detail = UjPengajuanDetail::create([
                'uj_pengajuan_id' => $p->id, 'urut' => $urutAwal + $i + 1, 'nama' => $d['nama'], 'keterangan' => $d['keterangan'], 'nominal' => $d['nominal'],
                'kategori' => $d['kategori'], 'jenis_kendaraan' => $d['jenis_kendaraan'], 'no_mobil' => $d['no_mobil'], 'no_do' => $d['no_do'],
                'saldo' => $d['saldo'], 'konfirmasi' => $d['konfirmasi'], 'temuan' => $temuan[$i] ?? null, 'status' => 'menunggu',
            ]);
            // Dibayar dari saldo sopir: tercatat sebagai pemakaian saldo (biaya proyek tujuan; UJ ber-DO ikut dipantau bongkarnya).
            // Dibayar penuh dari saldo = tidak ada yang ditransfer → langsung terealisasi.
            if ($d['saldo'] > 0) {
                $m = \App\Support\SaldoSopir::perMobil()[$d['no_mobil']] ?? null;
                \App\Models\UjPengalihan::create(['jenis' => 'pakai', 'no_do' => $d['no_do'], 'no_mobil' => $d['no_mobil'], 'nama' => $d['nama'] ?: $m['nama'] ?? null,
                    'ke_tahap_id' => $m['tahap_id'] ?? null, 'tanggal' => $p->tanggal, 'nominal' => $d['saldo'], 'uj_pengajuan_detail_id' => $detail->id,
                    'peruntukan' => $d['kategori'] === 'Uang MOB' ? 'mob' : (\App\Support\ValidasiUj::jenisKategori($d['kategori']) === 'uj' ? 'uj' : 'lain'),
                    'keterangan' => mb_strimwidth($p->kode().' — '.$d['keterangan'], 0, 300, '…'), 'user_id' => auth()->id()]);
                if ($d['saldo'] >= $d['nominal']) {
                    $detail->update(['status' => 'terealisasi', 'jenis_realisasi' => 'saldo', 'alasan' => 'Dibayar penuh dari saldo sopir (UJ yang dialihkan) — tidak ditransfer.',
                        'realisasi_pada' => now(), 'realisasi_oleh' => auth()->id()]);
                }
            }
            // Transaksi Kas UJ yang terjadi duluan (dijawab "Ya"): langsung tercatat sebagai realisasi pengajuan ini.
            if ($d['duluan'] && ($uj = \App\Models\UjDetail::where('id_uj', $d['duluan'])->first())) {
                $isiUj = $uj->only(['nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do']);
                $b = \App\Support\BandingPengajuan::banding($detail, $isiUj);
                $sesuai = $b['jenis'] === \App\Support\BandingPengajuan::SESUAI;
                $detail->update([
                    'status' => $b['jenis'] === \App\Support\BandingPengajuan::DIALIHKAN ? 'dialihkan' : 'terealisasi', 'id_uj' => $uj->id_uj, 'jenis_realisasi' => $b['jenis'],
                    'alasan' => $sesuai ? null : 'Transaksi UJ terjadi duluan sebelum pengajuan (dikonfirmasi admin saat mengajukan).',
                    'realisasi' => $sesuai ? null : [...$isiUj, 'id_uj' => $uj->id_uj, 'beda' => $b['beda']],
                    'realisasi_pada' => now(), 'realisasi_oleh' => auth()->id(),
                ]);
            }
        }
        \App\Support\SaldoSopir::lupakan();
        $p->hitungStatus();
    }

    /**
     * Pakai saldo sopir: wajib No Mobil, tidak melebihi nominal detail maupun saldo mobil itu. DO lama milik UJ yang dialihkan
     * tidak boleh dibayar dari saldo lagi — saldonya otomatis terpakai saat DO itu bongkar (cukup ajukan kekurangannya).
     */
    private function periksaSaldo(array $input, array $kecualiDetail): ?RedirectResponse
    {
        $pesan = [];
        $per = [];
        foreach ($input['detail'] as $i => $d) {
            if (! $d['saldo']) {
                continue;
            }
            $b = 'Baris '.($i + 1);
            if (! $d['no_mobil']) {
                $pesan["saldo.{$i}"] = "{$b}: isi No Mobil untuk membayar dari saldo sopir.";

                continue;
            }
            if ($d['saldo'] > $d['nominal']) {
                $pesan["saldo.{$i}"] = "{$b}: dibayar dari saldo (".rp($d['saldo']).') melebihi nominalnya ('.rp($d['nominal']).').';

                continue;
            }
            $lama = (\App\Support\SaldoSopir::perMobil()[$d['no_mobil']]['uj'] ?? collect())
                ->first(fn ($x) => ! $x->bongkar && $x->kunci && $x->kunci === \App\Support\SaldoSopir::kunciDo($d['no_do']));
            if ($lama) {
                $pesan["saldo.{$i}"] = "{$b}: DO {$d['no_do']} adalah DO lama {$lama->p->id_uj} (".rp($lama->p->nominal).') yang dialihkan — saldonya otomatis terpakai begitu DO itu bongkar. '
                    .'Ajukan kekurangannya saja tanpa "Pakai saldo".';

                continue;
            }
            $per[$d['no_mobil']] = ($per[$d['no_mobil']] ?? 0) + $d['saldo'];
        }
        foreach ($per as $m => $s) {
            if ($s > ($sisa = \App\Support\SaldoSopir::sisa($m, $kecualiDetail)) + 0.5) {
                $pesan['saldo.'.$m] = "Saldo sopir {$m} hanya ".rp((int) $sisa).', tetapi dipakai '.rp($s).'.';
            }
        }

        return $pesan ? back()->withInput()->withErrors($pesan) : null;
    }

    private function tolakUbah(UjPengajuan $p): ?string
    {
        // Boleh diubah selama masih ada detail yang menunggu realisasi (detail lain tampil terkunci).
        return $p->detail()->where('status', 'menunggu')->exists()
            ? null
            : "Pengajuan {$p->kode()} sudah tidak punya detail yang menunggu (semua terealisasi / dibatalkan) — tidak bisa diubah.";
    }

    private function ringkas(UjPengajuan $p): string
    {
        $d = $p->detail()->get();

        return rp($p->nominal).' '.$p->tanggal->translatedFormat('j M Y').' ('.$d->count().' transaksi · '.$d->pluck('nama')->filter()->unique()->implode(', ').')';
    }
}
