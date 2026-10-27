<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use App\Support\KontrolUj;
use App\Support\NomorMobil;
use App\Support\SaldoSopir;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengalihan UJ (dirancang ulang owner 20 Okt 2026): tugasnya hanya MEMILIH UJ belum bongkar yang dijadikan SALDO SOPIR — proyeknya
 * belum diketahui (ada masa tunggu). Kas UJ aslinya tidak diubah. Saldo per sopir (sopir sering pindah truk); biaya baru ditentukan saat
 * saldo berkurang: DO lama bongkar (proyek tempat bongkar), dipotong di Pengajuan UJ (jumlah ditentukan admin; proyek UJ itu), dipakai manual (proyek
 * dipilih saat dicatat), dikembalikan sopir (bukan biaya), atau tidak kembali (proyek asal). Lihat App\Support\SaldoSopir.
 */
class PengalihanUjController extends Controller
{
    /** UJ (Uang Jalan / Uang MOB) belum bongkar yang bisa dijadikan saldo: [id_uj => object{u, do, tgl, umur, tahap, tahap_id, status, lama}]. */
    private function terbuka(): Collection
    {
        $hasil = collect();
        foreach (KontrolUj::terbuka() as $d) {
            foreach ($d['detail'] as $u) {
                if (! in_array($u->kategori, KontrolUj::KATEGORI, true) || str_starts_with((string) $u->id_uj, 'SALDO-') || ! $u->id_uj) {
                    continue;
                }
                $hasil[$u->id_uj] = (object) ['u' => $u, 'do' => $d['do'], 'tgl' => $u->tanggal?->toDateString(), 'umur' => $u->tanggal ? (int) $u->tanggal->copy()->startOfDay()->diffInDays(today()) : null,
                    'tahap' => $d['tahap'], 'tahap_id' => $d['tahap_id'], 'status' => $d['status'], 'lama' => $d['lama']];
            }
        }

        return $hasil;
    }

