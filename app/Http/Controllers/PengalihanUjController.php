<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use App\Support\DaftarProyek;
use App\Support\RitasiProyek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengalihan UJ (owner 17 Okt 2026): UJ proyek ritasi yang belum bongkar karena truk dipindah ke proyek lain. Kas UJ aslinya
 * tidak diubah; dicatat sebagai Dialihkan (biaya pindah ke proyek tujuan, jadi saldo sopir) atau Dikembalikan, lalu saldo sopir
 * dipakai untuk Uang MOB / dipotong dari UJ berikutnya (lihat App\Models\UjPengalihan).
 */
class PengalihanUjController extends Controller
{
    public function index(Request $request): View
    {
        $ritasi = ProyekTahap::where('tipe', 'ritasi')->orderByRaw("status = 'progress' DESC")->orderBy('nama')->get();
        $asal = $ritasi->firstWhere('id', $request->integer('asal')) ?? $ritasi->firstWhere('nama', 'Kawasan Industri Kendal') ?? $ritasi->first();
        $tujuan = $ritasi->firstWhere('id', $request->integer('tujuan')) ?? $ritasi->firstWhere('nama', 'Jogja');
        $pilihMobil = collect(explode(',', (string) $request->query('mobil')))->map(fn ($m) => trim($m))->filter()->values();

        $belum = $asal ? RitasiProyek::hitung($asal)['belum'] : collect();
        $do = fn ($v) => ltrim(trim((string) $v), '0');
        $tanah = UjDetail::where('kategori', 'Uang Tanah')->whereIn('no_do', $belum->pluck('no_do')->filter())->get()->groupBy(fn ($u) => $do($u->no_do));
        $nama = $asal ? Ritasi::distinct()->pluck('tahap')->filter(fn ($n) => DaftarProyek::tahapId('ritasi', $n) === $asal->id) : collect();
        $ritAkhir = Ritasi::whereIn('tahap', $nama)->selectRaw('no_lambung, MAX(tanggal) t')->groupBy('no_lambung')->pluck('t', 'no_lambung');
        // usulan: UJ sesudah / sama dengan tanggal rit terakhir truk itu & uang tanahnya belum dibayar (yang lebih lama mungkin sudah jalan tapi belum diinput)
        $calon = $belum->map(function ($u) use ($tanah, $ritAkhir, $do, $pilihMobil) {
            $akhir = $ritAkhir[$u->no_mobil] ?? null;
            $akhir = $akhir ? \Illuminate\Support\Carbon::parse($akhir)->toDateString() : null;
            $sudahTanah = isset($tanah[$do($u->no_do)]);
            $tgl = $u->tanggal->timezone('Asia/Jakarta')->toDateString();
            $alasan = $u->kategori === 'Uang MOB' ? 'Uang MOB sudah terpakai untuk mobilisasi ke proyek ini — tetap biaya proyek asal'
                : ($sudahTanah ? 'uang tanah sudah dibayar — mungkin sudah jalan' : ($akhir && $tgl < $akhir ? "lebih lama dari rit terakhir truk ini ({$akhir}) — mungkin sudah jalan" : null));

            return (object) ['u' => $u, 'tgl' => $tgl, 'rit_akhir' => $akhir, 'usul' => ! $alasan && ($pilihMobil->isEmpty() || $pilihMobil->contains($u->no_mobil)), 'alasan' => $alasan];
        })->sortBy(fn ($c) => [$c->u->no_mobil, $c->tgl])->values();

        $buku = $tujuan ? UjPengalihan::with(['dari', 'ke'])->where('ke_tahap_id', $tujuan->id)->orderBy('no_mobil')->orderBy('tanggal')->orderBy('id')->get() : collect();
        $saldo = $buku->groupBy('no_mobil')->map(fn ($g) => ['nama' => $g->firstWhere('jenis', 'alih')?->nama ?? $g->first()->nama,
            'alih' => $g->where('jenis', 'alih')->sum('nominal'), 'n' => $g->where('jenis', 'alih')->count(),
            'mob' => $g->where('jenis', 'pakai')->where('peruntukan', 'mob')->sum('nominal'), 'pakai_lain' => $g->where('jenis', 'pakai')->where('peruntukan', '!=', 'mob')->sum('nominal')])
            ->map(fn ($x) => $x + ['saldo' => $x['alih'] - $x['mob'] - $x['pakai_lain']]);

        return view('pengalihan-uj.index', [
            'ritasi' => $ritasi, 'asal' => $asal, 'tujuan' => $tujuan, 'calon' => $calon, 'pilihMobil' => $pilihMobil, 'buku' => $buku, 'saldo' => $saldo,
            'kembali' => $asal ? UjPengalihan::where('dari_tahap_id', $asal->id)->where('jenis', 'kembali')->orderBy('tanggal')->get() : collect(),
        ]);
    }

