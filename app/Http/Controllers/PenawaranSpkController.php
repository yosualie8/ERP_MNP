<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\PenawaranSpk;
use App\Models\ProyekTahap;
use App\Support\SinkronGmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Penawaran & SPK dari Gmail MNP (owner 11 Nov 2026): daftar di kiri, PDF + isian (bisa dikoreksi) di kanan.
 * SPK ditautkan ke tahap → omset tahap = jumlah nilai SPK tertaut (volume dijumlah, harga/m³ bila sama).
 */
class PenawaranSpkController extends Controller
{
    public const TAMPIL = ['baru' => 'SPK belum dicek', 'ditautkan' => 'SPK sudah ditautkan', 'penawaran' => 'Penawaran', 'diabaikan' => 'Diabaikan', 'semua' => 'Semua'];

    public function index(Request $request): View
    {
        $tampil = array_key_exists($request->query('tampil'), self::TAMPIL) ? $request->query('tampil') : 'baru';
        $q = PenawaranSpk::with(['tahap', 'saranTahap', 'user', 'penawaran:id,kode,nilai,volume,harga']);
        match ($tampil) {
            'baru', 'ditautkan' => $q->where('jenis', 'spk')->where('status', $tampil),
            'penawaran' => $q->where('jenis', 'penawaran'),
            'diabaikan' => $q->where('status', 'diabaikan'),
            default => null,
        };
        $jumlah = [
            'baru' => PenawaranSpk::where('jenis', 'spk')->where('status', 'baru')->count(),
            'ditautkan' => PenawaranSpk::where('jenis', 'spk')->where('status', 'ditautkan')->count(),
            'penawaran' => PenawaranSpk::where('jenis', 'penawaran')->count(),
            'diabaikan' => PenawaranSpk::where('status', 'diabaikan')->count(),
            'semua' => PenawaranSpk::count(),
        ];
        $tahap = SinkronGmail::calonTahap();
        $terpakai = PenawaranSpk::whereNotNull('proyek_tahap_id')->pluck('proyek_tahap_id')->merge(PenawaranSpk::whereNotNull('saran_tahap_id')->pluck('saran_tahap_id'));
        if ($terpakai->diff($tahap->pluck('id'))->isNotEmpty()) {
            $tahap = $tahap->merge(ProyekTahap::with('proyek')->whereIn('id', $terpakai->diff($tahap->pluck('id')))->get());
        }

        return view('penawaran-spk.index', [
            'baris' => $q->orderByDesc('tanggal')->orderByDesc('waktu_email')->get(),
            'tampil' => $tampil, 'jumlah' => $jumlah,
            'tahap' => $tahap->map(fn ($t) => ['id' => $t->id, 'nama' => $t->nama, 'lokasi' => $t->lokasi, 'status' => $t->status, 'omset' => $t->omset, 'volume' => $t->volume])->values(),
            'pilih' => (int) $request->query('pilih'),
        ]);
    }

    public function pdf(PenawaranSpk $dok): BinaryFileResponse
    {
        abort_unless(Storage::exists($dok->path), 404);

        return response()->file(Storage::path($dok->path), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.addslashes($dok->nama_file).'"']);
    }

    public function ubah(Request $request, PenawaranSpk $dok): RedirectResponse
    {
        $angka = fn ($v) => $v === null || $v === '' ? null : SinkronGmail::angka((string) $v);
        $request->merge(['volume' => $angka($request->input('volume')), 'harga' => $angka($request->input('harga')), 'nilai' => $angka($request->input('nilai'))]);
        $data = $request->validate([
            'kode' => ['nullable', 'string', 'max:60'], 'nomor' => ['nullable', 'string', 'max:100'], 'tanggal' => ['nullable', 'date'],
            'pihak' => ['nullable', 'string', 'max:200'], 'pekerjaan' => ['nullable', 'string', 'max:2000'], 'lokasi' => ['nullable', 'string', 'max:250'],
            'volume' => ['nullable', 'numeric', 'min:0'], 'harga' => ['nullable', 'numeric', 'min:0'], 'nilai' => ['nullable', 'numeric', 'min:0'],
            'catatan' => ['nullable', 'string', 'max:500'], 'proyek_tahap_id' => ['nullable', 'exists:proyek_tahap,id'],
            'aksi' => ['required', 'in:simpan,tautkan,abaikan,kembalikan'],
        ]);
        $aksi = $data['aksi'];
        if ($aksi === 'tautkan' && (! $data['proyek_tahap_id'] || ! $data['nilai'])) {
            return back()->withErrors(['proyek_tahap_id' => 'Pilih tahap dan isi nilai SPK dulu sebelum menautkan.'])->withInput();
        }
        $sebelum = $dok->only(['kode', 'nilai', 'volume', 'harga', 'status', 'proyek_tahap_id']);
        $tahapLama = $dok->proyek_tahap_id;
        $pesan = DB::transaction(function () use ($dok, $data, $aksi, $request, $tahapLama) {
            $dok->fill(collect($data)->except(['aksi', 'proyek_tahap_id'])->all());
            $dok->nilai = $data['nilai'] !== null ? (int) round($data['nilai']) : null;
            $dok->user_id = $request->user()->id;
            $dok->dicek_at = now();
            match ($aksi) {
                'tautkan' => $dok->fill(['status' => 'ditautkan', 'proyek_tahap_id' => $data['proyek_tahap_id']]),
                'abaikan' => $dok->fill(['status' => 'diabaikan', 'proyek_tahap_id' => null]),
                'kembalikan' => $dok->fill(['status' => 'baru', 'proyek_tahap_id' => null]),
                default => $dok->fill(['saran_tahap_id' => $data['proyek_tahap_id'] ?? $dok->saran_tahap_id]),
            };
            $dok->save();

            $pesan = "{$dok->kode} disimpan.";
            foreach (array_unique(array_filter([$tahapLama, $dok->proyek_tahap_id])) as $id) {
                $t = ProyekTahap::find($id);
                $baru = $t ? SinkronGmail::hitungTahap($t) : null;
                if ($baru) {
                    $lama = $t->only(array_keys($baru));
                    $t->update([...$baru, 'user_id' => $request->user()->id]);
                    $pesan .= " Omset {$t->nama}: ".rp($lama['omset'] ?? null).' → '.rp($t->omset).'.';
                } elseif ($t) {
                    $pesan .= " {$t->nama} tidak punya SPK tertaut lagi — omsetnya tidak diubah (".rp($t->omset).'), ubah di Daftar Proyek bila perlu.';
                }
            }

            return $pesan;
        });
        KasRiwayat::create(['aksi' => 'penawaran-spk', 'lembar' => 'Penawaran & SPK', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
            'ringkasan' => mb_strimwidth("{$aksi} {$dok->jenis} {$dok->kode}: {$pesan}", 0, 490, '…'),
            'isi' => ['dok' => $dok->id, 'sebelum' => $sebelum, 'sesudah' => $dok->only(array_keys($sebelum))]]);

        // Sesudah ditautkan/diabaikan, lanjut ke SPK berikutnya yang belum dicek.
        $berikut = in_array($aksi, ['tautkan', 'abaikan'], true)
            ? PenawaranSpk::where('jenis', 'spk')->where('status', 'baru')->orderByDesc('tanggal')->orderByDesc('waktu_email')->value('id') : $dok->id;

        return redirect()->route('penawaran-spk.index', ['tampil' => $request->input('tampil', 'baru'), 'pilih' => $berikut])->with('success', $pesan);
    }
}
