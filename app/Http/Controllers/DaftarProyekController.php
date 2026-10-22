<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\Proyek;
use App\Models\ProyekAlias;
use App\Models\ProyekTahap;
use App\Support\DaftarProyek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Daftar Proyek: proyek → tahap → alias (nama lama di Ritasi/BBM/Cost Project/Kas). Ringkasan ritasi & Kas Harian per tahap
 * dihitung langsung dari data; nama yang belum terpetakan bisa langsung dipetakan. Data keuangan hanya untuk Super Admin.
 */
class DaftarProyekController extends Controller
{
    /**
     * Grid seperti sheet Cost Project: semua tahap rata (tidak dikelompokkan), urut tanggal mulai. Tanggal mulai/akhir =
     * ritasi pertama/terakhir (tahap tanpa ritasi: dari BBM / Kas Harian). Biaya: ritasi & ngepok (Harga Jual Ritasi),
     * solar (liter × tarif BBM), rental (jam HM × tarif rental), operasional Kas Harian.
     */
    public function index(Request $request): View
    {
        $ritasi = DaftarProyek::ritasi();
        $kas = DaftarProyek::kas();
        $bbm = DaftarProyek::bbm($request->boolean('segarkan'));

        $baris = ProyekTahap::with('proyek')->withCount('alias')->get()->map(function (ProyekTahap $t) use ($ritasi, $kas, $bbm) {
            $r = $ritasi['per_tahap'][$t->id] ?? [];
            $k = $kas['per_tahap'][$t->id] ?? [];
            $b = $bbm['per_tahap'][$t->id] ?? [];
            [$mulai, $akhir] = isset($r['mulai']) ? [$r['mulai'], $r['selesai']]
                : (isset($b['mulai']) ? [$b['mulai'], $b['selesai']] : [$k['mulai'] ?? null, $k['selesai'] ?? null]);
            $c = DaftarProyek::rincian($t, $ritasi, $kas, $bbm);

            return (object) ['t' => $t, 'mulai' => $mulai, 'akhir' => $akhir, 'biaya' => $c, 'total' => array_sum(array_column($c, 1))];
        })->sortBy(fn ($x) => [$x->mulai ?? '9999', $x->t->nama])->values();

        // Filter bawaan dari menu Pengaturan Daftar Proyek (status, tipe, cost center, periode); ?semua=1 mengabaikannya.
        $atur = DaftarProyek::pengaturan();
        $pakaiAtur = ! $request->boolean('semua');
        if ($pakaiAtur) {
            $baris = $baris->filter(fn ($x) => in_array($x->t->status, $atur['status'], true) && in_array($x->t->tipe, $atur['tipe'], true)
                && (! $atur['cost_center'] || in_array($x->t->costCenter() ?? '-', $atur['cost_center'], true)))->values();
        }

        // Tahap "berjalan" di suatu tahun: periode mulai–akhir menyentuh tahun itu; tahap belum ada data ikut bila Belum mulai / Progress.
        $daftarTahun = range((int) now()->year, (int) substr($baris->min('mulai') ?? now()->year, 0, 4));
        $berjalan = fn ($x, int $th) => $x->mulai ? (int) substr($x->mulai, 0, 4) <= $th && (int) substr($x->akhir, 0, 4) >= $th
            : in_array($x->t->status, ['belum', 'progress'], true);
        $jumlahTahun = collect($daftarTahun)->mapWithKeys(fn ($th) => [$th => $baris->filter(fn ($x) => $berjalan($x, $th))->count()]);
        // Tahun: pilihan chip, atau bawaan dari pengaturan (tahun berjalan / + tahun lalu / semua).
        $pilih = $request->query('tahun');
        $tahun = match (true) {
            $pilih === 'semua' => [],
            in_array((int) $pilih, $daftarTahun, true) => [(int) $pilih],
            ! $pakaiAtur, $atur['periode'] === 'tahun_ini' => [(int) now()->year],
            $atur['periode'] === 'dua_tahun' => [(int) now()->year, (int) now()->year - 1],
            default => [],
        };

        return view('proyek.index', [
            'baris' => $tahun ? $baris->filter(fn ($x) => collect($tahun)->contains(fn ($th) => $berjalan($x, $th)))->values() : $baris,
            'tahun' => $tahun, 'jumlahTahun' => $jumlahTahun, 'jumlahSemua' => $baris->count(),
            'atur' => $atur, 'pakaiAtur' => $pakaiAtur,
            'lepasRitasi' => $ritasi['lepas'], 'lepasKas' => $kas['lepas'], 'lepasBbm' => $bbm['lepas'], 'waktuBbm' => $bbm['waktu'],
            'semuaTahap' => ProyekTahap::with('proyek')->get()->sortBy(fn ($t) => [$t->proyek->urutan, (int) preg_replace('/\D/', '', $t->nama) ?: 99999, $t->nama]),
            'super' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function pengaturan(): View
    {
        return view('proyek.pengaturan', [
            'atur' => DaftarProyek::pengaturan(),
            'costCenter' => \App\Models\CostCenter::orderBy('kode')->pluck('kode'),
        ]);
    }

    public function simpanPengaturan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'periode' => ['required', Rule::in(array_keys(DaftarProyek::PERIODE))],
            'status' => ['required', 'array', 'min:1'], 'status.*' => [Rule::in(array_keys(ProyekTahap::STATUS))],
            'tipe' => ['required', 'array', 'min:1'], 'tipe.*' => [Rule::in(array_keys(ProyekTahap::TIPE))],
            'cost_center' => ['nullable', 'array'], 'cost_center.*' => ['string', 'max:20'],
        ], ['status.required' => 'Pilih minimal satu status.', 'tipe.required' => 'Pilih minimal satu tipe.']);
        // Semua cost center dicentang = tanpa batasan, supaya cost center baru nanti ikut tampil.
        $pilihCc = array_values($d['cost_center'] ?? []);
        $semuaCc = \App\Models\CostCenter::pluck('kode')->push('-')->all();
        $d['cost_center'] = array_diff($semuaCc, $pilihCc) ? $pilihCc : [];
        $lama = DaftarProyek::pengaturan();
        \App\Models\PengaturanApp::simpan(DaftarProyek::KUNCI_PENGATURAN, json_encode($d + ['tipe_infra' => true]) /* tanda: disimpan setelah ada tipe Infra */, $request->user()->id);
        $this->catat($request, 'proyek-ubah', 'Pengaturan filter bawaan Daftar Proyek diubah', ['sebelum' => $lama, 'sesudah' => $d]);

        return redirect()->route('pengaturan-proyek')->with('success', 'Pengaturan Daftar Proyek disimpan.');
    }

