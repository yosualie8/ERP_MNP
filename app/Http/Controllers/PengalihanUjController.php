<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use App\Support\KontrolUj;
use App\Support\NomorMobil;
use App\Support\SaldoSopir;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengalihan UJ (dirancang ulang owner 20 Okt 2026): tugasnya hanya MEMILIH UJ belum bongkar yang dijadikan SALDO SOPIR — proyeknya
 * belum diketahui (ada masa tunggu). Kas UJ aslinya tidak diubah. Saldo per sopir (sopir sering pindah truk); biaya baru ditentukan saat
 * saldo berkurang: DO lama bongkar (proyek tempat bongkar), dipotong di Pengajuan UJ (jumlah ditentukan admin; proyek UJ itu), dipakai manual (proyek
 * dipilih saat dicatat), dikembalikan sopir (bukan biaya), atau tidak kembali (proyek asal). Lihat App\Support\SaldoSopir.
 */
class PengalihanUjController extends Controller
{
    /** UJ (Uang Jalan / Uang MOB) belum bongkar yang bisa dijadikan saldo: [id_uj => object{u, do, tgl, umur, tahap, tahap_id, status, lama}]. */
    private function terbuka(): Collection
    {
        $hasil = collect();
        foreach (KontrolUj::terbuka() as $d) {
            foreach ($d['detail'] as $u) {
                if (! in_array($u->kategori, KontrolUj::KATEGORI, true) || str_starts_with((string) $u->id_uj, 'SALDO-') || ! $u->id_uj) {
                    continue;
                }
                $hasil[$u->id_uj] = (object) ['u' => $u, 'do' => $d['do'], 'tgl' => $u->tanggal?->toDateString(), 'umur' => $u->tanggal ? (int) $u->tanggal->copy()->startOfDay()->diffInDays(today()) : null,
                    'tahap' => $d['tahap'], 'tahap_id' => $d['tahap_id'], 'status' => $d['status'], 'lama' => $d['lama']];
            }
        }

        return $hasil;
    }