    public function index(Request $request): View
    {
        $saldo = SaldoSopir::perSopir();
        $tab = in_array($request->query('tab'), ['pilih', 'saldo'], true) ? $request->query('tab')
            : ($request->filled('asal') || $request->filled('mobil') || $saldo->where('saldo', '>', 0.5)->isEmpty() ? 'pilih' : 'saldo');
        $data = ['tab' => $tab, 'saldo' => $saldo, 'tahap' => ProyekTahap::orderBy('nama')->get(['id', 'nama', 'status', 'tipe'])->keyBy('id'), 'dipilih' => null];

        if ($tab === 'saldo') {
            // Buku utang per sopir (owner 27 Okt 2026): sopir mana pun bisa dibuka (juga yang belum punya catatan) untuk mencatat kasbon.
            $data['namaSopir'] = collect(\App\Support\DaftarSopir::untukForm()['aktif'])->map(fn ($x) => SaldoSopir::kunci($x['nama']))->merge($saldo->keys())->filter()->unique()->sort()->values();
            if ($k = SaldoSopir::kunci($request->query('sopir'))) {
                $data['dipilih'] = $saldo[$k] ?? ['sopir' => $k, 'mobil' => array_values(array_filter([\App\Support\DaftarSopir::cari($k)['mobil'] ?? null])), 'alih' => 0.0, 'n_alih' => 0,
                    'kasbon' => 0.0, 'terpakai_do' => 0.0, 'dipakai' => 0.0, 'kembali' => 0.0, 'hangus' => 0.0, 'pakai' => 0.0, 'saldo' => 0.0, 'umur' => null, 'sejak' => null,
                    'status' => 'habis', 'uj' => collect(), 'kasbon_rows' => collect(), 'pakai_rows' => collect(), 'terakhir' => null];
                $data['bonKasbon'] = self::bonKasbon()->filter(fn ($b) => $b->sisa > 0.5)->values();
                // Uang jalan DO dari utang → transfer Kas UJ ber-DO sama (sisa uang jalannya), untuk kolom Status buku utang.
                $doUtang = $data['dipilih']['pakai_rows']->where('peruntukan', 'uj')->pluck('no_do')->map(fn ($v) => SaldoSopir::kunciDo($v))->filter()->unique();
                $data['kasUjDo'] = $doUtang->isEmpty() ? collect() : UjDetail::where('biaya_transfer', false)->where('kategori', 'like', '%jalan%')
                    ->where(fn ($q) => $doUtang->each(fn ($k) => $q->orWhere('no_do', 'like', '%'.$k)))->get(['id_uj', 'nominal', 'no_do'])
                    ->filter(fn ($u) => $doUtang->contains(SaldoSopir::kunciDo($u->no_do)))->groupBy(fn ($u) => SaldoSopir::kunciDo($u->no_do));
            }
        }

        if ($tab === 'pilih') {
            // UJ yang SUDAH jadi saldo sopir tetap tampil (owner 20 Okt 2026): kolom Status hanya "Sudah jadi saldo sopir" / "Belum"; tanpa centang,
            // bisa dibatalkan dari sini. UJ yang dikembalikan / ditutup tanpa bongkar tidak ditampilkan (bukan saldo).
            $proses = UjPengalihan::with('dari')->where('jenis', 'alih')->whereNotNull('id_uj')->get();
            $ujProses = UjDetail::whereIn('id_uj', $proses->pluck('id_uj'))->get()->keyBy('id_uj');
            $bongkar = $saldo->flatMap(fn ($s) => $s['uj'])->keyBy(fn ($x) => $x->p->id_uj);
            $sejak = \Illuminate\Support\Carbon::parse(KontrolUj::SEJAK);
            $diproses = $proses->filter(fn ($p) => isset($ujProses[$p->id_uj]))->mapWithKeys(function ($p) use ($ujProses, $bongkar, $sejak, $data) {
                $u = $ujProses[$p->id_uj];
                $x = $bongkar[$p->id_uj] ?? null;
                $info = match ($p->jenis) {
                    'alih' => '💰 Sudah jadi saldo sopir '.($p->sopir ?: $p->nama).' ('.$p->tanggal->translatedFormat('j M').')'
                        .($x?->bongkar ? ' · DO lama sudah bongkar '.$x->bongkar->tanggal->translatedFormat('j M').' di '.($data['tahap'][$x->tahap_bongkar]->nama ?? $x->bongkar->tahap).' — saldonya terpakai' : ''),
                    'kembali' => '↩ Sudah dikembalikan sopir ('.$p->tanggal->translatedFormat('j M').')',
                    default => '✕ Sudah ditutup tanpa bongkar ('.$p->tanggal->translatedFormat('j M').')',
                };

                return [$p->id_uj => (object) ['u' => $u, 'do' => SaldoSopir::kunciDo($u->no_do), 'tgl' => $u->tanggal?->toDateString(),
                    'umur' => $u->tanggal ? (int) $u->tanggal->copy()->startOfDay()->diffInDays(today()) : null, 'tahap' => $p->dari?->nama, 'tahap_id' => $p->dari_tahap_id,
                    'status' => 'proses', 'lama' => $u->tanggal && $u->tanggal->lt($sejak), 'p' => $p, 'info' => $info.($p->keterangan ? ' — '.$p->keterangan : '')]];
            });
            $semua = $this->terbuka()->union($diproses);
            $asal = $request->integer('asal') ?: null;
            $lama = $request->boolean('lama');
            $status = in_array($request->query('status'), ['belum', 'sudah'], true) ? $request->query('status') : 'semua';
            $cari = trim((string) $request->query('cari'));
            $mobil = collect(explode(',', (string) $request->query('mobil')))->map(fn ($m) => NomorMobil::rapikan(trim($m)))->filter()->values();
            $calon = $semua->filter(fn ($c) => ($lama || ! $c->lama) && (! $asal || $c->tahap_id === $asal) && ($mobil->isEmpty() || $mobil->contains(NomorMobil::rapikan($c->u->no_mobil)))
                && ($status === 'semua' || ($status === 'sudah') === isset($c->p))
                && ($cari === '' || str_contains(mb_strtolower($c->u->nama.' '.$c->u->no_mobil.' '.$c->u->id_uj.' '.$c->u->no_do.' '.$c->u->keterangan), mb_strtolower($cari))));

            // Petunjuk "mungkin sudah jalan": uang tanah DO itu sudah dibayar, atau truk itu sudah ada rit sesudah tanggal UJ.
            $kunci = fn ($v) => SaldoSopir::kunciDo($v);
            $tanah = UjDetail::where('kategori', 'Uang Tanah')->whereIn('no_do', $calon->pluck('u.no_do')->filter()->unique()->values())->get(['no_do'])
                ->map(fn ($u) => $kunci($u->no_do))->filter()->flip();
            $ritAkhir = Ritasi::whereIn('no_lambung', $calon->pluck('u.no_mobil')->filter()->unique()->values())->selectRaw('no_lambung, MAX(tanggal) t')->groupBy('no_lambung')->pluck('t', 'no_lambung');
            $calon = $calon->map(function ($c) use ($tanah, $ritAkhir, $kunci, $mobil) {
                $akhir = ($t = $ritAkhir[$c->u->no_mobil] ?? null) ? \Illuminate\Support\Carbon::parse($t)->toDateString() : null;
                $c->rit_akhir = $akhir;
                $c->catatan = isset($c->p) ? null : ($c->u->kategori === 'Uang MOB' ? 'Uang MOB — biasanya sudah terpakai untuk mobilisasi'
                    : (isset($tanah[$kunci($c->u->no_do)]) ? 'uang tanah DO ini sudah dibayar — mungkin sudah jalan' : ($akhir && $c->tgl < $akhir ? "truk ini sudah ada rit sesudahnya ({$akhir}) — cek dulu" : null)));
                $c->usul = $mobil->isNotEmpty() && ! $c->catatan && ! isset($c->p);

                return $c;
            })->sortBy(fn ($c) => [SaldoSopir::kunci($c->u->nama), $c->tgl])->values();

            $tampil = $semua->filter(fn ($c) => $lama || ! $c->lama);
            $data += ['calon' => $calon, 'asal' => $asal, 'lama' => $lama, 'cari' => $cari, 'mobil' => $mobil, 'status' => $status,
                'daftarAsal' => $tampil->groupBy(fn ($c) => (int) $c->tahap_id)->map(fn ($g) => ['nama' => $g->first()->tahap ?? 'Proyek tidak dikenali', 'n' => $g->count()]),
                'jumlahStatus' => ['belum' => $tampil->filter(fn ($c) => ! isset($c->p))->count(), 'sudah' => $tampil->filter(fn ($c) => isset($c->p))->count()],
                'jumlahLama' => $semua->where('lama', true)->count()];
        }

        return view('pengalihan-uj.index', $data);
    }

