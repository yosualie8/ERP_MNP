<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\UjPengajuan;
use App\Models\UjPengajuanDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengajuan Uang Jalan: form & validasi sama persis dengan Input UJ (memakai bacaInput/wajibKonfirmasi milik UjController),
 * tetapi disimpan di aplikasi saja (tidak ditulis ke sheet). Direalisasikan per detail lewat Input UJ → "Ambil dari pengajuan".
 */
class PengajuanUjController extends UjController
{
    public function daftar(Request $request): View
    {
        $status = array_key_exists($request->query('status'), UjPengajuan::STATUS) ? $request->query('status') : null;
        $beda = $request->boolean('beda');
        $semua = UjPengajuan::with(['detail', 'user'])->orderByDesc('tanggal')->orderByDesc('id')->get();
        $terbuka = fn ($p) => in_array($p->status, ['diajukan', 'sebagian'], true);
        $adaBeda = fn ($p) => $p->detail->contains(fn ($d) => $d->berbeda());
        $detailBeda = $semua->flatMap->detail->filter(fn ($d) => $d->berbeda());

        return view('uj.pengajuan', [
            'pengajuan' => match (true) {
                $beda => $semua->filter($adaBeda)->values(),
                (bool) $status => $semua->where('status', $status)->values(),
                default => $semua->filter($terbuka)->merge($semua->reject($terbuka)->take(50))->values(),
            },
            'semua' => $semua, 'status' => $status, 'beda' => $beda, 'detailBeda' => $detailBeda,
            'menunggu' => UjPengajuanDetail::where('status', 'menunggu')->selectRaw('COUNT(*) n, SUM(nominal) s')->first(),
            'bolehRealisasi' => $request->user()->bolehMenu('input-uj'),
        ]);
    }

    public function buat(): View
    {
        return $this->create()->with('mode', 'pengajuan');
    }

    public function simpan(Request $request): RedirectResponse
    {
        $input = $this->bacaInput($request, true);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        $temuan = $this->wajibKonfirmasi($input, null, []);
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        $p = DB::transaction(function () use ($input, $temuan, $request) {
            // Pengajuan = daftar transaksi (tanpa penerima/rekening); nominal = jumlah detail.
            $p = UjPengajuan::create(['tanggal' => $input['tanggal']->toDateString(), 'nama' => null, 'bank' => null, 'rekening' => null,
                'nominal' => $input['nominal'], 'status' => 'diajukan', 'user_id' => $request->user()->id]);
            $this->simpanDetail($p, $input, $temuan);

            return $p;
        });
        KasRiwayat::create(['aksi' => 'uj-ajukan', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($p->kode().' '.$this->ringkas($p), 0, 490, '…'), 'isi' => ['pengajuan_id' => $p->id], 'user_id' => $request->user()->id]);

        return redirect()->route('pengajuan-uj.daftar')->with('success', "Pengajuan {$p->kode()} tersimpan: ".$this->ringkas($p).'.');
    }

    public function ubah(UjPengajuan $pengajuan): View|RedirectResponse
    {
        if ($tolak = $this->tolakUbah($pengajuan)) {
            return redirect()->route('pengajuan-uj.daftar')->with('error', $tolak);
        }
        $edit = [
            'pengajuan_id' => $pengajuan->id, 'kode' => $pengajuan->kode(), 'tanggal' => $pengajuan->tanggal->toDateString(),
            'nominal' => $pengajuan->nominal, 'biaya_transfer' => '0',
            'detail' => $pengajuan->detail->map(fn ($d) => $d->only(['nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do', 'konfirmasi']))->all(),
        ];

        return $this->create()->with(['mode' => 'pengajuan', 'edit' => $edit]);
    }

