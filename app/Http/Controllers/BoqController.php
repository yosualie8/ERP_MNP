<?php

namespace App\Http\Controllers;

use App\Models\BoqItem;
use App\Models\BoqOpname;
use App\Models\KasRiwayat;
use App\Models\ProyekTahap;
use App\Models\TagihanItem;
use App\Models\TagihanProyek;
use App\Models\TransaksiBoq;
use App\Support\GoogleSheets;
use App\Support\ProgresBoq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** BOQ & Progres Proyek: item BOQ per tahap, progres modal vs terpasang vs tertagih, opname & tagihan progres. */
class BoqController extends Controller
{
    public function index(Request $request): View
    {
        $punya = BoqItem::distinct()->pluck('proyek_tahap_id')->all();
        $daftar = ProyekTahap::with('proyek')->where(fn ($q) => $q->where('tipe', 'infra')->orWhereIn('id', $punya))->get()
            ->sortBy(fn ($t) => [in_array($t->id, $punya, true) ? 0 : 1, $t->status === 'progress' ? 0 : 1, $t->nama])->values();
        $tahap = $request->filled('tahap') ? ProyekTahap::with('proyek')->findOrFail($request->integer('tahap')) : $daftar->first();

        return view('boq.index', [
            'daftar' => $daftar, 'tahap' => $tahap, 'ubah' => $request->boolean('ubah'),
            'p' => $tahap ? ProgresBoq::hitung($tahap) : null,
            // biaya belum ber-BOQ dibagi ke item per bobot Total Harga (bawaan tampil; ?bagi=0 untuk hanya biaya ber-BOQ)
            'bagi' => $request->query('bagi', '1') !== '0', 'lain' => $tahap ? ProgresBoq::biayaLain($tahap) : null,
            'tagihan' => $tahap ? TagihanProyek::with('item')->where('proyek_tahap_id', $tahap->id)->orderBy('tanggal')->orderBy('id')->get() : collect(),
            'nTag' => $tahap ? TransaksiBoq::whereIn('boq_item_id', BoqItem::where('proyek_tahap_id', $tahap->id)->pluck('id'))->selectRaw('boq_item_id, count(*) n')->groupBy('boq_item_id')->pluck('n', 'boq_item_id') : collect(),
        ]);
    }

