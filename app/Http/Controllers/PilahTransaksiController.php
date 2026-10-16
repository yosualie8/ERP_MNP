<?php

namespace App\Http\Controllers;

use App\Models\BoqItem;
use App\Models\KasRiwayat;
use App\Models\MutasiPtTanda;
use App\Models\Proyek;
use App\Models\ProyekTahap;
use App\Models\TransaksiBoq;
use App\Support\DaftarProyek;
use App\Support\Overhead;
use App\Support\PilahTransaksi;
use App\Support\TandaMutasiPt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Pilah Transaksi Proyek: tiap transaksi Kas & BCA → tahap detail + kode BOQ, dengan rekomendasi (lihat App\Support\PilahTransaksi). */
class PilahTransaksiController extends Controller
{
    public const SARING = ['semua' => 'Semua', 'beda' => 'Rekomendasi beda dari pilihan', 'tanpa-boq' => 'Dihitung tanpa kode BOQ', 'belum-bca' => 'BCA belum dicek'];

    /** @return array{0: Collection, 1: array<int>, 2: string, 3: Collection} [proyek terpilih, id tahap, dari, semua proyek] */
    private function lingkup(Request $request): array
    {
        $semua = Proyek::orderBy('nama')->get(['id', 'nama']);
        $pilih = collect((array) $request->query('proyek'))->map(fn ($v) => (int) $v)->filter()->values();
        if ($pilih->isEmpty()) {
            // bawaan: proyek Infra & Pantai Mutiara 2 (PM2 Infra Regatta/Marina, Infra PIK)
            $pilih = $semua->filter(fn ($p) => preg_match('/infra|pantai mutiara 2/i', $p->nama))->pluck('id')->values();
        }
        $ids = ProyekTahap::whereIn('proyek_id', $pilih)->pluck('id')->all();
        $dari = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('dari')) ? $request->query('dari') : PilahTransaksi::MULAI;