    /** Satu halaman per tahap: form edit + alias, lalu transaksi detail Kas Harian yang terpetakan ke tahap ini (aturan sama dengan ringkasan grid). */
    public function show(Request $request, ProyekTahap $tahap): View
    {
        $transaksi = DaftarProyek::queryKas()
            ->select('b.no_id', 'b.tanggal', 'b.nominal', 'b.pic', 'b.keterangan', 'k.kode_asli', 'c.kode as cc', 'k.ref', 'a.nama as akun', 'al.proyek_tahap_id as alokasi')
            ->orderByDesc('b.tanggal')->orderByDesc('b.no_id')->get()
            ->filter(fn ($r) => ($r->alokasi ?: DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10))) === $tahap->id)->values();

        return view('proyek.show', [
            'tahap' => $tahap->load('proyek', 'alias'),
            'proyek' => Proyek::orderBy('urutan')->get(),
            'transaksi' => $transaksi,
            'perAkun' => $transaksi->groupBy(fn ($r) => $r->akun ?? '(tanpa akun)')->map(fn ($g) => ['n' => $g->count(), 'rp' => $g->sum('nominal')])->sortByDesc('rp'),
            'super' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function create(Request $request): View
    {
        $t = new ProyekTahap(['tipe' => 'luas', 'status' => 'progress', 'proyek_id' => $request->integer('proyek') ?: null] + ProyekTahap::BAWAAN);

        return view('proyek.form', ['tahap' => $t, 'proyek' => Proyek::orderBy('urutan')->get(), 'super' => $request->user()->isSuperAdmin()]);
    }

    /** Edit kini satu halaman dengan detail tahap. */
    public function edit(ProyekTahap $tahap): RedirectResponse
    {
        return redirect()->route('proyek.show', $tahap);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->baca($request, null);
        $tahap = ProyekTahap::create([...$data, 'user_id' => $request->user()->id]);
        // Nama baku langsung jadi alias di Ritasi & BBM supaya input dengan nama ini otomatis terpetakan.
        foreach (['ritasi', 'bbm'] as $sumber) {
            ProyekAlias::firstOrCreate(['sumber' => $sumber, 'kunci' => ProyekAlias::kunci($tahap->nama)], ['proyek_tahap_id' => $tahap->id, 'nama' => $tahap->nama]);
        }
        $this->catat($request, 'proyek-tambah', "Tahap baru {$tahap->nama} ({$tahap->proyek->nama})", $tahap->toArray());

        return redirect()->route('proyek.show', $tahap)->with('success', "Tahap {$tahap->nama} ditambahkan.");
    }

    public function update(Request $request, ProyekTahap $tahap): RedirectResponse
    {
        $lama = $tahap->toArray();
        $tahap->update([...$this->baca($request, $tahap), 'user_id' => $request->user()->id]);
        $berubah = collect($tahap->getChanges())->except(['updated_at', 'user_id'])->keys()->implode(', ');
        $this->catat($request, 'proyek-ubah', "Tahap {$tahap->nama} diubah: ".($berubah ?: 'tanpa perubahan'), ['sebelum' => $lama, 'sesudah' => $tahap->fresh()->toArray()]);
        // Tahap dipindah ke induk lain: induk lama yang jadi kosong (tanpa tahap) dihapus supaya tidak tersisa di daftar.
        if ($lama['proyek_id'] !== $tahap->proyek_id && ($induk = Proyek::find($lama['proyek_id'])) && ! ProyekTahap::where('proyek_id', $induk->id)->exists()) {
            $this->catat($request, 'proyek-ubah', "Proyek induk {$induk->nama} dihapus (tidak ada tahap lagi)", $induk->toArray());
            $induk->delete();
        }

        return redirect()->route('proyek.show', $tahap)->with('success', "Tahap {$tahap->nama} disimpan.");
    }

    /** Petakan nama dari Ritasi/BBM/Kas ke sebuah tahap (dari panel "belum terpetakan" atau form tahap). */
    public function petakan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'sumber' => ['required', Rule::in(array_keys(ProyekAlias::SUMBER))],
            'nama' => ['required', 'string', 'max:200'],
            'tahap_id' => ['required', 'integer', 'exists:proyek_tahap,id'],
        ], ['tahap_id.required' => 'Pilih tahapnya dulu.']);
        $kunci = ProyekAlias::kunci($d['nama']);
        if ($ada = ProyekAlias::with('tahap')->where('sumber', $d['sumber'])->where('kunci', $kunci)->first()) {
            return back()->with('error', "\"{$d['nama']}\" sudah dipetakan ke {$ada->tahap->nama}.");
        }
        $a = ProyekAlias::create(['proyek_tahap_id' => $d['tahap_id'], 'sumber' => $d['sumber'], 'nama' => trim(preg_replace('/\s+/', ' ', $d['nama'])), 'kunci' => $kunci]);
        $this->catat($request, 'proyek-alias', ProyekAlias::SUMBER[$a->sumber].": \"{$a->nama}\" dipetakan ke {$a->tahap->nama}", $a->toArray());

        return back()->with('success', "\"{$a->nama}\" ({$d['sumber']}) dipetakan ke {$a->tahap->nama}.");
    }

    public function hapusAlias(Request $request, ProyekAlias $alias): RedirectResponse
    {
        $this->catat($request, 'proyek-alias', ProyekAlias::SUMBER[$alias->sumber].": pemetaan \"{$alias->nama}\" → {$alias->tahap->nama} dihapus", $alias->toArray());
        $alias->delete();

        return back()->with('success', "Pemetaan \"{$alias->nama}\" dihapus.");
    }

    private function baca(Request $request, ?ProyekTahap $tahap): array
    {
        // "2.551,75" (format Indonesia) & "3.5" (desimal titik) sama-sama diterima; "139.000" = 139000.
        $angka = function ($v) {
            $v = str_replace(' ', '', trim((string) $v));
            if ($v === '') return null;
            if (str_contains($v, ',')) return str_replace(['.', ','], ['', '.'], $v);

            return preg_match('/^\d+\.\d{1,2}$/', $v) ? $v : str_replace('.', '', $v);
        };
        // Induk: pilih dari daftar, ATAU "+ Proyek induk baru…" lalu isi namanya — tidak bisa keduanya.
        $request->merge($request->input('proyek_id') === 'baru' ? ['proyek_id' => null] : ['proyek_baru' => null]);
        // "+ Cost center baru…": dibuat di master cost center (owner 22 Okt 2026), lalu dipakai tahap ini.
        if ($request->input('cost_center') === 'baru') {
            $ccBaru = trim(preg_replace('/\s+/', ' ', (string) $request->input('cost_center_baru')));
            $request->merge(['cost_center_baru' => $ccBaru]);
            $request->validate(['cost_center_baru' => ['required', 'string', 'max:20', 'regex:/^[\pL\d][\pL\d .\-]*$/u']],
                ['cost_center_baru.required' => 'Isi nama cost center baru.', 'cost_center_baru.regex' => 'Nama cost center hanya huruf, angka, spasi, titik atau tanda hubung.']);
            $ada = \App\Models\CostCenter::whereRaw('LOWER(kode) = ?', [mb_strtolower($ccBaru)])->first();
            $request->merge(['cost_center' => ($ada ?? \App\Models\CostCenter::create(['kode' => $ccBaru, 'nama' => null]))->kode]);
        }
        $request->merge(collect(['volume', 'luas', 'harga_m3', 'omset', 'tarif_bbm', 'tarif_rental', 'komisi_lapangan', 'komisi_yosua', 'komisi_verlix'])
            ->mapWithKeys(fn ($k) => [$k => $angka($request->input($k))])->all()
            + ['nama' => trim(preg_replace('/\s+/', ' ', (string) $request->input('nama')))]
            // Tanggal dari isian dd-MMM-yyyy (mis. 15-Agu-2025).
            + collect(['bayar_lapangan', 'bayar_yosua', 'bayar_verlix'])->mapWithKeys(fn ($k) => [$k => ($v = trim((string) $request->input($k))) === '' ? null
                : (RitasiController::tanggal($v)?->toDateString() ?? $v)])->all());
        $aturan = [
            'proyek_id' => ['nullable', 'integer', 'exists:proyek,id', 'required_without:proyek_baru'],
            'proyek_baru' => ['nullable', 'string', 'max:100', 'required_without:proyek_id'],
            'nama' => ['required', 'string', 'max:150', Rule::unique('proyek_tahap', 'nama')->ignore($tahap?->id)],
            'tipe' => ['required', Rule::in(array_keys(ProyekTahap::TIPE))],
            'status' => ['required', Rule::in(array_keys(ProyekTahap::STATUS))],
            'surveyor' => ['nullable', 'string', 'max:100'],
            'lokasi' => ['nullable', 'string', 'max:200'],
            'cost_center' => ['nullable', Rule::exists('cost_center', 'kode')],
            'volume' => ['nullable', 'numeric', 'min:0'],
            'luas' => ['nullable', 'numeric', 'min:0'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ];
        // Data keuangan hanya diisi/diubah Super Admin.
        if ($request->user()->isSuperAdmin()) {
            $aturan += [
                'harga_m3' => ['nullable', 'integer', 'min:0'], 'omset' => ['nullable', 'integer', 'min:0'],
                'tarif_bbm' => ['nullable', 'integer', 'min:0'], 'tarif_rental' => ['nullable', 'integer', 'min:0'],
                'komisi_lapangan' => ['nullable', 'numeric', 'between:0,100'], 'komisi_yosua' => ['nullable', 'numeric', 'between:0,100'],
                'komisi_verlix' => ['nullable', 'numeric', 'between:0,100'],
                'bayar_lapangan' => ['nullable', 'date'], 'bayar_yosua' => ['nullable', 'date'], 'bayar_verlix' => ['nullable', 'date'],
            ];
        }
        $d = $request->validate($aturan, [
            'nama.unique' => 'Nama tahap ini sudah ada di Daftar Proyek.',
            'proyek_id.required_without' => 'Pilih proyek induknya, atau isi nama proyek baru.',
            'bayar_lapangan.date' => 'Tanggal bayar komisi lapangan belum benar (contoh 15-Agu-2025).',
            'bayar_yosua.date' => 'Tanggal bayar komisi Yosua belum benar (contoh 15-Agu-2025).',
            'bayar_verlix.date' => 'Tanggal bayar komisi Verlix belum benar (contoh 15-Agu-2025).',
            '*.numeric' => 'Isian angka belum benar.', '*.integer' => 'Isian angka belum benar.',
        ]);
        if (! empty($d['proyek_baru'])) {
            $d['proyek_id'] = Proyek::firstOrCreate(['nama' => trim($d['proyek_baru'])], ['urutan' => (int) Proyek::max('urutan') + 1])->id;
        }
        unset($d['proyek_baru']);

        return $d;
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Proyek', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
