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
        $semua = UjPengajuan::with(['detail', 'user', 'transfer'])->orderByDesc('tanggal')->orderByDesc('id')->get();
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
                default => $semua->filter($terbuka)->merge($semua->reject($terbuka)->take(50))->values(),
            },
            'semua' => $semua, 'status' => $status, 'beda' => $beda, 'detailBeda' => $detailBeda, 'kasKini' => $kasKini, 'kelompokTf' => $kelompokTf,
            'menunggu' => UjPengajuanDetail::where('status', 'menunggu')->selectRaw('COUNT(*) n, SUM(nominal) s')->first(),
            'bolehRealisasi' => $request->user()->bolehMenu('input-uj'),
            // Menautkan transfer Kas Harian ke pengajuan hanya untuk Super Admin (rutenya juga dijaga can:super-admin).
            'bolehTautkan' => $request->user()->isSuperAdmin(),
        ]);
    }

    /** Mengajukan lagi DO yang sudah diajukan memang dobel — tidak ditawari tautan. */
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
        $p = DB::transaction(function () use ($input, $temuan, $request) {
            // Pengajuan = daftar transaksi (tanpa penerima/rekening); nominal = jumlah detail.
            $p = UjPengajuan::create(['tanggal' => $input['tanggal']->toDateString(), 'nama' => null, 'bank' => null, 'rekening' => null,
                'nominal' => $input['nominal'], 'status' => 'diajukan', 'user_id' => $request->user()->id]);
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
        $edit = [
            'pengajuan_id' => $pengajuan->id, 'kode' => $pengajuan->kode(), 'tanggal' => $pengajuan->tanggal->toDateString(),
            'nominal' => $pengajuan->nominal, 'biaya_transfer' => '0',
            'detail' => $pengajuan->detail->map(fn ($d) => $d->only(['nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do', 'konfirmasi']))->all(),
        ];

        return $this->create()->with(['mode' => 'pengajuan', 'edit' => $edit]);
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
        $temuan = $this->wajibKonfirmasi($input, null, $pengajuan->detail->pluck('id')->all());
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        DB::transaction(function () use ($pengajuan, $input, $temuan) {
            $pengajuan->update(['tanggal' => $input['tanggal']->toDateString(), 'nominal' => $input['nominal']]);
            $pengajuan->detail()->delete();
            $this->simpanDetail($pengajuan, $input, $temuan);
        });
        KasRiwayat::create(['aksi' => 'uj-ajukan-ubah', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($pengajuan->kode().' diubah: '.$this->ringkas($pengajuan->fresh()), 0, 490, '…'), 'isi' => ['pengajuan_id' => $pengajuan->id], 'user_id' => $request->user()->id]);

        return redirect()->route('pengajuan-uj.daftar')->with('success', "Pengajuan {$pengajuan->kode()} diperbarui.");
    }

    /** Batalkan detail yang masih menunggu (detail yang sudah terealisasi tidak berubah). */
    public function batal(Request $request, UjPengajuan $pengajuan): RedirectResponse
    {
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
        $kode = $pj->filter(fn ($p) => $baru->contains('uj_pengajuan_id', $p->id))->map->kode()->implode(', ');
        $ringkas = $baru->unique('kas_no_id')->map(fn ($x) => $x->idKas().' '.rp($x->nominal))->implode(', ');
        KasRiwayat::create(['aksi' => 'uj-ajukan-transfer', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($kode.' ← transfer Kas Harian '.$ringkas, 0, 490, '…'),
            'isi' => ['pengajuan_id' => $pj->pluck('id')->all(), 'kas_no_id' => $baru->pluck('kas_no_id')->unique()->values()->all()], 'user_id' => $request->user()->id]);

        return back()->with('success', "{$kode} ditautkan ke transfer Kas Harian: {$ringkas}.");
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

    private function simpanDetail(UjPengajuan $p, array $input, array $temuan): void
    {
        foreach ($input['detail'] as $i => $d) {
            UjPengajuanDetail::create([
                'uj_pengajuan_id' => $p->id, 'urut' => $i + 1, 'nama' => $d['nama'], 'keterangan' => $d['keterangan'], 'nominal' => $d['nominal'],
                'kategori' => $d['kategori'], 'jenis_kendaraan' => $d['jenis_kendaraan'], 'no_mobil' => $d['no_mobil'], 'no_do' => $d['no_do'],
                'konfirmasi' => $d['konfirmasi'], 'temuan' => $temuan[$i] ?? null, 'status' => 'menunggu',
            ]);
        }
    }

    private function tolakUbah(UjPengajuan $p): ?string
    {
        return $p->detail()->where('status', '!=', 'menunggu')->exists()
            ? "Pengajuan {$p->kode()} sudah (sebagian) direalisasikan atau dibatalkan — tidak bisa diubah. Batalkan sisanya lalu ajukan ulang bila perlu."
            : null;
    }

    private function ringkas(UjPengajuan $p): string
    {
        $d = $p->detail()->get();

        return rp($p->nominal).' '.$p->tanggal->translatedFormat('j M Y').' ('.$d->count().' transaksi · '.$d->pluck('nama')->filter()->unique()->implode(', ').')';
    }
}