    /** Alihkan / kembalikan UJ terpilih; opsional langsung catat Uang MOB per mobil dari saldo hasil pengalihan. */
    public function simpan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'asal' => ['required', 'integer', 'exists:proyek_tahap,id'], 'tujuan' => ['nullable', 'integer', 'exists:proyek_tahap,id'],
            'jenis' => ['required', 'in:alih,kembali'], 'tanggal' => ['required', 'date'], 'keterangan' => ['nullable', 'string', 'max:300'],
            'uj' => ['required', 'array', 'min:1'], 'uj.*' => ['string', 'max:20'], 'mob' => ['nullable', 'string', 'max:20'],
        ], ['uj.required' => 'Centang minimal satu UJ.']);
        if ($d['jenis'] === 'alih' && empty($d['tujuan'])) {
            return back()->withInput()->with('error', 'Pilih proyek tujuan pengalihan.');
        }
        $asal = ProyekTahap::findOrFail($d['asal']);
        $belum = RitasiProyek::hitung($asal)['belum']->keyBy('id_uj');
        $pilih = collect($d['uj'])->unique()->values();
        $salah = $pilih->reject(fn ($id) => isset($belum[$id]));
        if ($salah->isNotEmpty()) {
            return back()->withInput()->with('error', 'UJ berikut bukan UJ '.$asal->nama.' yang belum bongkar (atau sudah dialihkan): '.$salah->implode(', '));
        }
        $mob = (float) preg_replace('/\D/', '', (string) ($d['mob'] ?? ''));
        $dibuat = DB::transaction(function () use ($d, $pilih, $belum, $mob, $request) {
            $n = 0;
            foreach ($pilih as $id) {
                $u = $belum[$id];
                UjPengalihan::create(['jenis' => $d['jenis'], 'id_uj' => $id, 'no_mobil' => $u->no_mobil, 'nama' => $u->nama, 'dari_tahap_id' => $d['asal'],
                    'ke_tahap_id' => $d['jenis'] === 'alih' ? $d['tujuan'] : null, 'tanggal' => $d['tanggal'], 'nominal' => $u->nominal,
                    'keterangan' => $d['keterangan'] ?: null, 'user_id' => $request->user()->id]);
                $n++;
            }
            // Uang MOB ke proyek tujuan dibayar dari saldo hasil pengalihan, per mobil (maksimal sebesar saldonya)
            if ($d['jenis'] === 'alih' && $mob > 0) {
                foreach ($pilih->map(fn ($id) => $belum[$id])->groupBy('no_mobil') as $mobil => $g) {
                    $saldo = UjPengalihan::where('ke_tahap_id', $d['tujuan'])->where('no_mobil', $mobil)->get();
                    $sisa = $saldo->where('jenis', 'alih')->sum('nominal') - $saldo->where('jenis', 'pakai')->sum('nominal');
                    if ($sisa <= 0) {
                        continue;
                    }
                    UjPengalihan::create(['jenis' => 'pakai', 'no_mobil' => $mobil, 'nama' => $g->first()->nama, 'dari_tahap_id' => $d['asal'], 'ke_tahap_id' => $d['tujuan'],
                        'tanggal' => $d['tanggal'], 'nominal' => min($mob, $sisa), 'peruntukan' => 'mob',
                        'keterangan' => 'Uang MOB pindah lokasi'.($sisa < $mob ? ' (saldo hanya cukup '.number_format($sisa, 0, ',', '.').', kekurangan '.number_format($mob - $sisa, 0, ',', '.').' ditransfer terpisah)' : ''),
                        'user_id' => $request->user()->id]);
                }
            }

            return $n;
        });
        $tujuan = $d['tujuan'] ? ProyekTahap::find($d['tujuan']) : null;
        KasRiwayat::create(['aksi' => 'uj-alih', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("{$dibuat} UJ {$asal->nama} ".($d['jenis'] === 'alih' ? "dialihkan ke {$tujuan->nama}" : 'dikembalikan sopir').' (Rp '.number_format($pilih->sum(fn ($id) => $belum[$id]->nominal), 0, ',', '.').')'
                .($mob > 0 && $d['jenis'] === 'alih' ? ' + Uang MOB Rp '.number_format($mob, 0, ',', '.').'/mobil dari saldo' : '').': '.$pilih->implode(', '), 0, 490, '…'),
            'isi' => ['uj' => $pilih, 'jenis' => $d['jenis'], 'asal' => $d['asal'], 'tujuan' => $d['tujuan'] ?? null, 'mob' => $mob], 'user_id' => $request->user()->id]);

        return redirect()->route('pengalihan-uj.index', ['asal' => $d['asal'], 'tujuan' => $d['tujuan'] ?? null])->with('success', "{$dibuat} UJ ".($d['jenis'] === 'alih' ? "dialihkan ke {$tujuan->nama}" : 'dicatat dikembalikan').'.');
    }

    /** Catat pemakaian saldo sopir (Uang MOB / dipotong dari UJ berikutnya / lain). */
    public function pakai(Request $request): RedirectResponse
    {
        $d = $request->validate(['tujuan' => ['required', 'integer', 'exists:proyek_tahap,id'], 'no_mobil' => ['required', 'string', 'max:20'], 'tanggal' => ['required', 'date'],
            'peruntukan' => ['required', 'in:mob,uj,lain'], 'nominal' => ['required', 'string', 'max:20'], 'keterangan' => ['nullable', 'string', 'max:300']]);
        $nominal = (float) preg_replace('/\D/', '', $d['nominal']);
        $buku = UjPengalihan::where('ke_tahap_id', $d['tujuan'])->where('no_mobil', $d['no_mobil'])->get();
        $sisa = $buku->where('jenis', 'alih')->sum('nominal') - $buku->where('jenis', 'pakai')->sum('nominal');
        if ($nominal <= 0 || $nominal > $sisa) {
            return back()->withInput()->with('error', "Nominal harus 1 s/d saldo {$d['no_mobil']} (Rp ".number_format($sisa, 0, ',', '.').').');
        }
        UjPengalihan::create(['jenis' => 'pakai', 'no_mobil' => $d['no_mobil'], 'nama' => $buku->firstWhere('jenis', 'alih')?->nama, 'ke_tahap_id' => $d['tujuan'],
            'tanggal' => $d['tanggal'], 'nominal' => $nominal, 'peruntukan' => $d['peruntukan'], 'keterangan' => $d['keterangan'] ?: null, 'user_id' => $request->user()->id]);
        KasRiwayat::create(['aksi' => 'uj-alih-pakai', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Saldo pengalihan {$d['no_mobil']} dipakai: ".UjPengalihan::PERUNTUKAN[$d['peruntukan']].' Rp '.number_format($nominal, 0, ',', '.').($d['keterangan'] ? " — {$d['keterangan']}" : ''),
            'isi' => $d + ['nominal_angka' => $nominal], 'user_id' => $request->user()->id]);

        return back()->with('success', "Pemakaian saldo {$d['no_mobil']} dicatat.");
    }

    public function hapus(Request $request, UjPengalihan $pengalihan): RedirectResponse
    {
        if ($pengalihan->jenis === 'alih') {
            $sisa = UjPengalihan::where('ke_tahap_id', $pengalihan->ke_tahap_id)->where('no_mobil', $pengalihan->no_mobil)->get();
            if ($sisa->where('jenis', 'alih')->sum('nominal') - $pengalihan->nominal < $sisa->where('jenis', 'pakai')->sum('nominal')) {
                return back()->with('error', "Hapus dulu pemakaian saldo {$pengalihan->no_mobil} — tanpa pengalihan ini saldonya jadi minus.");
            }
        }
        KasRiwayat::create(['aksi' => 'uj-alih-hapus', 'lembar' => 'Kas UJ', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => 'Batal pengalihan UJ: '.UjPengalihan::JENIS[$pengalihan->jenis].' '.($pengalihan->id_uj ?? '').' '.$pengalihan->no_mobil.' Rp '.number_format($pengalihan->nominal, 0, ',', '.'),
            'isi' => $pengalihan->toArray(), 'user_id' => $request->user()->id]);
        $pengalihan->delete();

        return back()->with('success', 'Catatan pengalihan dihapus.');
    }
}