    public function index(Request $request): View
    {
        $saldo = SaldoSopir::perSopir();
        $tab = in_array($request->query('tab'), ['pilih', 'saldo'], true) ? $request->query('tab')
            : ($request->filled('asal') || $request->filled('mobil') || $saldo->where('saldo', '>', 0.5)->isEmpty() ? 'pilih' : 'saldo');
        $data = ['tab' => $tab, 'saldo' => $saldo, 'tahap' => ProyekTahap::orderBy('nama')->get(['id', 'nama', 'status', 'tipe'])->keyBy('id')];

        if ($tab === 'pilih') {
            // UJ yang SUDAH jadi saldo sopir tetap tampil (owner 20 Okt 2026): kolom Status hanya "Sudah jadi saldo sopir" / "Belum"; tanpa centang,
            // bisa dibatalkan dari sini. UJ yang dikembalikan / ditutup tanpa bongkar tidak ditampilkan (bukan saldo).
            $proses = UjPengalihan::with('dari')->where('jenis', 'alih')->whereNotNull('id_uj')->get();
            $ujProses = UjDetail::whereIn('id_uj', $proses->pluck('id_uj'))->get()->keyBy('id_uj');
            $bongkar = $saldo->flatMap(fn ($s) => $s['uj'])->keyBy(fn ($x) => $x->p->id_uj);
            $sejak = \Illuminate\Support\Carbon::parse(KontrolUj::SEJAK);
            $diproses = $proses->filter(fn ($p) => isset($ujProses[$p->id_uj]))->mapWithKeys(function ($p) use ($ujProses, $bongkar, $sejak, $data) {
                $u = $ujProses[$p->id_uj];
                $x = $bongkar[$p->id_uj] ?? null;
                $info = match ($p->jenis) {
                    'alih' => '💰 Sudah jadi saldo sopir '.($p->sopir ?: $p->nama).' ('.$p->tanggal->translatedFormat('j M').')'
                        .($x?->bongkar ? ' · DO lama sudah bongkar '.$x->bongkar->tanggal->translatedFormat('j M').' di '.($data['tahap'][$x->tahap_bongkar]->nama ?? $x->bongkar->tahap).' — saldonya terpakai' : ''),
                    'kembali' => '↩ Sudah dikembalikan sopir ('.$p->tanggal->translatedFormat('j M').')',
                    default => '✕ Sudah ditutup tanpa bongkar ('.$p->tanggal->translatedFormat('j M').')',
                };

                return [$p->id_uj => (object) ['u' => $u, 'do' => SaldoSopir::kunciDo($u->no_do), 'tgl' => $u->tanggal?->toDateString(),
                    'umur' => $u->tanggal ? (int) $u->tanggal->copy()->startOfDay()->diffInDays(today()) : null, 'tahap' => $p->dari?->nama, 'tahap_id' => $p->dari_tahap_id,
                    'status' => 'proses', 'lama' => $u->tanggal && $u->tanggal->lt($sejak), 'p' => $p, 'info' => $info.($p->keterangan ? ' — '.$p->keterangan : '')]];
            });
            $semua = $this->terbuka()->union($diproses);
            $asal = $request->integer('asal') ?: null;
            $lama = $request->boolean('lama');
            $status = in_array($request->query('status'), ['belum', 'sudah'], true) ? $request->query('status') : 'semua';
            $cari = trim((string) $request->query('cari'));
            $mobil = collect(explode(',', (string) $request->query('mobil')))->map(fn ($m) => NomorMobil::rapikan(trim($m)))->filter()->values();
            $calon = $semua->filter(fn ($c) => ($lama || ! $c->lama) && (! $asal || $c->tahap_id === $asal) && ($mobil->isEmpty() || $mobil->contains(NomorMobil::rapikan($c->u->no_mobil)))
                && ($status === 'semua' || ($status === 'sudah') === isset($c->p))
                && ($cari === '' || str_contains(mb_strtolower($c->u->nama.' '.$c->u->no_mobil.' '.$c->u->id_uj.' '.$c->u->no_do.' '.$c->u->keterangan), mb_strtolower($cari))));

            // Petunjuk "mungkin sudah jalan": uang tanah DO itu sudah dibayar, atau truk itu sudah ada rit sesudah tanggal UJ.
            $kunci = fn ($v) => SaldoSopir::kunciDo($v);
            $tanah = UjDetail::where('kategori', 'Uang Tanah')->whereIn('no_do', $calon->pluck('u.no_do')->filter()->unique()->values())->get(['no_do'])
                ->map(fn ($u) => $kunci($u->no_do))->filter()->flip();
            $ritAkhir = Ritasi::whereIn('no_lambung', $calon->pluck('u.no_mobil')->filter()->unique()->values())->selectRaw('no_lambung, MAX(tanggal) t')->groupBy('no_lambung')->pluck('t', 'no_lambung');
            $calon = $calon->map(function ($c) use ($tanah, $ritAkhir, $kunci, $mobil) {
                $akhir = ($t = $ritAkhir[$c->u->no_mobil] ?? null) ? \Illuminate\Support\Carbon::parse($t)->toDateString() : null;
                $c->rit_akhir = $akhir;
                $c->catatan = isset($c->p) ? null : ($c->u->kategori === 'Uang MOB' ? 'Uang MOB — biasanya sudah terpakai untuk mobilisasi'
                    : (isset($tanah[$kunci($c->u->no_do)]) ? 'uang tanah DO ini sudah dibayar — mungkin sudah jalan' : ($akhir && $c->tgl < $akhir ? "truk ini sudah ada rit sesudahnya ({$akhir}) — cek dulu" : null)));
                $c->usul = $mobil->isNotEmpty() && ! $c->catatan && ! isset($c->p);

                return $c;
            })->sortBy(fn ($c) => [SaldoSopir::kunci($c->u->nama), $c->tgl])->values();

            $tampil = $semua->filter(fn ($c) => $lama || ! $c->lama);
            $data += ['calon' => $calon, 'asal' => $asal, 'lama' => $lama, 'cari' => $cari, 'mobil' => $mobil, 'status' => $status,
                'daftarAsal' => $tampil->groupBy(fn ($c) => (int) $c->tahap_id)->map(fn ($g) => ['nama' => $g->first()->tahap ?? 'Proyek tidak dikenali', 'n' => $g->count()]),
                'jumlahStatus' => ['belum' => $tampil->filter(fn ($c) => ! isset($c->p))->count(), 'sudah' => $tampil->filter(fn ($c) => isset($c->p))->count()],
                'jumlahLama' => $semua->where('lama', true)->count()];
        }

        return view('pengalihan-uj.index', $data);
    }

