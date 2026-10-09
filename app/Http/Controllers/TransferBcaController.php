<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\PengaturanApp;
use App\Models\TransferBca;
use App\Models\UjTransaksi;
use App\Support\BankBca;
use App\Support\DaftarBank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Transfer Massal BCA: admin cukup mengisi penerima (nama, bank, rekening, nominal) → aplikasi membuat file Excel
 * "Template MAT BCA dan Bank Lain Dalam Negeri" (21 kolom, lembar "Data") yang siap diubah converter Multi Auto-Transfer
 * KlikBCA Bisnis menjadi file upload. Semua kolom lain diisi tetap: BI-FAST (BIF; sesama BCA = BCA), IDR, biaya OUR dari
 * rekening debet BCA PT, kode SWIFT dari tabel resmi BCA, penerima perorangan/penduduk, kode transaksi 02 Pemindahan Dana.
 */
class TransferBcaController extends Controller
{
    private const KUNCI_REKENING = 'bca_rekening_debet';

    public function index(): View
    {
        return view('kas.transfer-bca', [
            'rekening' => PengaturanApp::ambil(self::KUNCI_REKENING),
            'riwayat' => TransferBca::with('user')->latest('id')->limit(20)->get(),
            'bank' => collect(DaftarBank::untukForm())->filter(fn ($b) => BankBca::untuk($b['kode']))->values(),
            'penerima' => $this->penerimaDikenal(),
        ]);
    }

    public function simpanRekening(Request $request): RedirectResponse
    {
        $request->merge(['rekening' => preg_replace('/\D/', '', (string) $request->input('rekening'))]);
        $data = $request->validate(['rekening' => ['required', 'digits:10']], ['rekening.digits' => 'Rekening BCA harus 10 digit angka.']);
        PengaturanApp::simpan(self::KUNCI_REKENING, $data['rekening'], $request->user()->id);

        return back()->with('success', 'Rekening debet BCA PT MNP disimpan: '.$data['rekening'].'.');
    }

    public function buat(Request $request): BinaryFileResponse|RedirectResponse
    {
        $rekeningDebet = PengaturanApp::ambil(self::KUNCI_REKENING);
        if (! $rekeningDebet) {
            return back()->withInput()->with('error', 'Isi dulu rekening debet BCA PT MNP (sekali saja).');
        }
        $baris = collect((array) $request->input('penerima', []))
            ->map(fn ($p) => [
                'nama' => trim(preg_replace('/\s+/', ' ', (string) ($p['nama'] ?? ''))),
                'bank' => DaftarBank::kode($p['bank'] ?? null),
                'bank_teks' => trim((string) ($p['bank'] ?? '')),
                'rekening' => preg_replace('/\D/', '', (string) ($p['rekening'] ?? '')),
                'nominal' => (int) preg_replace('/\D/', '', (string) ($p['nominal'] ?? '')),
                'keterangan' => trim(preg_replace('/\s+/', ' ', (string) ($p['keterangan'] ?? ''))),
            ])
            ->reject(fn ($p) => $p['nama'] === '' && $p['bank_teks'] === '' && $p['rekening'] === '' && ! $p['nominal'])->values();
        // Tanggal dari isian dd-MMM-yyyy (mis. 09-Okt-2026) / ddmmyyyy / yyyy-mm-dd.
        $tanggal = RitasiController::tanggal((string) $request->input('tanggal'));
        if (! $tanggal || $tanggal->lt(today())) {
            return back()->withInput()->with('error', $tanggal ? 'Tanggal transfer tidak boleh sebelum hari ini.' : 'Tanggal transfer belum benar (contoh 09-Okt-2026).');
        }
        if ($baris->isEmpty()) {
            return back()->withInput()->with('error', 'Isi minimal satu penerima.');
        }
        $galat = [];
        foreach ($baris as $i => $p) {
            $no = 'Baris '.($i + 1).($p['nama'] ? " ({$p['nama']})" : '');
            $salah = [];
            if ($p['nama'] === '') {
                $salah[] = 'nama penerima belum diisi';
            } elseif (mb_strlen($p['nama']) > 70) {
                $salah[] = 'nama penerima maks. 70 karakter';
            }
            if (! $p['bank']) {
                $salah[] = $p['bank_teks'] === '' ? 'bank belum diisi' : "bank \"{$p['bank_teks']}\" tidak dikenal";
            } elseif (! BankBca::untuk($p['bank'])) {
                $salah[] = "{$p['bank']} tidak bisa (e-wallet/bank tidak ada di daftar BCA)";
            }
            if (strlen($p['rekening']) < 5 || strlen($p['rekening']) > 34) {
                $salah[] = 'nomor rekening belum benar';
            }
            if ($p['nominal'] < 1) {
                $salah[] = 'nominal belum diisi';
            } elseif ($p['bank'] !== 'BCA' && $p['nominal'] > BankBca::MAKS_BIFAST) {
                $salah[] = 'BI-FAST maks. '.rp(BankBca::MAKS_BIFAST).' per transaksi';
            }
            if (mb_strlen($p['keterangan']) > 18) {
                $salah[] = 'keterangan maks. 18 karakter';
            }
            if ($salah) {
                $galat["penerima.{$i}"] = $no.': '.implode(', ', $salah).'.';
            }
        }
        if ($galat) {
            return back()->withInput()->withErrors($galat);
        }

        $batch = TransferBca::create([
            'tanggal_efektif' => $tanggal->toDateString(), 'rekening_debet' => $rekeningDebet,
            'jumlah' => $baris->count(), 'total' => (int) $baris->sum('nominal'), 'user_id' => $request->user()->id,
            'isi' => $baris->map(fn ($p) => collect($p)->except('bank_teks')->all())->all(),
        ]);
        KasRiwayat::create(['aksi' => 'transfer-bca', 'lembar' => 'BCA', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth('Transfer Massal BCA #'.$batch->id.': '.$batch->jumlah.' penerima, '.rp($batch->total).' tgl '.$batch->tanggal_efektif->translatedFormat('j M Y'), 0, 490, '…'),
            'isi' => ['transfer_bca_id' => $batch->id], 'user_id' => $request->user()->id]);

        return $this->unduh($batch);
    }

