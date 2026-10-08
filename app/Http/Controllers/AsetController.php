<?php

namespace App\Http\Controllers;

use App\Models\AsetTruk;
use App\Models\KasRiwayat;
use App\Support\CekTruk;
use App\Support\NomorMobil;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Data Aset: daftar truk milik MNP (data induk di aplikasi) + pencocokan dengan Ritasi & Kas UJ. */
class AsetController extends Controller
{
    public function index(Request $request): View
    {
        $status = array_key_exists($request->query('status'), AsetTruk::STATUS) ? $request->query('status') : null;
        $semua = AsetTruk::orderBy('no_lambung')->get();
        $temuan = CekTruk::temuan();

        return view('aset.index', [
            'truk' => $status ? $semua->where('status', $status)->values() : $semua,
            'semua' => $semua, 'status' => $status, 'statistik' => CekTruk::statistik(),
            'temuanPerTruk' => $temuan->countBy('mobil'), 'temuanPerKode' => $temuan->groupBy('kode'),
            'bolehUbah' => $request->user()->bolehMenu('aset'),
        ]);
    }

    public function show(AsetTruk $aset): View
    {
        $dt = $aset->no_lambung;

        return view('aset.show', [
            'aset' => $aset, 'statistik' => CekTruk::statistik()[$dt] ?? null,
            'temuan' => CekTruk::temuan()->where('mobil', $dt)->values(),
            'rit' => DB::table('ritasi')->where('no_lambung', $dt)->orderByDesc('tanggal')->orderByDesc('baris')->limit(15)->get(),
            'uj' => DB::table('uj_detail')->where('no_mobil', $dt)->orderByDesc('tanggal')->orderByDesc('baris')->limit(15)->get(),
            'ritPerBulan' => DB::table('ritasi')->where('no_lambung', $dt)->selectRaw("DATE_FORMAT(tanggal, '%Y-%m') b, COUNT(*) n")->groupBy('b')->orderBy('b')->pluck('n', 'b'),
            'ujPerBulan' => DB::table('uj_detail')->where('no_mobil', $dt)->where('biaya_transfer', 0)->selectRaw("DATE_FORMAT(tanggal, '%Y-%m') b, SUM(nominal) n")->groupBy('b')->orderBy('b')->pluck('n', 'b'),
        ]);
    }

    public function create(): View
    {
        return view('aset.form', ['aset' => new AsetTruk(['status' => 'aktif']), 'jenis' => $this->daftarJenis()]);
    }