        return [$pilih, $ids, $dari, $semua];
    }

    public function index(Request $request): View
    {
        [$pilih, $ids, $dari, $semua] = $this->lingkup($request);
        if ($request->boolean('segarkan')) {
            PilahTransaksi::tagSheet(true);
        }
        $saring = array_key_exists((string) $request->query('saring'), self::SARING) ? $request->query('saring') : 'semua';
        $baris = PilahTransaksi::baris($ids, $dari);
        $hitungSaring = collect(self::SARING)->map(fn ($l, $k) => $this->saring($baris, $k)->count());
        $tampil = $this->saring($baris, $saring);
        $tahap = ProyekTahap::with('proyek')->get()->sortBy(fn ($t) => [in_array($t->id, $ids, true) ? 0 : 1, $t->status === 'progress' ? 0 : 1, $t->nama])->values();
        // ringkasan biaya dihitung per tahap lingkup ini (dari baris yang tampil di halaman)
        $per = $baris->filter(fn ($x) => $x->dihitung && $x->tahap_id)->groupBy('tahap_id')->map(fn ($g) => ['rp' => $g->sum('nominal'), 'n' => $g->count(),
            'boq' => $g->filter(fn ($x) => $x->boq)->sum('nominal')]);

        return view('pilah-transaksi.index', [
            'baris' => $tampil, 'semuaBaris' => $baris->count(), 'saring' => $saring, 'hitungSaring' => $hitungSaring, 'pilih' => $pilih, 'semuaProyek' => $semua,
            'dari' => $dari, 'tahap' => $tahap, 'per' => $per, 'tahapNama' => $tahap->pluck('nama', 'id'),
            'boq' => BoqItem::orderBy('urutan')->get()->groupBy('proyek_tahap_id')->map(fn ($g) => $g->map(fn ($i) => [$i->id, $i->label()])->values()),
            'galatSheet' => PilahTransaksi::tagSheet()['galat'] ?? null,
        ]);
    }

    private function saring(Collection $baris, string $saring): Collection
    {
        return (match ($saring) {
            'beda' => $baris->filter(fn ($x) => ($x->rek_tahap && $x->rek_tahap[0] !== ($x->abaikan ? 'abaikan' : $x->tahap_id)) || ($x->rek_boq && $x->rek_boq[0]->id !== $x->boq?->id)),
            'tanpa-boq' => $baris->filter(fn ($x) => $x->dihitung && ! $x->boq),
            'belum-bca' => $baris->filter(fn ($x) => $x->sumber === 'bca' && ! $x->manual),
            default => $baris,
        })->values();
    }

    /**
     * Simpan satu / banyak baris: tahap ("" = kembali otomatis, "abaikan" = bukan biaya / tidak dihitung, atau id tahap)
     * dan kode BOQ (harus milik tahap itu).
     */
    public function simpan(Request $request): JsonResponse
    {
        $d = $request->validate([
            'baris' => ['required', 'array', 'min:1', 'max:500'],
            'baris.*.sumber' => ['required', 'in:kas,bca'],
            'baris.*.kunci' => ['required', 'string', 'max:80'],
            'baris.*.tahap' => ['nullable', 'string', 'max:20'],
            'baris.*.boq' => ['nullable', 'integer'],
        ]);
        $tahapAda = ProyekTahap::get(['id', 'nama', 'tipe', 'cost_center', 'proyek_id', 'pos_overhead'])->keyBy('id');
        $bca = TandaMutasiPt::baris()->keyBy('kunci');
        $hasil = [];
        $catat = [];
        DB::beginTransaction();
        try {
            foreach ($d['baris'] as $i => $b) {
                $abaikan = ($b['tahap'] ?? '') === 'abaikan';
                $tid = ! $abaikan && ($b['tahap'] ?? '') !== '' ? (int) $b['tahap'] : null;
                if ($tid && ! isset($tahapAda[$tid])) {
                    throw new \RuntimeException('Tahap tidak dikenal (baris '.($i + 1).').');
                }
                $item = ! empty($b['boq']) ? BoqItem::find($b['boq']) : null;
                if (! empty($b['boq']) && (! $item || $item->proyek_tahap_id !== $tid)) {
                    throw new \RuntimeException('Kode BOQ harus milik tahap yang dipilih (baris '.($i + 1).').');
                }
                if ($b['sumber'] === 'kas') {
                    $r = DaftarProyek::queryKas(true)->whereRaw(DaftarProyek::SQL_KUNCI.' = ?', [$b['kunci']])
                        ->selectRaw('b.tanggal, b.nominal, b.keterangan, a.nama akun, a.kelompok kel, c.kode cc, k.ref')->first();
                    if (! $r) {
                        throw new \RuntimeException('Transaksi Kas tidak ditemukan (mungkin lembar diimpor ulang) — muat ulang halaman.');
                    }
                    PilahTransaksi::simpanKas($b['kunci'], $tid, $abaikan);
                    $efektif = $abaikan ? null : ($tid ?: DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10)));
                    $t = $efektif ? $tahapAda[$efektif] ?? null : null;
                    $status = $abaikan ? 'bukan biaya (dipilah)' : (! $t ? 'tanpa tahap' : (Overhead::diabaikan($r->kel, $r->akun, $t->tipe, $r->keterangan)
                        ? 'akun '.$r->akun.' — dari sumber lain' : ($t->pos_overhead ? 'biaya umum (dibagi Alokasi Biaya Umum)' : 'dihitung')));
                    $ringkas = 'Kas '.substr((string) $r->tanggal, 0, 10).' Rp '.number_format($r->nominal, 0, ',', '.').' '.mb_strimwidth((string) $r->keterangan, 0, 60, '…');
                } else {
                    $m = $bca[$b['kunci']] ?? null;
                    if (! $m || $m->m->dbcr !== 'DB') {
                        throw new \RuntimeException('Mutasi BCA tidak ditemukan / bukan uang keluar — muat ulang halaman.');
                    }
                    $tanda = MutasiPtTanda::firstOrNew(['kunci' => $b['kunci']]);
                    $tanda->fill(['status' => $abaikan ? 'tidak' : ($tid ? 'dihitung' : null), 'proyek_tahap_id' => $tid,
                        'cost_center' => $tid ? ProyekTahap::find($tid)->costCenter() : null, 'user_id' => $request->user()->id])->save();
                    $status = $abaikan ? 'Tidak dihitung' : ($tid ? 'Dihitung'.($tahapAda[$tid]->pos_overhead ? ' · biaya umum (dibagi)' : '') : 'ikut otomatis');
                    $ringkas = 'BCA '.$m->m->tanggal->toDateString().' Rp '.number_format($m->m->jumlah, 0, ',', '.').' '.mb_strimwidth((string) $m->m->keterangan, 0, 60, '…');
                }
                if ($item) {
                    TransaksiBoq::updateOrCreate(['sumber' => $b['sumber'], 'kunci' => $b['kunci']], ['boq_item_id' => $item->id, 'user_id' => $request->user()->id]);
                } else {
                    TransaksiBoq::where('sumber', $b['sumber'])->where('kunci', $b['kunci'])->delete();
                }
                $hasil[] = ['kunci' => $b['kunci'], 'status' => $status];
                $catat[] = $ringkas.' → '.($abaikan ? 'bukan biaya' : ($tid ? $tahapAda[$tid]->nama : 'otomatis')).($item ? ' · BOQ '.$item->kode : '');
            }
            KasRiwayat::create(['aksi' => 'pilah-transaksi', 'lembar' => 'Pilah Transaksi', 'baris_awal' => 0, 'baris_akhir' => 0,
                'ringkasan' => mb_strimwidth(count($catat) === 1 ? 'Pilah: '.$catat[0] : 'Pilah '.count($catat).' transaksi: '.implode(' | ', array_slice($catat, 0, 3)).(count($catat) > 3 ? ' …' : ''), 0, 490, '…'),
                'isi' => ['baris' => $d['baris'], 'ringkas' => $catat], 'user_id' => $request->user()->id]);
            DB::commit();
        } catch (\RuntimeException $e) {
            DB::rollBack();

            return response()->json(['galat' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'baris' => $hasil]);
    }

    public function unduh(Request $request): StreamedResponse
    {
        [, $ids, $dari] = $this->lingkup($request);
        $baris = PilahTransaksi::baris($ids, $dari);
        $nama = ProyekTahap::pluck('nama', 'id');

        return response()->streamDownload(function () use ($baris, $nama) {
            $w = new \OpenSpout\Writer\XLSX\Writer;
            $w->openToFile('php://output');
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Sumber', 'Tanggal', 'NO ID', 'Keterangan', 'Keterangan tambahan', 'Akun', 'Kode GL', 'Nominal', 'Status hitung',
                'Tahap sekarang', 'Kode BOQ', 'Rekomendasi tahap', 'Dasar', 'Rekomendasi BOQ', 'Dasar BOQ', 'Tag sheet Proyek', 'Tag sheet BOQ'], (new \OpenSpout\Common\Entity\Style\Style)->setFontBold()));
            foreach ($baris as $x) {
                $w->addRow(\OpenSpout\Common\Entity\Row::fromValues([strtoupper($x->sumber), $x->tanggal, $x->no_id, $x->keterangan, $x->catatan, $x->akun, $x->kode, $x->nominal, $x->status,
                    $x->abaikan ? 'bukan biaya' : ($nama[$x->tahap_id] ?? ''), $x->boq?->label(),
                    $x->rek_tahap ? ($x->rek_tahap[0] === 'abaikan' ? 'bukan biaya' : $nama[$x->rek_tahap[0]] ?? '') : '', $x->rek_tahap[1] ?? '',
                    $x->rek_boq ? $x->rek_boq[0]->label() : '', $x->rek_boq[1] ?? '', $x->tag[0] ?? '', $x->tag[1] ?? '']));
            }
            $w->close();
        }, 'Pilah Transaksi Proyek '.now()->format('Y-m-d').'.xlsx');
    }
}