    /** Pilih UJ → jadikan saldo sopir (atau catat dikembalikan sopir). Tanpa proyek tujuan. */
    public function simpan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'jenis' => ['required', 'in:alih,kembali'], 'tanggal' => ['required', 'date'], 'keterangan' => ['required', 'string', 'min:5', 'max:300'],
            'uj' => ['required', 'array', 'min:1'], 'uj.*' => ['string', 'max:20'],
        ], ['uj.required' => 'Centang minimal satu UJ.', 'keterangan.*' => 'Tulis alasannya (mis. "Truk pindah lokasi sebelum bongkar").']);
        $terbuka = $this->terbuka();
        $pilih = collect($d['uj'])->unique()->values();
        $sudah = UjPengalihan::whereIn('id_uj', $pilih)->pluck('id_uj')->flip();
        $salah = $pilih->reject(fn ($id) => isset($terbuka[$id]) && ! isset($sudah[$id]));
        if ($salah->isNotEmpty()) {
            return back()->withInput()->with('error', 'UJ berikut bukan UJ belum bongkar (atau sudah dijadikan saldo / ditutup): '.$salah->implode(', '));
        }
        DB::transaction(function () use ($d, $pilih, $terbuka, $request) {
            foreach ($pilih as $id) {
                $c = $terbuka[$id];
                UjPengalihan::create(['jenis' => $d['jenis'], 'id_uj' => $id, 'no_do' => $c->u->no_do ? ltrim(trim($c->u->no_do), "`'") : null,
                    'no_mobil' => NomorMobil::rapikan($c->u->no_mobil), 'nama' => $c->u->nama, 'sopir' => SaldoSopir::kunci($c->u->nama),
                    'dari_tahap_id' => $c->tahap_id, 'tanggal' => $d['tanggal'], 'nominal' => $c->u->nominal, 'keterangan' => $d['keterangan'], 'user_id' => $request->user()->id]);
            }
        });
        $rp = $pilih->sum(fn ($id) => $terbuka[$id]->u->nominal);
        $label = $d['jenis'] === 'alih' ? 'dijadikan saldo sopir' : 'dicatat dikembalikan sopir';
        KasRiwayat::create(['aksi' => 'uj-alih', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($pilih->count()." UJ {$label} (Rp ".number_format($rp, 0, ',', '.').'): '.$pilih->implode(', ').' — '.$d['keterangan'], 0, 490, '…'),
            'isi' => ['uj' => $pilih, 'jenis' => $d['jenis'], 'keterangan' => $d['keterangan']], 'user_id' => $request->user()->id]);

        return redirect()->route('pengalihan-uj.index', ['tab' => $d['jenis'] === 'alih' ? 'saldo' : 'pilih'])
            ->with('success', $pilih->count().' UJ (Rp '.number_format($rp, 0, ',', '.').") {$label}.");
    }

    /**
     * ➖ Potong utang / saldo sopir (owner 27 Okt 2026), grid seperti Input UJ — satu baris = satu potongan: uang jalan DO baru (No DO + DT; boleh
     * sebagian — sisanya ditransfer di Kas UJ dengan DO yang sama, tersambung otomatis lewat No DO), Uang MOB / lain (proyek dipilih), potong gaji / UM,
     * dikembalikan sopir, sudah dicicil sebelum dicatat, atau tidak kembali (proyek dipilih). Semua baris disimpan sekaligus atau tidak sama sekali.
     */
    public function pakai(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'sopir' => ['required', 'string', 'max:100'], 'baris' => ['required', 'array', 'min:1', 'max:50'],
            'baris.*.peruntukan' => ['required', 'in:'.implode(',', array_keys(UjPengalihan::PERUNTUKAN))], 'baris.*.tanggal' => ['required', 'date'],
            'baris.*.nominal' => ['required', 'string', 'max:20'], 'baris.*.tahap' => ['nullable', 'integer', 'exists:proyek_tahap,id'], 'baris.*.keterangan' => ['nullable', 'string', 'max:300'],
            'baris.*.no_do' => ['nullable', 'string', 'max:20'], 'baris.*.no_mobil' => ['nullable', 'string', 'max:20'], 'baris.*.kas_no_id' => ['nullable', 'string', 'max:30'],
            'baris.*.tetap' => ['nullable', 'boolean'],
        ], ['baris.required' => 'Isi minimal satu baris potongan.', 'baris.*.tanggal.required' => 'Isi tanggal di setiap baris.', 'baris.*.nominal.required' => 'Isi nominal di setiap baris.']);
        $sopir = SaldoSopir::kunci($d['sopir']);
        $s = SaldoSopir::perSopir()[$sopir] ?? null;
        $sisa = SaldoSopir::sisa($sopir);
        $siap = [];
        $info = [];
        $perDo = []; // potongan uang jalan per DO di input ini (dua baris DO sama ikut dijumlah)
        foreach (array_values($d['baris']) as $i => $b) {
            $no = 'Baris '.($i + 1).': ';
            $nominal = (float) preg_replace('/\D/', '', $b['nominal']);
            $per = $b['peruntukan'];
            $ket = trim((string) ($b['keterangan'] ?? ''));
            $tahap = $b['tahap'] ?? null;
            if ($nominal <= 0) {
                return back()->withInput()->with('error', $no.'nominal belum diisi.');
            }
            if (! in_array($per, ['uj', ...UjPengalihan::BUKAN_BIAYA], true) && empty($tahap)) {
                return back()->withInput()->with('error', $no.'pilih proyek yang menanggung biaya ini.');
            }
            if ($per === 'hangus' && mb_strlen($ket) < 10) {
                return back()->withInput()->with('error', $no.'tidak kembali — tulis alasannya (min. 10 karakter), mis. "sopir kabur, tidak bisa dihubungi".');
            }
            if ($per === 'sebelum' && mb_strlen($ket) < 5) {
                return back()->withInput()->with('error', $no.'tulis kapan / bagaimana sudah dicicil (mis. "dipotong gaji Okt, catatan Bu Valen").');
            }
            $do = null;
            $mobil = $s['mobil'][0] ?? null;
            if ($per === 'uj') {
                $do = str_replace(['`', "'", ' '], '', trim((string) ($b['no_do'] ?? '')));
                if (! preg_match('/^\d{3,10}$/', $do)) {
                    return back()->withInput()->with('error', $no.'isi No DO uang jalannya (angka saja).');
                }
                $mobil = NomorMobil::rapikan($b['no_mobil'] ?? null) ?: $mobil;
                if (! $mobil) {
                    return back()->withInput()->with('error', $no.'isi No Mobil (DT) untuk uang jalan ini.');
                }
                // Tersambung lewat No DO: uang jalan DO ini di Kas UJ (transfer) + potongan utang lain → total per DO tetap dijaga.
                $k = SaldoSopir::kunciDo($do);
                $kasUj = UjDetail::where('biaya_transfer', false)->where('kategori', 'like', '%jalan%')->where('no_do', 'like', '%'.$k)->get(['id_uj', 'tanggal', 'nominal', 'no_mobil', 'no_do'])
                    ->filter(fn ($u) => SaldoSopir::kunciDo($u->no_do) === $k)->values();
                $dariUtang = UjPengalihan::where('jenis', 'pakai')->where('peruntukan', 'uj')->where('no_do', 'like', '%'.$k)->get()->filter(fn ($p) => SaldoSopir::kunciDo($p->no_do) === $k)->values();
                $perDo[$k] = ($perDo[$k] ?? 0) + $nominal;
                $total = (float) $kasUj->sum('nominal') + (float) $dariUtang->sum('nominal') + $perDo[$k];
                $sebut = $kasUj->map(fn ($u) => $u->id_uj.' '.rp((int) $u->nominal).' (Kas UJ)')->concat($dariUtang->map(fn ($p) => rp((int) $p->nominal).' utang '.($p->sopir ?: $p->nama)))->implode(', ');
                if ($total > \App\Support\ValidasiUj::MAKS_UJ_PER_DO) {
                    return back()->withInput()->with('error', $no."total uang jalan DO {$do} jadi ".rp((int) $total).' (lebih dari '.rp(\App\Support\ValidasiUj::MAKS_UJ_PER_DO).').'
                        .($sebut !== '' ? " Sudah ada: {$sebut}." : '').' Periksa No DO / nominalnya.');
                }
                $dtLain = $kasUj->map(fn ($u) => NomorMobil::rapikan($u->no_mobil))->filter()->unique()->reject(fn ($m) => $m === $mobil);
                if ($dtLain->isNotEmpty() && empty($b['tetap'])) {
                    return back()->withInput()->with('error', $no."DO {$do} di Kas UJ tercatat untuk ".$dtLain->implode(', ').", bukan {$mobil}. Periksa No DO; bila memang benar, centang \"Tetap simpan\" di baris itu.");
                }
                $info[] = $sebut !== '' ? "DO {$do} tersambung dengan {$sebut} — total uang jalan ".rp((int) $total).'.' : "DO {$do} sekarang tercatat punya uang jalan (dari utang sopir).";
            }
            $siap[] = ['jenis' => 'pakai', 'no_do' => $do, 'no_mobil' => $mobil, 'nama' => $sopir, 'sopir' => $sopir,
                'dari_tahap_id' => $per === 'hangus' ? $tahap : null, 'ke_tahap_id' => in_array($per, UjPengalihan::BUKAN_BIAYA, true) ? null : $tahap,
                'tanggal' => $b['tanggal'], 'nominal' => $nominal, 'peruntukan' => $per, 'kas_no_id' => $per === 'kembali' ? (trim((string) ($b['kas_no_id'] ?? '')) ?: null) : null,
                'keterangan' => $ket ?: null, 'user_id' => $request->user()->id];
        }
        $jumlah = array_sum(array_column($siap, 'nominal'));
        if (! $s || $jumlah > $sisa + 0.5) {
            return back()->withInput()->with('error', 'Total potongan '.rp((int) $jumlah)." melebihi sisa utang {$sopir} (".rp((int) $sisa).').');
        }
        DB::transaction(function () use ($siap, $sopir, $request) {
            foreach ($siap as $x) {
                $p = UjPengalihan::create($x);
                $proyek = $p->ke?->nama;
                KasRiwayat::create(['aksi' => 'uj-alih-pakai', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
                    'ringkasan' => mb_strimwidth("Utang sopir {$sopir} berkurang: ".UjPengalihan::PERUNTUKAN[$x['peruntukan']].($x['no_do'] ? " DO {$x['no_do']} ({$x['no_mobil']})" : '')
                        .' Rp '.number_format($x['nominal'], 0, ',', '.').($proyek ? " ({$proyek})" : '').($x['keterangan'] ? " — {$x['keterangan']}" : ''), 0, 490, '…'),
                    'isi' => $p->toArray(), 'user_id' => $request->user()->id]);
            }
        });

        return redirect()->route('pengalihan-uj.index', ['tab' => 'saldo', 'sopir' => $sopir])
            ->with('success', "Utang {$sopir} berkurang ".rp((int) $jumlah).' ('.count($siap).' baris).'.($info ? ' '.implode(' ', $info) : ''));
    }

    /**
     * ➕ Kasbon sopir dari Kas Harian, grid seperti Input UJ — satu baris = satu bon ber-Kode GL Piutang (NO ID) dengan bagian sopir ini
     * (satu bon boleh untuk beberapa sopir; total yang ditautkan tidak melebihi nominal bon).
     */
    public function kasbon(Request $request): RedirectResponse
    {
        $d = $request->validate(['sopir' => ['required', 'string', 'max:100'], 'baris' => ['required', 'array', 'min:1', 'max:50'],
            'baris.*.kas_no_id' => ['required', 'string', 'max:30'], 'baris.*.nominal' => ['required', 'string', 'max:20'], 'baris.*.keterangan' => ['nullable', 'string', 'max:300']],
            ['baris.required' => 'Isi minimal satu baris kasbon.', 'baris.*.kas_no_id.required' => 'Pilih bon kasbon di setiap baris.', 'baris.*.nominal.required' => 'Isi nominal di setiap baris.']);
        $sopir = SaldoSopir::kunci($d['sopir']);
        $bonSemua = self::bonKasbon()->keyBy('no_id');
        $pakaiBon = [];
        $siap = [];
        $mobil = \App\Support\DaftarSopir::cari($sopir)['mobil'] ?? (SaldoSopir::perSopir()[$sopir]['mobil'][0] ?? null);
        foreach (array_values($d['baris']) as $i => $b) {
            $no = 'Baris '.($i + 1).': ';
            $bon = $bonSemua[trim($b['kas_no_id'])] ?? null;
            if (! $sopir || ! $bon) {
                return back()->withInput()->with('error', $no.'bon itu tidak ditemukan di Kas Harian, atau Kode GL-nya bukan Piutang.');
            }
            $nominal = (float) preg_replace('/\D/', '', $b['nominal']);
            $pakaiBon[$bon->no_id] = ($pakaiBon[$bon->no_id] ?? 0) + $nominal;
            if ($nominal <= 0 || $pakaiBon[$bon->no_id] > $bon->sisa + 0.5) {
                return back()->withInput()->with('error', $no.'nominal harus 1 s/d sisa bon NO ID '.$bon->no_id.' yang belum ditautkan ('.rp((int) $bon->sisa).').');
            }
            $ket = trim((string) ($b['keterangan'] ?? ''));
            $siap[] = [$bon, ['jenis' => 'kasbon', 'no_mobil' => $mobil, 'nama' => $sopir, 'sopir' => $sopir, 'tanggal' => $bon->tanggal, 'nominal' => $nominal, 'kas_no_id' => $bon->no_id,
                'keterangan' => mb_strimwidth(trim($bon->keterangan.($ket ? ' · '.$ket : '')), 0, 300, '…'), 'user_id' => $request->user()->id]];
        }
        DB::transaction(function () use ($siap, $sopir, $request) {
            foreach ($siap as [$bon, $x]) {
                $p = UjPengalihan::create($x);
                KasRiwayat::create(['aksi' => 'uj-alih-kasbon', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
                    'ringkasan' => mb_strimwidth("Utang sopir {$sopir} bertambah: kasbon Rp ".number_format($x['nominal'], 0, ',', '.')." dari Kas Harian NO ID {$bon->no_id} ({$bon->keterangan})", 0, 490, '…'),
                    'isi' => $p->toArray(), 'user_id' => $request->user()->id]);
            }
        });
        $jumlah = array_sum(array_map(fn ($x) => $x[1]['nominal'], $siap));

        return redirect()->route('pengalihan-uj.index', ['tab' => 'saldo', 'sopir' => $sopir])
            ->with('success', 'Kasbon '.rp((int) $jumlah).' ('.count($siap)." bon) masuk utang {$sopir}.");
    }

    /** Bon Kas Harian ber-Kode GL kelompok Piutang (kasbon) sejak Mei 2026, dengan bagian yang sudah ditautkan ke sopir & sisanya. */
    public static function bonKasbon(): Collection
    {
        $tertaut = UjPengalihan::where('jenis', 'kasbon')->whereNotNull('kas_no_id')->selectRaw('kas_no_id, SUM(nominal) n')->groupBy('kas_no_id')->pluck('n', 'kas_no_id');

        return \App\Models\KasBon::query()->join('kode_gl as k', 'k.id', '=', 'kas_bon.kode_gl_id')->join('akun_gl as a', 'a.id', '=', 'k.akun_gl_id')
            ->join('kas_transfer as t', 't.id', '=', 'kas_bon.kas_transfer_id')
            ->where('a.kelompok', 'Piutang')->where('t.kredit', '>', 0)->where('kas_bon.tanggal', '>=', '2026-05-01')->whereNotNull('kas_bon.no_id')
            ->orderByDesc('kas_bon.tanggal')->orderByDesc('kas_bon.no_id')
            ->get(['kas_bon.no_id', 'kas_bon.tanggal', 'kas_bon.nominal', 'kas_bon.keterangan', 'kas_bon.pic', 'k.kode_asli', 't.nama_tujuan'])
            ->map(function ($b) use ($tertaut) {
                $b->tertaut = (float) ($tertaut[$b->no_id] ?? 0);
                $b->sisa = (float) $b->nominal - $b->tertaut;

                return $b;
            });
    }

    /** Ganti / gabungkan nama sopir (ejaan berbeda di Kas UJ, mis. "Andre" & "ANDRE S"): semua catatan saldo sopir lama pindah ke nama baru. */
    public function sopir(Request $request): RedirectResponse
    {
        $d = $request->validate(['lama' => ['required', 'string', 'max:100'], 'baru' => ['required', 'string', 'max:100']]);
        [$lama, $baru] = [SaldoSopir::kunci($d['lama']), SaldoSopir::kunci($d['baru'])];
        if ($lama === $baru) {
            return back();
        }
        $baris = UjPengalihan::whereIn('jenis', ['alih', 'kasbon', 'pakai'])->get()->filter(fn ($p) => SaldoSopir::kunci($p->sopir ?: $p->nama) === $lama);
        UjPengalihan::whereIn('id', $baris->pluck('id'))->update(['sopir' => $baru]);
        KasRiwayat::create(['aksi' => 'uj-alih-sopir', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Saldo sopir {$lama} dipindah ke nama {$baru} ({$baris->count()} catatan)", 'isi' => ['lama' => $lama, 'baru' => $baru, 'id' => $baris->pluck('id')],
            'user_id' => $request->user()->id]);

        return redirect()->route('pengalihan-uj.index', ['tab' => 'saldo'])->with('success', "Saldo {$lama} sekarang atas nama {$baru}.");
    }

    /**
     * Monitor Ritasi → tutup UJ belum bongkar satu DO (Kontrol UJ, owner 18 Okt 2026):
     *  - kembali : uang dikembalikan sopir (keluar dari biaya proyeknya);
     *  - batal   : tidak bongkar, uang sudah terpakai (mis. truk breakdown di jalan) — tetap biaya, alasan wajib.
     */
    public function tutup(Request $request): RedirectResponse
    {
        $d = $request->validate(['jenis' => ['required', 'in:kembali,batal'], 'uj' => ['required', 'array', 'min:1'], 'uj.*' => ['string', 'max:20'],
            'keterangan' => ['required', 'string', 'min:10', 'max:300']], ['keterangan.*' => 'Tulis alasannya (min. 10 karakter).', 'uj.required' => 'Tidak ada UJ yang dipilih.']);
        $uj = UjDetail::whereIn('id_uj', $d['uj'])->whereIn('kategori', KontrolUj::KATEGORI)->get();
        $sudah = UjPengalihan::whereIn('id_uj', $uj->pluck('id_uj'))->pluck('id_uj')->flip();
        $uj = $uj->reject(fn ($u) => isset($sudah[$u->id_uj]));
        if ($uj->isEmpty()) {
            return back()->with('error', 'UJ itu sudah dijadikan saldo / dikembalikan / dibatalkan sebelumnya.');
        }
        $tahap = KontrolUj::terbuka()->keyBy('do');
        DB::transaction(function () use ($uj, $d, $tahap, $request) {
            foreach ($uj as $u) {
                UjPengalihan::create(['jenis' => $d['jenis'], 'id_uj' => $u->id_uj, 'no_do' => $u->no_do ? ltrim(trim($u->no_do), "`'") : null, 'no_mobil' => NomorMobil::rapikan($u->no_mobil),
                    'nama' => $u->nama, 'sopir' => SaldoSopir::kunci($u->nama), 'dari_tahap_id' => $tahap[SaldoSopir::kunciDo($u->no_do)]['tahap_id'] ?? null, 'tanggal' => now()->toDateString(),
                    'nominal' => $u->nominal, 'keterangan' => $d['keterangan'], 'user_id' => $request->user()->id]);
            }
        });
        $label = $d['jenis'] === 'kembali' ? 'dikembalikan sopir' : 'dibatalkan (tidak bongkar)';
        KasRiwayat::create(['aksi' => 'uj-tutup', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($uj->count().' UJ '.$label.' (Rp '.number_format($uj->sum('nominal'), 0, ',', '.').'): '.$uj->pluck('id_uj')->implode(', ').' — '.$d['keterangan'], 0, 490, '…'),
            'isi' => ['uj' => $uj->pluck('id_uj'), 'jenis' => $d['jenis'], 'keterangan' => $d['keterangan']], 'user_id' => $request->user()->id]);

        return back()->with('success', $uj->count().' UJ ('.$uj->pluck('id_uj')->implode(', ').") {$label}.");
    }

    public function hapus(Request $request, UjPengalihan $pengalihan): RedirectResponse
    {
        if ($pengalihan->uj_pengajuan_detail_id) {
            return back()->with('error', 'Potongan ini dari Pengajuan UJ — ubah atau batalkan pengajuannya ('.($pengalihan->keterangan ? mb_strimwidth($pengalihan->keterangan, 0, 40, '…') : 'Pengajuan UJ').').');
        }
        if (in_array($pengalihan->jenis, UjPengalihan::NAMBAH, true)) {
            $s = SaldoSopir::perSopir()[SaldoSopir::kunci($pengalihan->sopir ?: $pengalihan->nama)] ?? null;
            $x = $s && $pengalihan->jenis === 'alih' ? $s['uj']->first(fn ($x) => $x->p->id === $pengalihan->id) : null;
            if ($s && ! $x?->bongkar && $s['saldo'] - $pengalihan->nominal < -0.5) {
                return back()->with('error', "Utang {$s['sopir']} sudah dipotong — tanpa catatan ini sisanya jadi minus. Hapus dulu potongannya.");
            }
        }
        KasRiwayat::create(['aksi' => 'uj-alih-hapus', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => 'Batal catatan pengalihan: '.UjPengalihan::JENIS[$pengalihan->jenis].' '.($pengalihan->id_uj ?? '').' '.($pengalihan->sopir ?: $pengalihan->nama).' Rp '.number_format($pengalihan->nominal, 0, ',', '.'),
            'isi' => $pengalihan->toArray(), 'user_id' => $request->user()->id]);
        $pengalihan->delete();

        return back()->with('success', 'Catatan pengalihan dihapus.');
    }
}