    public function edit(AsetTruk $aset): View
    {
        return view('aset.form', ['aset' => $aset, 'jenis' => $this->daftarJenis()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $aset = AsetTruk::create([...$this->baca($request, null), 'user_id' => $request->user()->id]);
        $this->catat($request, 'aset-tambah', "Aset baru {$aset->no_lambung} {$aset->plat}", $aset->toArray());

        return redirect()->route('aset.show', $aset)->with('success', "Truk {$aset->no_lambung} ditambahkan ke Data Aset.");
    }

    public function update(Request $request, AsetTruk $aset): RedirectResponse
    {
        $lama = $aset->toArray();
        $aset->update([...$this->baca($request, $aset), 'user_id' => $request->user()->id]);
        $berubah = collect($aset->getChanges())->except(['updated_at', 'user_id'])->keys()->implode(', ');
        $this->catat($request, 'aset-ubah', "Aset {$aset->no_lambung} diubah: ".($berubah ?: 'tanpa perubahan'), ['sebelum' => $lama, 'sesudah' => $aset->fresh()->toArray()]);

        return redirect()->route('aset.show', $aset)->with('success', "Data {$aset->no_lambung} disimpan.");
    }

    /** Daftar semua temuan pencocokan, bisa disaring per jenis & per truk. */
    public function temuan(Request $request): View
    {
        $semua = CekTruk::temuan();
        $kode = array_key_exists($request->query('kode'), CekTruk::JENIS) ? $request->query('kode') : null;
        $mobil = trim((string) $request->query('mobil')) ?: null;

        return view('aset.temuan', [
            'temuan' => $semua->when($kode, fn ($c) => $c->where('kode', $kode))->when($mobil, fn ($c) => $c->where('mobil', $mobil))->values(),
            'perKode' => $semua->groupBy('kode'), 'kode' => $kode, 'mobil' => $mobil,
        ]);
    }

    public function temuanExcel(): BinaryFileResponse
    {
        $semua = CekTruk::temuan();
        $path = storage_path('app/cek-truk-'.now()->format('YmdHis').'.xlsx');
        $w = new Writer(new Options());
        $w->openToFile($path);
        $s = fn () => (new Style())->setFontSize(11);
        $kepala = $s()->setFontBold()->setBackgroundColor('BDD6EE')->setShouldWrapText()->setCellAlignment(CellAlignment::CENTER);
        $tgl = $s()->setFormat('dd/mmm/yyyy')->setCellAlignment(CellAlignment::CENTER);
        $angka = $s()->setFormat('#,##0');
        $w->getCurrentSheet()->setName('Temuan');
        $w->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(1));
        foreach ([6, 34, 12, 10, 26, 10, 14, 44, 16, 13, 70] as $i => $x) {
            $w->getCurrentSheet()->setColumnWidth($x, $i + 1);
        }
        $w->addRow(Row::fromValues(['Kode', 'Jenis temuan', 'Tanggal', 'No Mobil', 'Referensi', 'No DO', 'Nama/Driver', 'Keterangan', 'Kategori', 'Nominal', 'Rincian'], $kepala));
        foreach ($semua->sortBy(fn ($t) => $t['kode'].$t['tanggal'])->values() as $t) {
            $w->addRow(new Row([Cell::fromValue($t['kode']), Cell::fromValue(CekTruk::JENIS[$t['kode']][0]), Cell::fromValue(new \DateTimeImmutable($t['tanggal']), $tgl),
                Cell::fromValue((string) $t['mobil']), Cell::fromValue($t['ref']), Cell::fromValue((string) $t['do']), Cell::fromValue((string) $t['nama']),
                Cell::fromValue((string) $t['ket']), Cell::fromValue((string) $t['kategori']), Cell::fromValue($t['nominal'] ?: '', $angka), Cell::fromValue($t['rincian'])]));
        }
        $w->close();

        return response()->download($path, 'Cek Truk Ritasi vs Kas UJ '.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    private function baca(Request $request, ?AsetTruk $aset): array
    {
        $request->merge([
            'no_lambung' => NomorMobil::rapikan($request->input('no_lambung')),
            'plat' => ($p = trim(preg_replace('/\s+/', ' ', strtoupper((string) $request->input('plat'))))) === '' ? null : $p,
            'no_rangka' => strtoupper(trim((string) $request->input('no_rangka'))) ?: null,
            'no_mesin' => strtoupper(trim((string) $request->input('no_mesin'))) ?: null,
        ]);

        return $request->validate([
            'no_lambung' => ['required', 'regex:/^DT \d{3}$/', Rule::unique('aset_truk', 'no_lambung')->ignore($aset?->id)],
            'plat' => ['nullable', 'string', 'max:20'],
            'jenis' => ['nullable', 'string', 'max:40'],
            'tahun' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'no_rangka' => ['nullable', 'string', 'max:60'],
            'no_mesin' => ['nullable', 'string', 'max:60'],
            'stnk_berlaku' => ['nullable', 'date'],
            'kir_berlaku' => ['nullable', 'date'],
            'status' => ['required', Rule::in(array_keys(AsetTruk::STATUS))],
            'driver_tetap' => ['nullable', 'string', 'max:60'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ], [
            'no_lambung.regex' => 'No Lambung harus berformat "DT 000", mis. DT 026.',
            'no_lambung.unique' => 'No Lambung ini sudah terdaftar di Data Aset.',
        ]);
    }

    private function daftarJenis(): array
    {
        return AsetTruk::whereNotNull('jenis')->distinct()->orderBy('jenis')->pluck('jenis')->merge(['Faw', 'Mercy', 'Giga', 'Hino'])->unique()->values()->all();
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Aset', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