    public function simpanUbah(Request $request, UjPengajuan $pengajuan): RedirectResponse
    {
        if ($tolak = $this->tolakUbah($pengajuan)) {
            return redirect()->route('pengajuan-uj.daftar')->with('error', $tolak);
        }
        $input = $this->bacaInput($request, true);
        if ($input instanceof RedirectResponse) {
            return $input;
        }
        $temuan = $this->wajibKonfirmasi($input, null, $pengajuan->detail->pluck('id')->all());
        if ($temuan instanceof RedirectResponse) {
            return $temuan;
        }
        DB::transaction(function () use ($pengajuan, $input, $temuan) {
            $pengajuan->update(['tanggal' => $input['tanggal']->toDateString(), 'nominal' => $input['nominal']]);
            $pengajuan->detail()->delete();
            $this->simpanDetail($pengajuan, $input, $temuan);
        });
        KasRiwayat::create(['aksi' => 'uj-ajukan-ubah', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($pengajuan->kode().' diubah: '.$this->ringkas($pengajuan->fresh()), 0, 490, '…'), 'isi' => ['pengajuan_id' => $pengajuan->id], 'user_id' => $request->user()->id]);

        return redirect()->route('pengajuan-uj.daftar')->with('success', "Pengajuan {$pengajuan->kode()} diperbarui.");
    }

    /** Batalkan detail yang masih menunggu (detail yang sudah terealisasi tidak berubah). */
    public function batal(Request $request, UjPengajuan $pengajuan): RedirectResponse
    {
        $n = $pengajuan->detail()->where('status', 'menunggu')->update(['status' => 'batal']);
        $pengajuan->hitungStatus();
        KasRiwayat::create(['aksi' => 'uj-ajukan-batal', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "{$pengajuan->kode()} dibatalkan ({$n} detail)", 'isi' => ['pengajuan_id' => $pengajuan->id], 'user_id' => $request->user()->id]);

        return back()->with('success', "Pengajuan {$pengajuan->kode()}: {$n} detail yang menunggu dibatalkan.");
    }

    /** Untuk panel "Ambil dari pengajuan" di Input UJ: semua detail yang masih menunggu, per pengajuan. */
    public function terbuka(): JsonResponse
    {
        $p = UjPengajuan::whereIn('status', ['diajukan', 'sebagian'])
            ->with(['detail' => fn ($q) => $q->where('status', 'menunggu'), 'user'])->orderBy('tanggal')->orderBy('id')->get()
            ->filter(fn ($p) => $p->detail->isNotEmpty());

        return response()->json(['pengajuan' => $p->map(fn ($p) => [
            'id' => $p->id, 'kode' => $p->kode(), 'tanggal' => $p->tanggal->toDateString(), 'tgl' => $p->tanggal->translatedFormat('j M Y'),
            'driver' => $p->detail->pluck('nama')->filter()->unique()->implode(', '), 'oleh' => $p->user?->name ?? $p->user?->email,
            'detail' => $p->detail->map(fn ($d) => [
                'pengajuan' => $d->id, 'nama' => $d->nama, 'keterangan' => $d->keterangan, 'nominal' => (int) $d->nominal,
                'kategori' => $d->kategori, 'jenis_kendaraan' => $d->jenis_kendaraan, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do,
            ])->values(),
        ])->values()]);
    }

    private function simpanDetail(UjPengajuan $p, array $input, array $temuan): void
    {
        foreach ($input['detail'] as $i => $d) {
            UjPengajuanDetail::create([
                'uj_pengajuan_id' => $p->id, 'urut' => $i + 1, 'nama' => $d['nama'], 'keterangan' => $d['keterangan'], 'nominal' => $d['nominal'],
                'kategori' => $d['kategori'], 'jenis_kendaraan' => $d['jenis_kendaraan'], 'no_mobil' => $d['no_mobil'], 'no_do' => $d['no_do'],
                'konfirmasi' => $d['konfirmasi'], 'temuan' => $temuan[$i] ?? null, 'status' => 'menunggu',
            ]);
        }
    }

    private function tolakUbah(UjPengajuan $p): ?string
    {
        return $p->detail()->where('status', '!=', 'menunggu')->exists()
            ? "Pengajuan {$p->kode()} sudah (sebagian) direalisasikan atau dibatalkan — tidak bisa diubah. Batalkan sisanya lalu ajukan ulang bila perlu."
            : null;
    }

    private function ringkas(UjPengajuan $p): string
    {
        $d = $p->detail()->get();

        return rp($p->nominal).' '.$p->tanggal->translatedFormat('j M Y').' ('.$d->count().' transaksi · '.$d->pluck('nama')->filter()->unique()->implode(', ').')';
    }
}
