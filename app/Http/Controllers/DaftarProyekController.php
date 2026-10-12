<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\Proyek;
use App\Models\ProyekAlias;
use App\Models\ProyekTahap;
use App\Support\DaftarProyek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Daftar Proyek: proyek → tahap → alias (nama lama di Ritasi/BBM/Cost Project/Kas). Ringkasan ritasi & Kas Harian per tahap
 * dihitung langsung dari data; nama yang belum terpetakan bisa langsung dipetakan. Data keuangan hanya untuk Super Admin.
 */
class DaftarProyekController extends Controller
{
    public function index(Request $request): View
    {
        $status = array_key_exists($request->query('status'), ProyekTahap::STATUS) ? $request->query('status') : null;
        $cari = trim((string) $request->query('q', ''));
        $proyek = Proyek::with(['tahap' => fn ($q) => $q->withCount('alias')])->orderBy('urutan')->orderBy('nama')->get();
        $ritasi = DaftarProyek::ritasi();
        $kas = DaftarProyek::kas();

        $proyek->each(function (Proyek $p) use ($status, $cari) {
            $p->setRelation('tahap', $p->tahap
                ->when($status, fn ($c) => $c->where('status', $status))
                ->when($cari !== '', fn ($c) => $c->filter(fn ($t) => str_contains(mb_strtolower($t->nama.' '.$t->lokasi.' '.$p->nama), mb_strtolower($cari))))
                ->sortBy(fn ($t) => [(int) preg_replace('/\D/', '', $t->nama) ?: 99999, $t->nama])->values());
        });

        return view('proyek.index', [
            'proyek' => $proyek->filter(fn ($p) => $p->tahap->isNotEmpty() || (! $status && $cari === '')),
            'ritasi' => $ritasi['per_tahap'], 'kas' => $kas['per_tahap'],
            'lepasRitasi' => $ritasi['lepas'], 'lepasKas' => $kas['lepas'],
            'semuaTahap' => ProyekTahap::with('proyek')->get()->sortBy(fn ($t) => [$t->proyek->urutan, (int) preg_replace('/\D/', '', $t->nama) ?: 99999, $t->nama]),
            'jumlahStatus' => ProyekTahap::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'status' => $status, 'cari' => $cari,
            'super' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function create(Request $request): View
    {
        $t = new ProyekTahap(['tipe' => 'luas', 'status' => 'progress', 'proyek_id' => $request->integer('proyek') ?: null] + ProyekTahap::BAWAAN);

        return view('proyek.form', ['tahap' => $t, 'proyek' => Proyek::orderBy('urutan')->get(), 'super' => $request->user()->isSuperAdmin()]);
    }

    public function edit(Request $request, ProyekTahap $tahap): View
    {
        return view('proyek.form', ['tahap' => $tahap->load('alias'), 'proyek' => Proyek::orderBy('urutan')->get(), 'super' => $request->user()->isSuperAdmin()]);
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

        return redirect()->route('proyek.edit', $tahap)->with('success', "Tahap {$tahap->nama} ditambahkan.");
    }

    public function update(Request $request, ProyekTahap $tahap): RedirectResponse
    {
        $lama = $tahap->toArray();
        $tahap->update([...$this->baca($request, $tahap), 'user_id' => $request->user()->id]);
        $berubah = collect($tahap->getChanges())->except(['updated_at', 'user_id'])->keys()->implode(', ');
        $this->catat($request, 'proyek-ubah', "Tahap {$tahap->nama} diubah: ".($berubah ?: 'tanpa perubahan'), ['sebelum' => $lama, 'sesudah' => $tahap->fresh()->toArray()]);

        return redirect()->route('proyek.index', ['q' => $tahap->nama])->with('success', "Tahap {$tahap->nama} disimpan.");
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
            'lokasi' => ['nullable', 'string', 'max:200'],
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