    /** Simpan seluruh daftar item BOQ tahap (ubah, tambah, hapus). Item yang dihapus ikut melepas kode BOQ transaksinya. */
    public function simpan(Request $request, ProyekTahap $tahap): RedirectResponse
    {
        $d = $request->validate([
            'item' => ['array'],
            'item.*.id' => ['nullable', 'integer'],
            'item.*.kode' => ['nullable', 'string', 'max:20'],
            'item.*.uraian' => ['nullable', 'string', 'max:255'],
            'item.*.satuan' => ['nullable', 'string', 'max:20'],
            'item.*.volume' => ['nullable', 'numeric'],
            'item.*.harga' => ['nullable', 'numeric'],
            'item.*.modal_material' => ['nullable', 'numeric'],
            'item.*.modal_jasa' => ['nullable', 'numeric'],
        ]);
        $baris = collect($d['item'] ?? [])->filter(fn ($x) => trim((string) ($x['kode'] ?? '')) !== '' || trim((string) ($x['uraian'] ?? '')) !== '')->values();
        $kode = $baris->map(fn ($x) => strtoupper(trim((string) ($x['kode'] ?? ''))));
        if ($kode->contains('') || $baris->contains(fn ($x) => trim((string) ($x['uraian'] ?? '')) === '')) {
            return back()->withInput()->with('error', 'Setiap item wajib punya kode & uraian.');
        }
        if ($kode->duplicates()->isNotEmpty()) {
            return back()->withInput()->with('error', 'Kode BOQ dobel: '.$kode->duplicates()->unique()->implode(', '));
        }
        $lama = BoqItem::where('proyek_tahap_id', $tahap->id)->get()->keyBy('id');
        DB::transaction(function () use ($baris, $lama, $tahap) {
            $simpan = [];
            // kode sementara dulu supaya tukar kode antar baris tidak bentrok unik
            BoqItem::where('proyek_tahap_id', $tahap->id)->update(['kode' => DB::raw("CONCAT('~', id)")]);
            foreach ($baris as $i => $x) {
                $isi = ['kode' => strtoupper(trim($x['kode'])), 'uraian' => trim($x['uraian']), 'satuan' => trim((string) ($x['satuan'] ?? '')) ?: null, 'urutan' => $i + 1]
                    + collect(['volume', 'harga', 'modal_material', 'modal_jasa'])->mapWithKeys(fn ($k) => [$k => isset($x[$k]) && $x[$k] !== '' ? (float) $x[$k] : null])->all();
                $item = ! empty($x['id']) && isset($lama[$x['id']]) ? tap($lama[$x['id']])->update($isi) : BoqItem::create($isi + ['proyek_tahap_id' => $tahap->id]);
                $simpan[] = $item->id;
            }
            BoqItem::where('proyek_tahap_id', $tahap->id)->whereNotIn('id', $simpan)->delete();
        });
        KasRiwayat::create(['aksi' => 'boq-simpan', 'lembar' => 'BOQ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "BOQ {$tahap->nama} disimpan: ".$baris->count().' item (sebelumnya '.$lama->count().')',
            'isi' => ['tahap_id' => $tahap->id, 'lama' => $lama->values()->map->only(['id', 'kode', 'uraian', 'satuan', 'volume', 'harga', 'modal_material', 'modal_jasa']), 'baru' => $baris], 'user_id' => $request->user()->id]);

        return redirect()->route('boq.index', ['tahap' => $tahap->id])->with('success', 'BOQ disimpan: '.$baris->count().' item.');
    }