    /** Pilih UJ → jadikan saldo sopir (atau catat dikembalikan sopir). Tanpa proyek tujuan. */
    public function simpan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'jenis' => ['required', 'in:alih,kembali'], 'tanggal' => ['required', 'date'], 'keterangan' => ['required', 'string', 'min:5', 'max:300'],
            'uj' => ['required', 'array', 'min:1'], 'uj.*' => ['string', 'max:20'],
        ], ['uj.required' => 'Centang minimal satu UJ.', 'keterangan.*' => 'Tulis alasannya (mis. "Truk pindah lokasi sebelum bongkar").']);
        $terbuka = $this->terbuka();
        $pilih = collect($d['uj'])->unique()->values();
        $sudah = UjPengalihan::whereIn('id_uj', $pilih)->pluck('id_uj')->flip();
        $salah = $pilih->reject(fn ($id) => isset($terbuka[$id]) && ! isset($sudah[$id]));
        if ($salah->isNotEmpty()) {
            return back()->withInput()->with('error', 'UJ berikut bukan UJ belum bongkar (atau sudah dijadikan saldo / ditutup): '.$salah->implode(', '));
        }
        DB::transaction(function () use ($d, $pilih, $terbuka, $request) {
            foreach ($pilih as $id) {
                $c = $terbuka[$id];
                UjPengalihan::create(['jenis' => $d['jenis'], 'id_uj' => $id, 'no_do' => $c->u->no_do ? ltrim(trim($c->u->no_do), "`'") : null,
                    'no_mobil' => NomorMobil::rapikan($c->u->no_mobil), 'nama' => $c->u->nama, 'sopir' => SaldoSopir::kunci($c->u->nama),
                    'dari_tahap_id' => $c->tahap_id, 'tanggal' => $d['tanggal'], 'nominal' => $c->u->nominal, 'keterangan' => $d['keterangan'], 'user_id' => $request->user()->id]);
            }
        });
        $rp = $pilih->sum(fn ($id) => $terbuka[$id]->u->nominal);
        $label = $d['jenis'] === 'alih' ? 'dijadikan saldo sopir' : 'dicatat dikembalikan sopir';
        KasRiwayat::create(['aksi' => 'uj-alih', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($pilih->count()." UJ {$label} (Rp ".number_format($rp, 0, ',', '.').'): '.$pilih->implode(', ').' — '.$d['keterangan'], 0, 490, '…'),
            'isi' => ['uj' => $pilih, 'jenis' => $d['jenis'], 'keterangan' => $d['keterangan']], 'user_id' => $request->user()->id]);

        return redirect()->route('pengalihan-uj.index', ['tab' => $d['jenis'] === 'alih' ? 'saldo' : 'pilih'])
            ->with('success', $pilih->count().' UJ (Rp '.number_format($rp, 0, ',', '.').") {$label}.");
    }

    /** Saldo sopir berkurang di luar Pengajuan UJ: Uang MOB / lain (proyek dipilih), dikembalikan sopir, atau tidak kembali (proyek asal). */
    public function pakai(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'sopir' => ['required', 'string', 'max:100'], 'tanggal' => ['required', 'date'], 'peruntukan' => ['required', 'in:mob,lain,kembali,hangus'],
            'nominal' => ['required', 'string', 'max:20'], 'tahap' => ['nullable', 'integer', 'exists:proyek_tahap,id'], 'keterangan' => ['nullable', 'string', 'max:300'],
        ]);
        $d += ['tahap' => null, 'keterangan' => null];
        $nominal = (float) preg_replace('/\D/', '', $d['nominal']);
        $sopir = SaldoSopir::kunci($d['sopir']);
        $s = SaldoSopir::perSopir()[$sopir] ?? null;
        $sisa = SaldoSopir::sisa($sopir);
        if (! $s || $nominal <= 0 || $nominal > $sisa + 0.5) {
            return back()->withInput()->with('error', "Nominal harus 1 s/d saldo {$sopir} (Rp ".number_format($sisa, 0, ',', '.').').');
        }
        if ($d['peruntukan'] !== 'kembali' && empty($d['tahap'])) {
            return back()->withInput()->with('error', 'Pilih proyek yang menanggung biaya ini.');
        }
        if ($d['peruntukan'] === 'hangus' && mb_strlen(trim((string) $d['keterangan'])) < 10) {
            return back()->withInput()->with('error', 'Saldo tidak kembali: tulis alasannya (min. 10 karakter), mis. "sopir kabur, tidak bisa dihubungi".');
        }
        $p = UjPengalihan::create(['jenis' => 'pakai', 'no_mobil' => $s['mobil'][0] ?? null, 'nama' => $sopir, 'sopir' => $sopir,
            'dari_tahap_id' => $d['peruntukan'] === 'hangus' ? $d['tahap'] : null, 'ke_tahap_id' => $d['peruntukan'] === 'kembali' ? null : $d['tahap'],
            'tanggal' => $d['tanggal'], 'nominal' => $nominal, 'peruntukan' => $d['peruntukan'], 'keterangan' => $d['keterangan'] ?: null, 'user_id' => $request->user()->id]);
        $proyek = $p->ke?->nama;
        KasRiwayat::create(['aksi' => 'uj-alih-pakai', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("Saldo sopir {$sopir} berkurang: ".UjPengalihan::PERUNTUKAN[$d['peruntukan']].' Rp '.number_format($nominal, 0, ',', '.')
                .($proyek ? " ({$proyek})" : '').($d['keterangan'] ? " — {$d['keterangan']}" : ''), 0, 490, '…'),
            'isi' => $p->toArray(), 'user_id' => $request->user()->id]);

        return redirect()->route('pengalihan-uj.index', ['tab' => 'saldo'])->with('success', "Saldo {$sopir} berkurang Rp ".number_format($nominal, 0, ',', '.').'.');
    }

    /** Ganti / gabungkan nama sopir (ejaan berbeda di Kas UJ, mis. "Andre" & "ANDRE S"): semua catatan saldo sopir lama pindah ke nama baru. */
    public function sopir(Request $request): RedirectResponse
    {
        $d = $request->validate(['lama' => ['required', 'string', 'max:100'], 'baru' => ['required', 'string', 'max:100']]);
        [$lama, $baru] = [SaldoSopir::kunci($d['lama']), SaldoSopir::kunci($d['baru'])];
        if ($lama === $baru) {
            return back();
        }
        $baris = UjPengalihan::whereIn('jenis', ['alih', 'pakai'])->get()->filter(fn ($p) => SaldoSopir::kunci($p->sopir ?: $p->nama) === $lama);
        UjPengalihan::whereIn('id', $baris->pluck('id'))->update(['sopir' => $baru]);
        KasRiwayat::create(['aksi' => 'uj-alih-sopir', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Saldo sopir {$lama} dipindah ke nama {$baru} ({$baris->count()} catatan)", 'isi' => ['lama' => $lama, 'baru' => $baru, 'id' => $baris->pluck('id')],
            'user_id' => $request->user()->id]);

        return redirect()->route('pengalihan-uj.index', ['tab' => 'saldo'])->with('success', "Saldo {$lama} sekarang atas nama {$baru}.");
    }

    /**
     * Monitor Ritasi → tutup UJ belum bongkar satu DO (Kontrol UJ, owner 18 Okt 2026):
     *  - kembali : uang dikembalikan sopir (keluar dari biaya proyeknya);
     *  - batal   : tidak bongkar, uang sudah terpakai (mis. truk breakdown di jalan) — tetap biaya, alasan wajib.
     */
    public function tutup(Request $request): RedirectResponse
    {
        $d = $request->validate(['jenis' => ['required', 'in:kembali,batal'], 'uj' => ['required', 'array', 'min:1'], 'uj.*' => ['string', 'max:20'],
            'keterangan' => ['required', 'string', 'min:10', 'max:300']], ['keterangan.*' => 'Tulis alasannya (min. 10 karakter).', 'uj.required' => 'Tidak ada UJ yang dipilih.']);
        $uj = UjDetail::whereIn('id_uj', $d['uj'])->whereIn('kategori', KontrolUj::KATEGORI)->get();
        $sudah = UjPengalihan::whereIn('id_uj', $uj->pluck('id_uj'))->pluck('id_uj')->flip();
        $uj = $uj->reject(fn ($u) => isset($sudah[$u->id_uj]));
        if ($uj->isEmpty()) {
            return back()->with('error', 'UJ itu sudah dijadikan saldo / dikembalikan / dibatalkan sebelumnya.');
        }
        $tahap = KontrolUj::terbuka()->keyBy('do');
        DB::transaction(function () use ($uj, $d, $tahap, $request) {
            foreach ($uj as $u) {
                UjPengalihan::create(['jenis' => $d['jenis'], 'id_uj' => $u->id_uj, 'no_do' => $u->no_do ? ltrim(trim($u->no_do), "`'") : null, 'no_mobil' => NomorMobil::rapikan($u->no_mobil),
                    'nama' => $u->nama, 'sopir' => SaldoSopir::kunci($u->nama), 'dari_tahap_id' => $tahap[SaldoSopir::kunciDo($u->no_do)]['tahap_id'] ?? null, 'tanggal' => now()->toDateString(),
                    'nominal' => $u->nominal, 'keterangan' => $d['keterangan'], 'user_id' => $request->user()->id]);
            }
        });
        $label = $d['jenis'] === 'kembali' ? 'dikembalikan sopir' : 'dibatalkan (tidak bongkar)';
        KasRiwayat::create(['aksi' => 'uj-tutup', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($uj->count().' UJ '.$label.' (Rp '.number_format($uj->sum('nominal'), 0, ',', '.').'): '.$uj->pluck('id_uj')->implode(', ').' — '.$d['keterangan'], 0, 490, '…'),
            'isi' => ['uj' => $uj->pluck('id_uj'), 'jenis' => $d['jenis'], 'keterangan' => $d['keterangan']], 'user_id' => $request->user()->id]);

        return back()->with('success', $uj->count().' UJ ('.$uj->pluck('id_uj')->implode(', ').") {$label}.");
    }

    public function hapus(Request $request, UjPengalihan $pengalihan): RedirectResponse
    {
        if ($pengalihan->uj_pengajuan_detail_id) {
            return back()->with('error', 'Potongan ini dari Pengajuan UJ — ubah atau batalkan pengajuannya ('.($pengalihan->keterangan ? mb_strimwidth($pengalihan->keterangan, 0, 40, '…') : 'Pengajuan UJ').').');
        }
        if ($pengalihan->jenis === 'alih') {
            $s = SaldoSopir::perSopir()[SaldoSopir::kunci($pengalihan->sopir ?: $pengalihan->nama)] ?? null;
            $x = $s ? $s['uj']->first(fn ($x) => $x->p->id === $pengalihan->id) : null;
            if ($s && $x && ! $x->bongkar && $s['saldo'] - $pengalihan->nominal < -0.5) {
                return back()->with('error', "Saldo {$s['sopir']} sudah terpakai — tanpa UJ ini saldonya jadi minus. Hapus dulu pemakaiannya.");
            }
        }
        KasRiwayat::create(['aksi' => 'uj-alih-hapus', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => 'Batal catatan pengalihan: '.UjPengalihan::JENIS[$pengalihan->jenis].' '.($pengalihan->id_uj ?? '').' '.($pengalihan->sopir ?: $pengalihan->nama).' Rp '.number_format($pengalihan->nominal, 0, ',', '.'),
            'isi' => $pengalihan->toArray(), 'user_id' => $request->user()->id]);
        $pengalihan->delete();

        return back()->with('success', 'Catatan pengalihan dihapus.');
    }
}
