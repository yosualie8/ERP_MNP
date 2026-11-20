<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\PengajuanSparepart;
use App\Models\PengajuanSparepartDetail;
use App\Models\PengajuanSparepartFoto;
use App\Models\SparepartBeli;
use App\Support\DaftarSparepart;
use App\Support\FotoBon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pengajuan Sparepart (owner 16 Nov 2026, menu Plant & Sparepart): pengguna mengetik nama sparepart + jenis, sistem mengusulkan item & harganya
 * dari Database Sparepart (harga terakhir, termurah, rata-rata, per tipe unit), subtotal = qty × harga, lalu diajukan ke manajemen.
 * Manajemen (hak 'pengajuan-sparepart-putus') menyetujui / menolak. Selama masih "Diajukan" pembuat boleh mengubah / membatalkan.
 * Revisi Admin Plant 17 Nov 2026: tiap baris ditandai Disetujui / Dibeli / Terpasang / Ditolak (berwarna) supaya atasan melihat progresnya,
 * dan baris yang belum di-ACC bisa diajukan ulang ke pengajuan baru (baris asal jadi "Diajukan ulang" dengan tautan ke pengajuan barunya).
 * Owner 17 Nov 2026: status barang = catatan Admin Plant sendiri — menandai & foto progres pekerjaan hanya dengan hak 'pengajuan-sparepart-tandai'
 * (sementara hanya jabatan Admin Plant); manajemen menyetujui pengajuan tidak mengubah status barang.
 */
class PengajuanSparepartController extends Controller
{
    /** Batas foto progres per sekali unggah. */
    public const MAKS_FOTO = 20;

    public function index(Request $request): View
    {
        // Owner 20 Nov 2026: pengajuan Dibatalkan disembunyikan dari daftar (membingungkan Admin Plant — status barangnya terkunci);
        // detailnya tetap bisa dibuka lewat tautan langsung.
        $status = $request->query('status') !== 'batal' && array_key_exists($request->query('status'), PengajuanSparepart::STATUS) ? $request->query('status') : null;
        $semua = PengajuanSparepart::query()->where('status', '!=', 'batal')->with(['pembuat:id,name', 'pemutus:id,name', 'detail:id,pengajuan_sparepart_id,progres'])->withCount(['detail', 'foto'])
            ->orderByDesc('tanggal')->orderByDesc('id')->get();

        return view('pengajuan-sparepart.index', [
            'daftar' => $status ? $semua->where('status', $status)->values() : $semua,
            'jumlah' => $semua->countBy('status'), 'status' => $status,
        ]);
    }