    /** Impor item dari lembar penawaran (format "Monitoring"): kode yang sudah ada diperbarui, kode baru ditambah. */
    public function impor(Request $request, ProyekTahap $tahap): RedirectResponse
    {
        $d = $request->validate(['link' => ['required', 'string', 'max:300'], 'lembar' => ['required', 'string', 'max:100']]);
        try {
            $baru = ProgresBoq::bacaSheet(GoogleSheets::idDari($d['link']), $d['lembar']);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal membaca sheet: '.$e->getMessage());
        }
        if (! $baru) {
            return back()->with('error', 'Tidak ada baris berkode BOQ (A1, B12, …) di kolom A lembar "'.$d['lembar'].'".');
        }
        $ada = BoqItem::where('proyek_tahap_id', $tahap->id)->get()->keyBy('kode');
        $urut = (int) $ada->max('urutan');
        DB::transaction(function () use ($baru, $ada, $tahap, &$urut) {
            foreach ($baru as $x) {
                isset($ada[$x['kode']]) ? $ada[$x['kode']]->update($x) : BoqItem::create($x + ['proyek_tahap_id' => $tahap->id, 'urutan' => ++$urut]);
            }
        });
        KasRiwayat::create(['aksi' => 'boq-impor', 'lembar' => 'BOQ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "BOQ {$tahap->nama} diimpor dari sheet ({$d['lembar']}): ".count($baru).' item', 'isi' => ['tahap_id' => $tahap->id, 'link' => $d['link'], 'item' => $baru], 'user_id' => $request->user()->id]);

        return redirect()->route('boq.index', ['tahap' => $tahap->id])->with('success', count($baru).' item BOQ diimpor dari lembar "'.$d['lembar'].'".');
    }

    /** Catat volume terpasang KUMULATIF satu item (opname) — dari halaman tanpa muat ulang. */
    public function opname(Request $request, BoqItem $item): JsonResponse
    {
        $d = $request->validate(['volume' => ['required', 'numeric', 'min:0'], 'tanggal' => ['nullable', 'date']]);
        $tgl = $d['tanggal'] ?? now()->toDateString();
        $o = BoqOpname::updateOrCreate(['boq_item_id' => $item->id, 'tanggal' => $tgl], ['volume' => $d['volume'], 'user_id' => $request->user()->id]);
        KasRiwayat::create(['aksi' => 'boq-opname', 'lembar' => 'BOQ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Opname {$item->tahap->nama} {$item->kode}: terpasang ".rtrim(rtrim(number_format($d['volume'], 3, ',', '.'), '0'), ',')." {$item->satuan} per {$tgl}",
            'isi' => ['boq_item_id' => $item->id, 'opname_id' => $o->id, 'volume' => $d['volume'], 'tanggal' => $tgl], 'user_id' => $request->user()->id]);
        $vol = (float) $item->volume;
        // terpasang efektif minimal = volume yang sudah ditagih
        $efektif = max((float) $d['volume'], (float) TagihanItem::where('boq_item_id', $item->id)->sum('volume'));

        return response()->json(['ok' => true, 'persen' => $vol > 0 ? round($efektif / $vol * 100, 1) : null, 'tanggal' => \Illuminate\Support\Carbon::parse($tgl)->translatedFormat('d-M-y')]);
    }

    /** Tagihan progres baru: volume ditagih per item (nilai = volume × harga jual). */
    public function tagihan(Request $request, ProyekTahap $tahap): RedirectResponse
    {
        $d = $request->validate(['nomor' => ['required', 'string', 'max:60'], 'tanggal' => ['required', 'date'], 'catatan' => ['nullable', 'string', 'max:500'],
            'volume' => ['array'], 'volume.*' => ['nullable', 'numeric', 'min:0']]);
        $items = BoqItem::where('proyek_tahap_id', $tahap->id)->get()->keyBy('id');
        $isi = collect($d['volume'] ?? [])->filter(fn ($v, $id) => $v !== null && (float) $v > 0 && isset($items[$id]));
        if ($isi->isEmpty()) {
            return back()->withInput()->with('error', 'Isi volume ditagih minimal satu item.');
        }
        $t = DB::transaction(function () use ($d, $tahap, $isi, $items, $request) {
            $t = TagihanProyek::create(['proyek_tahap_id' => $tahap->id, 'nomor' => trim($d['nomor']), 'tanggal' => $d['tanggal'], 'catatan' => $d['catatan'] ?? null, 'user_id' => $request->user()->id]);
            foreach ($isi as $id => $v) {
                TagihanItem::create(['tagihan_proyek_id' => $t->id, 'boq_item_id' => $id, 'volume' => (float) $v, 'nilai' => round((float) $v * (float) $items[$id]->harga, 2)]);
            }

            return $t;
        });
        $nilai = TagihanItem::where('tagihan_proyek_id', $t->id)->sum('nilai');
        KasRiwayat::create(['aksi' => 'tagihan-proyek', 'lembar' => 'BOQ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Tagihan {$t->nomor} {$tahap->nama} ".$t->tanggal->format('d-m-Y').': Rp '.number_format($nilai, 0, ',', '.').' ('.$isi->count().' item)',
            'isi' => ['tagihan_id' => $t->id, 'volume' => $isi], 'user_id' => $request->user()->id]);

        return redirect()->route('boq.index', ['tahap' => $tahap->id])->with('success', "Tagihan {$t->nomor} disimpan: Rp ".number_format($nilai, 0, ',', '.'));
    }

    public function hapusTagihan(Request $request, TagihanProyek $tagihan): RedirectResponse
    {
        $tahap = $tagihan->proyek_tahap_id;
        KasRiwayat::create(['aksi' => 'tagihan-proyek-hapus', 'lembar' => 'BOQ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Tagihan {$tagihan->nomor} ({$tagihan->tahap->nama}) dihapus", 'isi' => ['tagihan' => $tagihan->only(['id', 'nomor', 'tanggal', 'catatan']), 'item' => $tagihan->item->map->only(['boq_item_id', 'volume', 'nilai'])], 'user_id' => $request->user()->id]);
        $tagihan->delete();

        return redirect()->route('boq.index', ['tahap' => $tahap])->with('success', 'Tagihan dihapus.');
    }
}
