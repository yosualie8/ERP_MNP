<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\PengajuanSparepart;
use App\Models\SparepartBeli;
use App\Support\DaftarSparepart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pengajuan Sparepart (owner 16 Nov 2026, menu Plant & Sparepart): pengguna mengetik nama sparepart + jenis, sistem mengusulkan item & harganya
 * dari Database Sparepart (harga terakhir, termurah, rata-rata, per tipe unit), subtotal = qty × harga, lalu diajukan ke manajemen.
 * Manajemen (hak 'pengajuan-sparepart-putus') menyetujui / menolak. Selama masih "Diajukan" pembuat boleh mengubah / membatalkan.
 */
class PengajuanSparepartController extends Controller
{
    public function index(Request $request): View
    {
        $status = array_key_exists($request->query('status'), PengajuanSparepart::STATUS) ? $request->query('status') : null;
        $semua = PengajuanSparepart::query()->with(['pembuat:id,name', 'pemutus:id,name'])->withCount('detail')
            ->orderByDesc('tanggal')->orderByDesc('id')->get();

        return view('pengajuan-sparepart.index', [
            'daftar' => $status ? $semua->where('status', $status)->values() : $semua,
            'jumlah' => $semua->countBy('status'), 'status' => $status,
        ]);
    }

    public function buat(): View
    {
        return view('pengajuan-sparepart.form', ['p' => new PengajuanSparepart(['tanggal' => now()]), 'baris' => [], ...$this->bahanForm()]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        [$kepala, $baris] = $this->baca($request);
        $p = DB::transaction(function () use ($request, $kepala, $baris) {
            $p = PengajuanSparepart::create([...$kepala, 'nomor' => PengajuanSparepart::nomorBaru(), 'status' => 'diajukan', 'user_id' => $request->user()->id]);
            $p->detail()->createMany($baris);

            return $p;
        });
        $this->catat($request, 'pengajuan-sp', "Pengajuan sparepart {$p->nomor}: ".count($baris).' item, Rp '.rp($p->total), $p->load('detail')->toArray());

        return redirect()->route('pengajuan-sparepart.lihat', $p)->with('success', "Pengajuan {$p->nomor} terkirim ke manajemen.");
    }

    public function lihat(Request $request, PengajuanSparepart $p): View
    {
        $p->load(['detail', 'pembuat:id,name', 'pemutus:id,name']);

        return view('pengajuan-sparepart.lihat', [
            'p' => $p, 'bolehUbah' => $this->bolehUbah($request, $p),
            'bolehPutus' => $p->status === 'diajukan' && $request->user()->bolehMenu('pengajuan-sparepart-putus'),
        ]);
    }

    public function ubah(Request $request, PengajuanSparepart $p): View
    {
        abort_unless($this->bolehUbah($request, $p), 403, 'Pengajuan ini tidak bisa diubah lagi.');
        $baris = $p->detail->map(fn ($d) => ['nama' => $d->nama_item, 'jenis' => $d->jenis, 'satuan' => $d->satuan, 'part' => $d->part_number, 'merk' => $d->merk,
            'qty' => $d->qty, 'harga' => $d->harga, 'saran' => $d->harga_saran, 'ket' => $d->keterangan])->all();

        return view('pengajuan-sparepart.form', ['p' => $p, 'baris' => $baris, ...$this->bahanForm()]);
    }

    public function perbarui(Request $request, PengajuanSparepart $p): RedirectResponse
    {
        abort_unless($this->bolehUbah($request, $p), 403, 'Pengajuan ini tidak bisa diubah lagi.');
        [$kepala, $baris] = $this->baca($request);
        DB::transaction(function () use ($p, $kepala, $baris) {
            $p->update($kepala);
            $p->detail()->delete();
            $p->detail()->createMany($baris);
        });
        $this->catat($request, 'pengajuan-sp-ubah', "Pengajuan sparepart {$p->nomor} diubah: ".count($baris).' item, Rp '.rp($p->total), $p->load('detail')->toArray());

        return redirect()->route('pengajuan-sparepart.lihat', $p)->with('success', "Pengajuan {$p->nomor} diperbarui.");
    }

    public function batal(Request $request, PengajuanSparepart $p): RedirectResponse
    {
        abort_unless($this->bolehUbah($request, $p), 403, 'Pengajuan ini tidak bisa dibatalkan lagi.');
        $p->update(['status' => 'batal']);
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

    /** Pembuat (atau Super Admin) selama status masih Diajukan. */
    private function bolehUbah(Request $request, PengajuanSparepart $p): bool
    {
        $u = $request->user();

        return $p->status === 'diajukan' && ($p->user_id === $u->id || $u->isSuperAdmin());
    }

    /** Katalog usulan dari Database Sparepart + daftar jenis & unit untuk autocomplete. */
    private function bahanForm(): array
    {
        $semua = SparepartBeli::query()->orderBy('tanggal')->orderBy('id')->toBase()->get();
        $katalog = DaftarSparepart::item($semua)->map(fn ($x) => [
            'n' => $x->nama, 's' => $x->satuan, 'k' => $x->kategori, 'j' => $x->jenis, 'p' => $x->part, 'm' => $x->merk,
            'h' => (float) $x->akhir->harga_satuan, 't' => SparepartController::tgl($x->akhir->tanggal), 'sup' => $x->akhir->supplier,
            'mu' => (float) $x->murah->harga_satuan, 'tm' => SparepartController::tgl($x->murah->tanggal), 'r' => round($x->rata), 'b' => $x->n,
            'u' => $x->per_unit->map(fn ($u) => [$u['tu'], $u['harga']])->values(),
            // istilah lapangan dari keterangan asli (dipotong supaya halaman tetap ringan)
            'c' => mb_substr($x->cari.' '.$x->golongan->implode(' ').' '.$x->per_unit->pluck('tu')->implode(' '), 0, 300),
        ])->values();

        return [
            'katalog' => $katalog,
            'jenis' => collect(DaftarSparepart::JENIS)->merge(\App\Models\SparepartItem::whereNotNull('jenis')->distinct()->pluck('jenis'))->unique()->values(),
            'unit' => SparepartBeli::whereNotNull('no_unit')->distinct()->orderBy('no_unit')->pluck('no_unit'),
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
            'item.*.jenis' => ['nullable', 'string', 'max:80'],
            'item.*.satuan' => ['nullable', 'string', 'max:20'],
            'item.*.part' => ['nullable', 'string', 'max:80'],
            'item.*.merk' => ['nullable', 'string', 'max:80'],
            'item.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'item.*.harga' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'item.*.saran' => ['nullable', 'numeric', 'min:0'],
            'item.*.ket' => ['nullable', 'string', 'max:300'],
        ], ['item.required' => 'Isi minimal satu sparepart.', 'item.*.qty.gt' => 'Qty setiap sparepart harus lebih dari 0.'], ['item.*.harga' => 'harga', 'item.*.qty' => 'jumlah']);

        $rapi = fn ($v) => ($v = trim(preg_replace('/\s+/', ' ', (string) $v))) === '' ? null : $v;
        $baris = collect($d['item'])->values()->map(fn ($b, $i) => [
            'urut' => $i + 1, 'nama_item' => $rapi($b['nama']), 'jenis' => $rapi($b['jenis'] ?? null), 'satuan' => $rapi($b['satuan'] ?? null),
            'part_number' => ($p = $rapi($b['part'] ?? null)) === null ? null : strtoupper($p), 'merk' => $rapi($b['merk'] ?? null),
            'qty' => round($b['qty'], 2), 'harga' => round($b['harga']), 'subtotal' => round(round($b['qty'], 2) * round($b['harga'])),
            'harga_saran' => $b['saran'] ?? null, 'keterangan' => $rapi($b['ket'] ?? null),
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