    /** ?dari=ID: ajukan ulang baris yang belum di-ACC dari pengajuan itu (keperluan & catatan ikut, tanggal hari ini). */
    public function buat(Request $request): View|RedirectResponse
    {
        $p = new PengajuanSparepart(['tanggal' => now()]);
        $baris = [];
        // ajukan ulang mengubah status baris asal → hanya hak tandai
        if ($request->filled('dari') && $request->user()->bolehMenu('pengajuan-sparepart-tandai')) {
            $asal = PengajuanSparepart::with('detail')->findOrFail($request->integer('dari'));
            $baris = $asal->status === 'batal' ? [] : $asal->detail->whereNull('progres')->map(fn ($d) => [...$this->isiForm($d), 'asal' => $d->id])->values()->all();
            if (! $baris) {
                return redirect()->route('pengajuan-sparepart.lihat', $asal)->withErrors('Tidak ada sparepart berstatus Belum di pengajuan ini untuk diajukan ulang.');
            }
            $p->fill(['keperluan' => $asal->keperluan, 'catatan' => $asal->catatan, 'asal_id' => $asal->id])->setRelation('asal', $asal);
        }

        return view('pengajuan-sparepart.form', ['p' => $p, 'baris' => $baris, ...$this->bahanForm()]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        [$kepala, $baris] = $this->baca($request);
        // Ajukan ulang: baris hanya boleh menunjuk baris pengajuan asal yang masih Belum.
        $asal = $request->filled('asal_id') && $request->user()->bolehMenu('pengajuan-sparepart-tandai') ? PengajuanSparepart::where('status', '!=', 'batal')->find($request->integer('asal_id')) : null;
        $bolehAsal = $asal ? $asal->detail()->whereNull('progres')->pluck('id')->all() : [];
        $baris = array_map(fn ($b) => [...array_diff_key($b, ['id_lama' => 0]),
            'asal_detail_id' => in_array($b['asal_detail_id'], $bolehAsal, true) ? $b['asal_detail_id'] : null], $baris);
        $p = DB::transaction(function () use ($request, $kepala, $baris, $asal) {
            $p = PengajuanSparepart::create([...$kepala, 'nomor' => PengajuanSparepart::nomorBaru($kepala['tanggal']), 'status' => 'diajukan', 'user_id' => $request->user()->id,
                'asal_id' => $asal?->id]);
            $p->detail()->createMany($baris);
            $this->sinkronAsal($p);

            return $p;
        });
        $this->catat($request, 'pengajuan-sp', "Pengajuan sparepart {$p->nomor}: ".count($baris).' item, Rp '.rp($p->total), $p->load('detail')->toArray());

        return redirect()->route('pengajuan-sparepart.lihat', $p)->with('success', "Pengajuan {$p->nomor} terkirim ke manajemen. Tekan 📤 Bagikan ke WhatsApp untuk mengirim gambar pengajuan + total harganya.");
    }

    public function lihat(Request $request, PengajuanSparepart $p): View
    {
        $p->load(['detail.penanda:id,name', 'detail.lanjutan.pengajuan:id,nomor,status', 'pembuat:id,name', 'pemutus:id,name', 'asal:id,nomor', 'lanjutan:id,asal_id,nomor,status', 'foto.pengunggah:id,name']);

        return view('pengajuan-sparepart.lihat', [
            'p' => $p, 'bolehUbah' => $this->bolehUbah($request, $p),
            'bolehTanda' => $p->status !== 'batal' && $request->user()->bolehMenu('pengajuan-sparepart-tandai'),
            'bolehPutus' => $p->status === 'diajukan' && $request->user()->bolehMenu('pengajuan-sparepart-putus'),
        ]);
    }

    public function ubah(Request $request, PengajuanSparepart $p): View
    {
        abort_unless($this->bolehUbah($request, $p), 403, 'Pengajuan ini tidak bisa diubah lagi.');
        $baris = $p->detail->map(fn ($d) => [...$this->isiForm($d), 'id' => $d->id])->all();

        return view('pengajuan-sparepart.form', ['p' => $p, 'baris' => $baris, ...$this->bahanForm()]);
    }

    /** Baris detail → isian form (ubah / ajukan ulang). */
    private function isiForm(PengajuanSparepartDetail $d): array
    {
        return ['nama' => $d->nama_item, 'jenis' => $d->jenis, 'satuan' => $d->satuan, 'part' => $d->part_number, 'merk' => $d->merk, 'supplier' => $d->supplier,
            'qty' => $d->qty, 'harga' => $d->harga, 'saran' => $d->harga_saran, 'dasar' => $d->dasar_harga, 'ket' => $d->keterangan];
    }

    public function perbarui(Request $request, PengajuanSparepart $p): RedirectResponse
    {
        abort_unless($this->bolehUbah($request, $p), 403, 'Pengajuan ini tidak bisa diubah lagi.');
        [$kepala, $baris] = $this->baca($request);
        // Baris lama yang masih ada (id dari form) tetap membawa status & asal ajukan ulangnya; baris baru = Belum.
        $lama = $p->detail->keyBy('id');
        $baris = array_map(function ($b) use ($lama) {
            $d = $lama[$b['id_lama']] ?? null;

            return [...array_diff_key($b, ['id_lama' => 0]), 'asal_detail_id' => $d?->asal_detail_id,
                'progres' => $d?->progres, 'progres_pada' => $d?->progres_pada, 'progres_oleh' => $d?->progres_oleh];
        }, $baris);
        DB::transaction(function () use ($p, $kepala, $baris, $lama) {
            $p->update($kepala);
            $p->detail()->delete();
            $p->detail()->createMany($baris);
            $this->sinkronAsal($p, $lama->pluck('asal_detail_id')->filter()->all());
        });
        $this->catat($request, 'pengajuan-sp-ubah', "Pengajuan sparepart {$p->nomor} diubah: ".count($baris).' item, Rp '.rp($p->total), $p->load('detail')->toArray());

        return redirect()->route('pengajuan-sparepart.lihat', $p)->with('success', "Pengajuan {$p->nomor} diperbarui.");
    }

    public function batal(Request $request, PengajuanSparepart $p): RedirectResponse
    {
        abort_unless($this->bolehUbah($request, $p), 403, 'Pengajuan ini tidak bisa dibatalkan lagi.');
        DB::transaction(function () use ($p) {
            $sebelum = $p->detail()->whereNotNull('asal_detail_id')->pluck('asal_detail_id')->all();
            $p->update(['status' => 'batal']);
            $this->sinkronAsal($p, $sebelum);
        });
        $this->catat($request, 'pengajuan-sp-batal', "Pengajuan sparepart {$p->nomor} dibatalkan", ['id' => $p->id, 'nomor' => $p->nomor]);

        return redirect()->route('pengajuan-sparepart.lihat', $p)->with('success', "Pengajuan {$p->nomor} dibatalkan.");
    }

    /** Manajemen menyetujui / menolak (hak 'pengajuan-sparepart-putus'). */
    public function putus(Request $request, PengajuanSparepart $p): RedirectResponse
    {
        abort_unless($p->status === 'diajukan', 422, 'Pengajuan ini sudah diputus / dibatalkan.');
        $d = $request->validate(['putusan' => ['required', Rule::in(['disetujui', 'ditolak'])], 'catatan_putusan' => ['nullable', 'string', 'max:500']]);
        $p->update(['status' => $d['putusan'], 'diputus_oleh' => $request->user()->id, 'diputus_pada' => now(), 'catatan_putusan' => trim((string) ($d['catatan_putusan'] ?? '')) ?: null]);
        $label = PengajuanSparepart::STATUS[$d['putusan']];
        $this->catat($request, 'pengajuan-sp-putus', "Pengajuan sparepart {$p->nomor} {$label}", ['id' => $p->id, 'nomor' => $p->nomor, 'status' => $d['putusan'], 'catatan' => $p->catatan_putusan]);

        return redirect()->route('pengajuan-sparepart.lihat', $p)->with('success', "Pengajuan {$p->nomor} {$label}.");
    }

    /**
     * Tandai status baris (revisi Admin Plant 17 Nov 2026): Disetujui / Dibeli / Terpasang / Ditolak, kosong = kembali Belum. Hanya hak
     * 'pengajuan-sparepart-tandai' (dijaga middleware menu lewat nama rute), selama pengajuan tidak dibatalkan.
     * Baris "Diajukan ulang" diikuti di pengajuan barunya, tidak ditandai di sini.
     */
    public function progres(Request $request, PengajuanSparepart $p): JsonResponse
    {
        abort_if($p->status === 'batal', 422, 'Pengajuan ini sudah dibatalkan.');
        $d = $request->validate([
            'detail' => ['required', 'array', 'min:1'], 'detail.*' => ['integer'],
            'progres' => ['nullable', Rule::in(array_keys(PengajuanSparepartDetail::PROGRES))],
        ]);
        $baris = $p->detail()->whereIn('id', $d['detail'])
            ->where(fn ($q) => $q->whereNull('progres')->orWhere('progres', '!=', PengajuanSparepartDetail::DIULANG))->get();
        abort_if($baris->isEmpty(), 422, 'Baris tidak ditemukan / sudah diajukan ulang.');
        $nilai = $d['progres'] ?? null;
        PengajuanSparepartDetail::whereIn('id', $baris->pluck('id'))
            ->update(['progres' => $nilai, 'progres_pada' => $nilai ? now() : null, 'progres_oleh' => $nilai ? $request->user()->id : null]);
        $label = PengajuanSparepartDetail::labelProgres($nilai);
        $this->catat($request, 'pengajuan-sp-progres', "Pengajuan sparepart {$p->nomor}: ".$baris->pluck('nama_item')->implode(', ')." → {$label}",
            ['id' => $p->id, 'nomor' => $p->nomor, 'detail' => $baris->pluck('id'), 'dari' => $baris->pluck('progres', 'id'), 'progres' => $nilai]);

        return response()->json([
            'label' => $label, 'oleh' => $nilai ? $request->user()->name : null, 'pada' => $nilai ? now()->translatedFormat('j M H:i') : null,
            'ringkas' => $p->load('detail:id,pengajuan_sparepart_id,progres')->progres(),
        ]);
    }

    /** Foto progres pekerjaan (hak tandai): diperkecil di browser, di server dibatasi sisi terpanjang 2000 px + pratinjau kecil. */
    public function unggahFoto(Request $request, PengajuanSparepart $p): RedirectResponse
    {
        abort_if($p->status === 'batal', 422, 'Pengajuan ini sudah dibatalkan.');
        $d = $request->validate([
            'foto' => ['required', 'array', 'min:1', 'max:'.self::MAKS_FOTO], 'foto.*' => ['image', 'max:15360'],
            'keterangan' => ['nullable', 'string', 'max:300'],
        ], ['foto.required' => 'Pilih minimal satu foto.', 'foto.max' => 'Maksimal '.self::MAKS_FOTO.' foto sekali unggah.',
            'foto.*.image' => 'File harus berupa foto (JPG/PNG).', 'foto.*.max' => 'Satu foto maksimal 15 MB.']);
        $ket = trim(preg_replace('/\s+/', ' ', (string) ($d['keterangan'] ?? ''))) ?: null;
        $baru = [];
        foreach ($request->file('foto') as $file) {
            $dasar = "pengajuan-sparepart/{$p->id}/".now()->format('ymd-His').'-'.Str::random(6);
            [$penuh] = FotoBon::perkecil((string) file_get_contents($file->getRealPath()), FotoBon::MAKS_SISI, 82, $file);
            [$kecil] = FotoBon::perkecil($penuh, FotoBon::SISI_KECIL, 72);
            Storage::put("{$dasar}.jpg", $penuh);
            Storage::put("{$dasar}-kecil.jpg", $kecil);
            $baru[] = PengajuanSparepartFoto::create(['pengajuan_sparepart_id' => $p->id, 'keterangan' => $ket, 'path' => "{$dasar}.jpg",
                'path_kecil' => "{$dasar}-kecil.jpg", 'ukuran' => strlen($penuh), 'user_id' => $request->user()->id])->id;
        }
        $this->catat($request, 'pengajuan-sp-foto', "Pengajuan sparepart {$p->nomor}: ".count($baru).' foto progres'.($ket ? " — {$ket}" : ''),
            ['id' => $p->id, 'nomor' => $p->nomor, 'foto' => $baru, 'keterangan' => $ket]);

        return redirect()->to(route('pengajuan-sparepart.lihat', $p).'#foto-progres')->with('success', count($baru).' foto progres ditambahkan.');
    }

    /** ?kecil=1 = pratinjau. Siapa pun yang punya menu Pengajuan Sparepart boleh melihat. */
    public function foto(Request $request, PengajuanSparepartFoto $foto): BinaryFileResponse
    {
        $path = $request->boolean('kecil') && $foto->path_kecil && Storage::exists($foto->path_kecil) ? $foto->path_kecil : $foto->path;
        abort_unless(Storage::exists($path), 404);

        return response()->file(Storage::path($path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800']);
    }

    public function hapusFoto(Request $request, PengajuanSparepartFoto $foto): RedirectResponse
    {
        Storage::delete(array_filter([$foto->path, $foto->path_kecil]));
        $foto->delete();
        $p = $foto->pengajuan;
        // kas_riwayat.aksi maks. 20 huruf
        $this->catat($request, 'pengajuan-sp-fotohps', "Pengajuan sparepart {$p?->nomor}: foto progres dihapus", ['id' => $p?->id, 'foto' => $foto->id, 'keterangan' => $foto->keterangan]);

        return redirect()->to(route('pengajuan-sparepart.lihat', $foto->pengajuan_sparepart_id).'#foto-progres')->with('success', 'Foto progres dihapus.');
    }

    /** Baris asal yang dibawa pengajuan ini = "Diajukan ulang"; yang tidak dibawa lagi (barisnya dihapus / pengajuan dibatalkan) kembali Belum. */
    private function sinkronAsal(PengajuanSparepart $p, array $sebelum = []): void
    {
        $kini = $p->status === 'batal' ? [] : $p->detail()->whereNotNull('asal_detail_id')->pluck('asal_detail_id')->all();
        if ($lepas = array_diff($sebelum, $kini)) {
            PengajuanSparepartDetail::whereIn('id', $lepas)->where('progres', PengajuanSparepartDetail::DIULANG)
                ->update(['progres' => null, 'progres_pada' => null, 'progres_oleh' => null]);
        }
        if ($kini) {
            PengajuanSparepartDetail::whereIn('id', $kini)->whereNull('progres')
                ->update(['progres' => PengajuanSparepartDetail::DIULANG, 'progres_pada' => now(), 'progres_oleh' => $p->user_id]);
        }
    }

    /** Pembuat (atau Super Admin) selama status masih Diajukan. */
    private function bolehUbah(Request $request, PengajuanSparepart $p): bool
    {
        $u = $request->user();

        return $p->status === 'diajukan' && ($p->user_id === $u->id || $u->isSuperAdmin());
    }

    /**
     * Katalog usulan dari Database Sparepart. Harga dihitung PER JENIS ALAT (owner 16 Nov 2026: filter truk ya harga truk, alat berat ya alat berat —
     * jangan dicampur) dan di dalamnya per tipe unit; "Umum" = semua pembelian (barang yang tidak terikat alat). Pembelian tanpa unit hanya masuk "Umum".
     */
    private function bahanForm(): array
    {
        $semua = SparepartBeli::query()->orderBy('tanggal')->orderBy('id')->toBase()->get();
        $tgl = fn ($t) => SparepartController::tgl($t);
        $ringkas = function ($bs) use ($tgl) {
            $akhir = $bs->last();
            $murah = $bs->sortBy('harga_satuan')->first();

            return [
                'h' => (float) $akhir->harga_satuan, 't' => $tgl($akhir->tanggal), 'sup' => $akhir->supplier, 'b' => $bs->count(),
                'mu' => (float) $murah->harga_satuan, 'tm' => $tgl($murah->tanggal), 'sm' => $murah->supplier, 'r' => round((float) $bs->avg('harga_satuan')),
                // harga terakhir per tipe unit di jenis ini: [tipe, harga, kali beli, tanggal, supplier]
                'u' => $bs->filter(fn ($b) => $b->tipe_unit)->groupBy('tipe_unit')->sortByDesc(fn ($x) => $x->count())
                    ->map(fn ($x, $tu) => [$tu, (float) $x->last()->harga_satuan, $x->count(), $tgl($x->last()->tanggal), $x->last()->supplier])->values(),
            ];
        };
        $beli = $semua->reject(fn ($b) => in_array($b->kategori, SparepartBeli::BUKAN_ITEM, true))->groupBy(fn ($b) => $b->nama_item.'|'.$b->satuan);

        $katalog = DaftarSparepart::item($semua)->map(function ($x) use ($beli, $ringkas) {
            $bs = $beli[$x->nama.'|'.$x->satuan] ?? collect();
            $perJenis = $bs->groupBy(fn ($b) => DaftarSparepart::jenisGolongan($b->golongan) ?? '')->forget('')
                ->map(fn ($x) => $ringkas($x->values()));

            return [
                'n' => $x->nama, 's' => $x->satuan, 'k' => $x->kategori, 'p' => $x->part, 'm' => $x->merk,
                'J' => [...$perJenis->all(), 'Umum' => $ringkas($bs->values())],
                // istilah lapangan dari keterangan asli (dipotong supaya halaman tetap ringan)
                'c' => mb_substr($x->cari.' '.$x->golongan->implode(' ').' '.$x->per_unit->pluck('tu')->implode(' '), 0, 300),
            ];
        })->values();

        // Unit di kolom Keperluan → tipe unit & jenis (DT 049 = DT Isuzu Giga, Truck): harga tipe itu diutamakan.
        $unit = $semua->filter(fn ($b) => $b->no_unit && $b->tipe_unit)->groupBy('no_unit')
            ->map(fn ($x) => [$x->last()->tipe_unit, DaftarSparepart::jenisGolongan($x->last()->golongan)])->sortKeys();
        $tipe = $semua->filter(fn ($b) => $b->tipe_unit && DaftarSparepart::jenisGolongan($b->golongan))->groupBy('tipe_unit')
            ->map(fn ($x) => DaftarSparepart::jenisGolongan($x->last()->golongan));

        return [
            'katalog' => $katalog, 'unitPeta' => $unit, 'tipePeta' => $tipe,
            'jenis' => DaftarSparepart::JENIS,
            'unit' => $unit->keys()->merge($tipe->keys())->unique()->values(),
            'satuan' => SparepartBeli::whereNotNull('satuan')->distinct()->orderBy('satuan')->pluck('satuan'),
        ];
    }

    /** @return array{0: array, 1: array<int, array>} kepala pengajuan + baris detail (subtotal dihitung ulang di server) */
    private function baca(Request $request): array
    {
        // angka dari form: "1.250.000" (titik ribuan) / "1,5" (koma desimal) → float
        $angka = fn ($v) => is_numeric($v) ? (float) $v : (float) str_replace(',', '.', str_replace('.', '', (string) $v));
        $isi = $request->input('item', []);
        $request->merge(['item' => collect(is_array($isi) ? $isi : [])
            ->filter(fn ($b) => is_array($b) && trim((string) ($b['nama'] ?? '')) !== '')
            ->map(fn ($b) => [...$b, 'qty' => $angka($b['qty'] ?? 0), 'harga' => $angka($b['harga'] ?? 0), 'saran' => ($b['saran'] ?? '') === '' ? null : $angka($b['saran'])])
            ->values()->all()]);

        $d = $request->validate([
            'tanggal' => ['required', 'date'],
            'keperluan' => ['nullable', 'string', 'max:200'],
            'catatan' => ['nullable', 'string', 'max:500'],
            'item' => ['required', 'array', 'min:1', 'max:200'],
            'item.*.nama' => ['required', 'string', 'max:200'],
            'item.*.jenis' => ['required', Rule::in(DaftarSparepart::JENIS)],
            'item.*.dasar' => ['nullable', 'string', 'max:120'],
            'item.*.satuan' => ['nullable', 'string', 'max:20'],
            'item.*.part' => ['nullable', 'string', 'max:80'],
            'item.*.merk' => ['nullable', 'string', 'max:80'],
            'item.*.supplier' => ['nullable', 'string', 'max:150'],
            'item.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'item.*.harga' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'item.*.saran' => ['nullable', 'numeric', 'min:0'],
            'item.*.ket' => ['nullable', 'string', 'max:300'],
            'item.*.id' => ['nullable', 'integer'],      // ubah: baris lama (status & asal dipertahankan)
            'item.*.asal' => ['nullable', 'integer'],    // ajukan ulang: baris pengajuan asal
        ], ['item.required' => 'Isi minimal satu sparepart.', 'item.*.qty.gt' => 'Qty setiap sparepart harus lebih dari 0.',
            'item.*.jenis.required' => 'Jenis alat setiap sparepart wajib diisi.', 'item.*.jenis.in' => 'Jenis alat harus salah satu dari: '.implode(', ', DaftarSparepart::JENIS).'.'], ['item.*.harga' => 'harga', 'item.*.qty' => 'jumlah']);

        $rapi = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $baris = collect($d['item'])->values()->map(fn ($b, $i) => [
            'urut' => $i + 1, 'nama_item' => $rapi($b['nama']), 'jenis' => $rapi($b['jenis'] ?? null), 'satuan' => $rapi($b['satuan'] ?? null),
            'part_number' => ($p = $rapi($b['part'] ?? null)) === null ? null : strtoupper($p), 'merk' => $rapi($b['merk'] ?? null),
            'supplier' => $rapi($b['supplier'] ?? null),
            'qty' => round($b['qty'], 2), 'harga' => round($b['harga']), 'subtotal' => round(round($b['qty'], 2) * round($b['harga'])),
            'harga_saran' => $b['saran'] ?? null, 'dasar_harga' => $rapi($b['dasar'] ?? null), 'keterangan' => $rapi($b['ket'] ?? null),
            'id_lama' => isset($b['id']) ? (int) $b['id'] : null, 'asal_detail_id' => isset($b['asal']) ? (int) $b['asal'] : null,
        ])->all();

        return [['tanggal' => $d['tanggal'], 'keperluan' => $rapi($d['keperluan'] ?? null), 'catatan' => $rapi($d['catatan'] ?? null),
            'total' => array_sum(array_column($baris, 'subtotal'))], $baris];
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Sparepart', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'), 'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