    public function unduh(TransferBca $transfer): BinaryFileResponse
    {
        $nama = 'MAT BCA '.$transfer->tanggal_efektif->format('Y-m-d').' - '.$transfer->jumlah.' penerima - Rp '.number_format($transfer->total, 0, ',', '.').' (#'.$transfer->id.').xlsx';

        return response()->download($this->excel($transfer), $nama)->deleteFileAfterSend();
    }

    /** File Excel berformat "Template MAT BCA dan Bank Lain Dalam Negeri": lembar "Data", baris 1 judul kolom. */
    private function excel(TransferBca $t): string
    {
        $path = storage_path('app/mat-bca-'.$t->id.'-'.uniqid().'.xlsx');
        $w = new Writer(new Options());
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Data');
        $tebal = (new Style())->setFontBold();
        $uang = (new Style())->setFormat('0.00');
        $w->addRow(Row::fromValues(['No', 'Transaction ID', 'Transfer Type', 'Debited Acc.', 'Beneficiary ID', 'Credited Acc.', 'Amount', 'Eff. Date',
            'Transaction Purpose', 'Currency', 'Charges Type', 'Charges Acc.', 'Remark 1', 'Remark 2', 'Receiver Bank Cd', 'Receiver Bank Name',
            'Receiver Name', 'Receiver Cust. Type', 'Receiver Cust. Residen', 'Transaction Cd', 'Beneficiary Email'], $tebal));
        foreach (array_values($t->isi) as $i => $p) {
            $jenis = BankBca::jenisTransfer($p['bank']);
            $bank = $jenis === 'BCA' ? null : BankBca::untuk($p['bank']);
            $w->addRow(new Row([
                Cell::fromValue($i + 1), Cell::fromValue($t->idTransaksi($i + 1)), Cell::fromValue($jenis), Cell::fromValue((string) $t->rekening_debet),
                Cell::fromValue(''), Cell::fromValue((string) $p['rekening']), Cell::fromValue((float) $p['nominal'], $uang),
                Cell::fromValue($t->tanggal_efektif->format('Ymd')), Cell::fromValue(''), Cell::fromValue('IDR'), Cell::fromValue('OUR'),
                Cell::fromValue((string) $t->rekening_debet), Cell::fromValue((string) $p['keterangan']), Cell::fromValue(''),
                Cell::fromValue($bank['bic'] ?? ''), Cell::fromValue($bank['nama'] ?? ''), Cell::fromValue(mb_substr($p['nama'], 0, 70)),
                Cell::fromValue($jenis === 'BCA' ? '' : '1'), Cell::fromValue($jenis === 'BCA' ? '' : '1'), Cell::fromValue($jenis === 'BCA' ? '' : '02'), Cell::fromValue(''),
            ]));
        }
        $w->close();

        return $path;
    }

    /**
     * Penerima yang pernah dipakai (Kas Harian & Kas UJ): nama → bank & rekening terakhir, untuk saran isian.
     *
     * @return array<int, array{nama: string, bank: string, rekening: string}>
     */
    private function penerimaDikenal(): array
    {
        $hasil = [];
        $tambah = function ($nama, $bank, $rek, $tgl) use (&$hasil) {
            $nama = trim((string) $nama);
            $rek = preg_replace('/\D/', '', (string) $rek);
            $kode = DaftarBank::kode($bank);
            if ($nama === '' || strlen($rek) < 5 || ! $kode || ! BankBca::untuk($kode)) {
                return;
            }
            $k = mb_strtolower($nama).'|'.$rek;
            if (! isset($hasil[$k]) || $hasil[$k]['tgl'] < $tgl) {
                $hasil[$k] = ['nama' => $nama, 'bank' => $kode, 'rekening' => $rek, 'tgl' => (string) $tgl];
            }
        };
        foreach (KasTransfer::whereNotNull('no_rek_tujuan')->where('no_rek_tujuan', '!=', '')->get(['nama_tujuan', 'bank_tujuan', 'no_rek_tujuan', 'tanggal']) as $r) {
            $tambah($r->nama_tujuan, $r->bank_tujuan, $r->no_rek_tujuan, $r->tanggal?->toDateString());
        }
        foreach (UjTransaksi::whereNotNull('rekening')->where('rekening', '!=', '')->get(['nama', 'bank', 'rekening', 'tanggal']) as $r) {
            $tambah($r->nama, $r->bank, $r->rekening, $r->tanggal?->toDateString());
        }

        return collect($hasil)->sortByDesc('tgl')->map(fn ($x) => collect($x)->except('tgl')->all())->values()->all();
    }
}
