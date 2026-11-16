<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\SparepartBeli;
use App\Models\SparepartItem;
use App\Support\DaftarSparepart;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Database Sparepart (owner 16 Nov 2026, menu Plant & Sparepart): kepala mekanik mencari sparepart, harga terakhir & termurah,
 * dan histori harganya. Sumber: tabel sparepart_beli (riwayat pembelian Kas Harian + Kas UJ dengan nama item baku).
 * v2 (owner 16 Nov 2026): daftar item hanya kolom inti (kategori, nama, satuan, harga & tanggal terakhir, supplier terakhir, merk, Ori/KW);
 * ringkasan & histori lengkap muncul saat item diklik. Merk & Ori/KW diisi di dialog item (hak 'sparepart-ubah') ke tabel sparepart_item.
 */
class SparepartController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'item';
        $semua = SparepartBeli::query()->orderBy('tanggal')->orderBy('id')->toBase()->get();
        $info = ['baris' => $semua->count(), 'awal' => $semua->first()?->tanggal, 'akhir' => $semua->last()?->tanggal, 'ditarik' => $semua->max('ditarik')];

        $item = DaftarSparepart::item($semua);

        // Riwayat: bawaan 6 bulan terakhir (semua baris = ±5 MB HTML, berat di HP); histori lengkap per item tetap lewat dialog.
        $periode = in_array($request->query('periode'), ['12', 'semua'], true) ? $request->query('periode') : '6';
        $sejak = $periode === 'semua' ? '' : now()->subMonths((int) $periode)->toDateString();

        return view('sparepart.index', [
            'tab' => $tab, 'info' => $info, 'item' => $item, 'periode' => $periode, 'cari' => (string) $request->query('q', ''),
            'riwayat' => $tab === 'riwayat' ? $semua->filter(fn ($b) => $b->tanggal >= $sejak)->reverse()->values() : collect(),
            'jumlahPaket' => $semua->where('kategori', SparepartBeli::PAKET)->count(),
        ]);
    }

    /** Ringkasan + histori pembelian satu item (+ paket yang memuat item itu) untuk dialog di halaman Database Sparepart. */
    public function riwayat(Request $request): JsonResponse
    {
        $data = $request->validate(['nama' => ['required', 'string', 'max:200'], 'satuan' => ['nullable', 'string', 'max:20']]);
        $nama = $data['nama'];
        $satuan = $data['satuan'] ?? null;
        // Histori di dialog: 12 bulan terakhir dari hari ini (owner 16 Nov 2026), urut tanggal pembelian terbaru dulu.
        $sejak = now()->subYear()->startOfDay();
        $itemIni = fn ($q) => $q->where('nama_item', $nama)->when($satuan, fn ($q, $s) => $q->where('satuan', $s), fn ($q) => $q->whereNull('satuan'));
        $baris = SparepartBeli::query()->where('tanggal', '>=', $sejak)
            ->where(fn ($w) => $w->where($itemIni)
                ->orWhere(fn ($q) => $q->where('kategori', SparepartBeli::PAKET)->where('nama_item', 'like', '%'.addcslashes($nama, '%_\\').'%')))
            ->orderByDesc('tanggal')->orderByDesc('id')->get()
            // paket: nama item harus salah satu bagiannya persis ("Filter Oli" tidak ikut "Filter Oli Gardan")
            ->filter(fn ($b) => $b->kategori !== SparepartBeli::PAKET || in_array($nama, explode(' + ', $b->nama_item), true));

        // ringkasan dari pembelian item itu sendiri (paket tidak dihitung: harganya untuk beberapa barang)
        $sendiri = $baris->filter(fn ($b) => $b->kategori !== SparepartBeli::PAKET)->sortBy(fn ($b) => $b->tanggal->toDateString().sprintf('%09d', $b->id))->values();
        $ringkas = DaftarSparepart::item($sendiri->map(fn ($b) => (object) $b->getAttributes()))->first();
        $isian = SparepartItem::where('nama_item', $nama)->where('satuan', $satuan ?? '')->first();
        // tidak ada pembelian 12 bulan terakhir → tetap beri tahu pembelian terakhirnya
        $terakhir = $sendiri->isEmpty() ? SparepartBeli::query()->where($itemIni)->orderByDesc('tanggal')->orderByDesc('id')->first() : null;
        // Part number / merk / Ori-KW dihitung dari semua pembelian (bukan hanya 12 bulan) supaya usulannya sama dengan daftar item
        $atribut = DaftarSparepart::item(SparepartBeli::query()->where($itemIni)->orderBy('tanggal')->orderBy('id')->toBase()->get())->first();

        return response()->json([
            'sejak' => $sejak->translatedFormat('j M Y'),
            'terakhir' => $terakhir ? ['tgl' => $terakhir->tanggal->translatedFormat('j M Y'), 'harga' => $terakhir->harga_satuan, 'supplier' => $terakhir->supplier] : null,
            'atribut' => $atribut ? ['jenis' => $atribut->jenis, 'jenis_usul' => $atribut->jenis_usul, 'part' => $atribut->part, 'part_usul' => $atribut->part_usul, 'merk' => $atribut->merk, 'merk_usul' => $atribut->merk_usul,
                'kualitas' => $atribut->kualitas, 'kualitas_usul' => $atribut->kualitas_usul] : null,
            'ringkas' => $ringkas ? [
                'kategori' => $ringkas->kategori, 'n' => $ringkas->n, 'qty' => $ringkas->qty, 'total' => $ringkas->total,
                'awal' => self::tgl($sendiri->first()?->tanggal?->toDateString()),
                'per_unit' => $ringkas->per_unit, 'golongan' => $ringkas->golongan, 'spek' => $ringkas->spek,
                'supplier' => $ringkas->supplier->map(fn ($n, $s) => ['nama' => $s, 'n' => $n])->values(),
                'merk' => $ringkas->merk, 'merk_usul' => $ringkas->merk_usul, 'kualitas' => $ringkas->kualitas, 'kualitas_usul' => $ringkas->kualitas_usul,
            ] : null,
            'isian' => ['jenis' => $isian?->jenis, 'part_number' => $isian?->part_number, 'merk' => $isian?->merk, 'kualitas' => $isian?->kualitas, 'catatan' => $isian?->catatan,
                'oleh' => $isian?->user_id ? \App\Models\User::find($isian->user_id)?->name : null, 'kapan' => $isian?->updated_at?->translatedFormat('j M Y H:i')],
            'bisa_ubah' => $request->user()->bolehMenu('sparepart-ubah'),
            'baris' => $baris->map(fn ($b) => [
                'tanggal' => $b->tanggal->toDateString(), 'tgl' => $b->tanggal->translatedFormat('j M Y'), 'paket' => $b->kategori === SparepartBeli::PAKET,
                'nama' => $b->nama_item, 'spek' => $b->spesifikasi, 'golongan' => $b->golongan, 'tipe_unit' => $b->tipe_unit, 'no_unit' => $b->no_unit,
                'qty' => $b->qty, 'satuan' => $b->satuan, 'harga' => $b->harga_satuan, 'total' => $b->total, 'supplier' => $b->supplier, 'pic' => $b->pic,
                'keterangan' => $b->keterangan, 'sumber' => $b->sumber, 'ref' => $b->ref,
            ])->values(),
        ]);
    }

    /** Simpan Jenis / Part Number / Merk / Ori-KW / catatan satu item (semua kosong = hapus isian, kembali ke usulan otomatis). */
    public function simpanItem(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nama' => ['required', 'string', 'max:200'],
            'satuan' => ['nullable', 'string', 'max:20'],
            'jenis' => ['nullable', 'string', 'max:80'],
            'part_number' => ['nullable', 'string', 'max:80'],
            'merk' => ['nullable', 'string', 'max:80'],
            'kualitas' => ['nullable', Rule::in(array_keys(SparepartItem::KUALITAS))],
            'catatan' => ['nullable', 'string', 'max:300'],
        ]);
        abort_unless(SparepartBeli::where('nama_item', $d['nama'])->exists(), 422, 'Item tidak ada di Database Sparepart.');
        $rapi = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $isi = ['jenis' => $rapi($d['jenis'] ?? null), 'part_number' => ($p = $rapi($d['part_number'] ?? null)) === null ? null : strtoupper($p), 'merk' => $rapi($d['merk'] ?? null), 'kualitas' => $d['kualitas'] ?? null, 'catatan' => $rapi($d['catatan'] ?? null)];
        $kunci = ['nama_item' => $d['nama'], 'satuan' => $d['satuan'] ?? ''];

        if (! array_filter($isi)) {
            SparepartItem::where($kunci)->delete();
        } else {
            SparepartItem::updateOrCreate($kunci, [...$isi, 'user_id' => $request->user()->id]);
        }
        $label = trim($d['nama'].' '.($d['satuan'] ?? ''));
        KasRiwayat::create(['aksi' => 'sparepart-item', 'lembar' => 'Sparepart', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("Sparepart {$label}: jenis ".($isi['jenis'] ?? '—').', part number '.($isi['part_number'] ?? '—').', merk '.($isi['merk'] ?? '—').', '.(SparepartItem::KUALITAS[$isi['kualitas']] ?? 'Ori/KW kosong'), 0, 490, '…'),
            'isi' => [...$kunci, ...$isi], 'user_id' => $request->user()->id]);

        return response()->json(['ok' => true]);
    }

    public static function tgl(?string $t): string
    {
        return $t ? Carbon::parse($t)->translatedFormat('j M Y') : '';
    }
}
