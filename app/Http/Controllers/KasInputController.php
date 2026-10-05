<?php

namespace App\Http\Controllers;

use App\Models\AkunGl;
use App\Models\KasBon;
use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\KodeGl;
use App\Support\FotoBon;
use App\Support\GoogleSheets;
use App\Support\HapusKasSheet;
use App\Support\ImporKas;
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
            ->select('bank_tujuan', DB::raw('COUNT(*) as n'))->groupBy('bank_tujuan')->orderByDesc('n')->limit(8)->pluck('bank_tujuan');

        return view('kas.input', compact('kodeGl', 'pic', 'rekening', 'bank'));
    }

    /**
     * Semua rekening tujuan yang pernah dipakai (nama + bank + nomor), untuk saran dua arah di form.
     * Nomor yang sama setelah nol di depan dan tanda baca diabaikan digabung (sheet sering menghapus nol depan),
     * memakai tulisan yang paling sering dipakai.
     *
     * @return array<int, array{nama: string, bank: ?string, no_rek: string, kunci: string, dipakai: int, terakhir: string}>
     */
    private function daftarRekening(): array
    {
        return KasTransfer::whereNotNull('nama_tujuan')->whereNotNull('no_rek_tujuan')
            ->select('nama_tujuan', 'no_rek_tujuan', 'bank_tujuan', DB::raw('COUNT(*) as n'), DB::raw('MAX(tanggal) as terakhir'))
            ->groupBy('nama_tujuan', 'no_rek_tujuan', 'bank_tujuan')
            ->get()
            ->map(fn ($r) => [...$r->toArray(), 'kunci' => ltrim(preg_replace('/\D/', '', $r->no_rek_tujuan), '0')])
            ->filter(fn ($r) => $r['kunci'] !== '')
            ->groupBy(fn ($r) => mb_strtolower(trim($r['nama_tujuan'])).'|'.mb_strtolower(trim((string) $r['bank_tujuan'])).'|'.$r['kunci'])
            ->map(function ($varian) {
                $utama = $varian->sortByDesc(fn ($r) => [$r['n'], strlen(preg_replace('/\D/', '', $r['no_rek_tujuan']))])->first();

                return [
                    'nama' => trim($utama['nama_tujuan']),
                    'bank' => $utama['bank_tujuan'],
                    'no_rek' => preg_replace('/[^\d]/', '', $utama['no_rek_tujuan']),
                    'kunci' => $utama['kunci'],
                    'dipakai' => (int) $varian->sum('n'),
                    'terakhir' => Carbon::parse($varian->max('terakhir'))->translatedFormat('j M Y'),
                    'urut' => $varian->max('terakhir'),
                ];
            })
            ->sortByDesc('urut')
            ->map(fn ($r) => collect($r)->except('urut')->all())
            ->values()
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'arah' => ['required', 'in:keluar,masuk'],
            'nama_tujuan' => ['required', 'string', 'max:200'],
            'no_rek' => ['nullable', 'string', 'max:40'],
            'bank' => ['nullable', 'string', 'max:40'],
            'keterangan' => ['nullable', 'string', 'max:300'],
            'nominal_masuk' => ['required_if:arah,masuk', 'nullable', 'integer', 'min:1'],
            'nominal_transfer' => ['required_if:arah,keluar', 'nullable', 'integer', 'min:1'],
            'biaya_transfer' => ['nullable', 'boolean'],
            'bon' => ['required_if:arah,keluar', 'array'],
            'bon.*.nominal' => ['required', 'integer', 'min:1'],
            'bon.*.pic' => ['nullable', 'string', 'max:60'],
            'bon.*.keterangan' => ['required', 'string', 'max:300'],
            'bon.*.kode_gl' => ['required', 'string', 'max:120'],
            'foto' => ['nullable', 'array', 'max:6'],
            'foto.*' => KasFotoController::ATURAN['foto.*'],
        ], [
            ...KasFotoController::PESAN,
            'bon.required_if' => 'Isi minimal satu bon untuk transfer keluar.',
            'bon.*.keterangan.required' => 'Keterangan setiap bon wajib diisi.',
            'bon.*.kode_gl.required' => 'Kode GL setiap bon wajib diisi.',
            'bon.*.nominal.required' => 'Nominal setiap bon wajib diisi.',
            'nominal_transfer.required_if' => 'Nominal transfer wajib diisi.',
        ]);

        // Jumlah bon wajib sama persis dengan nominal transfer; bila tidak, transaksi ditolak dan tidak ditulis ke sheet.
        if ($data['arah'] === 'keluar') {
            $jumlahBon = array_sum(array_map(fn ($b) => (int) $b['nominal'], $data['bon']));
            $nominal = (int) $data['nominal_transfer'];
            if ($jumlahBon !== $nominal) {
                return back()->withInput()->withErrors(['nominal_transfer' => 'Ditolak: jumlah bon '.rp($jumlahBon).' tidak sama dengan nominal transfer '.rp($nominal)
                    .' (selisih '.rp(abs($nominal - $jumlahBon)).'). Transaksi tidak ditulis ke sheet.']);
            }
        }

        $tanggal = Carbon::parse($data['tanggal']);
        $input = [
            'tanggal' => $tanggal,
            'arah' => $data['arah'],
            'nama_tujuan' => $data['nama_tujuan'],
            'no_rek' => $data['no_rek'] ?? null,
            'bank' => $data['bank'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
            'nominal_masuk' => (int) ($data['nominal_masuk'] ?? 0),
            'nominal_transfer' => $data['arah'] === 'keluar' ? (int) $data['nominal_transfer'] : null,
            'bon' => $data['arah'] === 'keluar' ? array_values($data['bon']) : [],
            'biaya_transfer' => $data['arah'] === 'keluar' && $request->boolean('biaya_transfer'),
        ];

        try {
            $hasil = (new TulisKasSheet(GoogleSheets::wajib()))->tulis($input);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Gagal menulis ke sheet: '.$e->getMessage());
        }

        // Foto bon ditautkan ke NO ID baris transfer (baris pertama yang ditulis).
        $jumlahFoto = 0;
        foreach ($request->file('foto', []) as $file) {
            FotoBon::simpan($file, $hasil['no_id'][0], $hasil['lembar'], $request->user()->id);
            $jumlahFoto++;
        }

        $nilai = $input['arah'] === 'masuk' ? $input['nominal_masuk'] : array_sum(array_column($input['bon'], 'nominal'));
        KasRiwayat::create([
            'aksi' => 'tambah', 'lembar' => $hasil['lembar'], 'baris_awal' => $hasil['baris_awal'], 'baris_akhir' => $hasil['baris_akhir'],
            'ringkasan' => ($input['arah'] === 'masuk' ? 'Uang masuk ' : 'Transfer ').rp($nilai).' '.$tanggal->translatedFormat('j M Y').' '
                .trim($input['nama_tujuan'].' — '.$input['keterangan'], ' —').' ('.count($input['bon']).' bon)',
            'isi' => ['input' => [...$input, 'tanggal' => $tanggal->toDateString()], 'no_id' => $hasil['no_id']],
            'user_id' => $request->user()->id,
        ]);
        // Dicek sebelum impor ulang, karena impor itulah yang membuat akun barunya.
        $akunBaru = collect($input['bon'])->pluck('kode_gl')
            ->map(fn ($k) => UraiKodeGl::urai($k)['akun'])->filter()
            ->reject(fn ($a) => AkunGl::where('nama', $a)->exists())->unique();
        $pesanImpor = $this->imporUlang($hasil['lembar']);

        return redirect()->route('kas.index', ['lembar' => $hasil['lembar'], 'tgl' => $tanggal->day])->with(
            $pesanImpor ? 'error' : 'success',
            ($input['arah'] === 'masuk' ? 'Uang masuk ' : 'Transfer ').rp($nilai).' tersimpan di sheet lembar '.$hasil['lembar']
            .' baris '.$hasil['baris_awal'].($hasil['baris_akhir'] > $hasil['baris_awal'] ? '–'.$hasil['baris_akhir'] : '')
            .' (NO ID '.reset($hasil['no_id']).(count($hasil['no_id']) > 1 ?'–'.end($hasil['no_id']) : '').').'
            .($akunBaru->isNotEmpty() ? ' Akun baru: '.$akunBaru->implode(', ').'.' : '')
            .($jumlahFoto ? " {$jumlahFoto} foto bon terlampir." : '')
            .$pesanImpor
        );
    }

    /** Hapus transfer + seluruh bonnya dari sheet (opsional beserta baris biaya transfernya), lalu impor ulang lembarnya. */
    public function hapus(Request $request, KasTransfer $transfer): RedirectResponse
    {
        $transfer->load('bon', 'kasBulan');
        $lembar = $transfer->kasBulan->lembar;
        $ringkasan = ($transfer->debet ? 'Uang masuk ' : 'Transfer ').rp($transfer->debet ?: $transfer->kredit)
            .' '.$transfer->tanggal->translatedFormat('j M Y').' '.trim($transfer->nama_tujuan.' — '.$transfer->keterangan, ' —')
            .' ('.$transfer->bon->count().' bon)';

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

    /** @return string kosong bila berhasil, atau pesan kegagalan untuk ditampilkan */
    private function imporUlang(string $lembar): string
    {
        try {
            (new ImporKas)->simpan(ImporKas::baca(ImporKas::ambilDariSheet([$lembar])));

            return '';
        } catch (\Throwable $e) {
            report($e);

            return ' Sheet sudah diubah, tetapi impor ulang ke aplikasi gagal ('.$e->getMessage().'); klik "Sinkron dari sheet".';
        }
    }

    /** Impor ulang satu lembar dari sheet (setelah admin mengubah sheet langsung). */
    public function sinkron(Request $request): RedirectResponse
    {
        $lembar = $request->validate(['lembar' => ['required', 'regex:/^\d{4}$/']])['lembar'];
        try {
            $hasil = ImporKas::baca(ImporKas::ambilDariSheet([$lembar]));
            (new ImporKas)->simpan($hasil);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Sinkron gagal: '.$e->getMessage());
        }
        $h = reset($hasil);

        return back()->with('success', "Lembar {$lembar} disinkronkan dari sheet: ".count($h['transfer']).' transfer, '.$h['jumlah_bon'].' bon, saldo akhir '.rp($h['saldo_akhir']).'.');
    }
}
