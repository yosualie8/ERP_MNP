<?php

namespace App\Http\Controllers;

use App\Models\AnggaranTahap;
use App\Models\KasRiwayat;
use App\Models\KomponenAnggaran;
use App\Models\ProyekTahap;
use App\Support\Anggaran;
use App\Support\DaftarProyek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Anggaran Proyek: rencana omset & HPP per tahap dari baris komponen (template per tipe tahap), baseline yang bisa dikunci,
 * dibandingkan dengan biaya aktual (sama dengan grid Daftar Proyek) & progres fisik dari kubikasi Ritasi.
 */
class AnggaranController extends Controller
{
    /** Ringkasan semua tahap yang berjalan / sudah punya anggaran: rencana vs realisasi. */
    public function index(Request $request): View
    {
        [$ritasi, $kas, $bbm] = [DaftarProyek::ritasi(), DaftarProyek::kas(), DaftarProyek::bbm()];
        $semua = $request->boolean('semua');
        $tahap = ProyekTahap::with(['proyek', 'anggaran.komponen'])
            ->when(! $semua, fn ($q) => $q->where(fn ($w) => $w->whereIn('status', ['belum', 'progress'])->orHas('anggaran')))
            ->get()
            ->map(function ($t) use ($ritasi, $kas, $bbm) {
                $h = Anggaran::hitung($t->anggaran);
                $biaya = array_sum(array_column(DaftarProyek::rincian($t, $ritasi, $kas, $bbm), 1));
                $vol = Anggaran::volumeRencana($t->anggaran);
                $m3 = $ritasi['per_tahap'][$t->id]['m3'] ?? 0;

                return (object) ['t' => $t, 'h' => $h, 'ada' => $t->anggaran->isNotEmpty(), 'biaya' => $biaya,
                    'pakaiHpp' => $h['hpp'] > 0 ? $biaya / $h['hpp'] * 100 : null, 'm3' => $m3, 'fisik' => $vol > 0 ? $m3 / $vol * 100 : null];
            })
            ->sortBy(fn ($x) => [$x->ada ? 0 : 1, $x->t->proyek->urutan, (int) preg_replace('/\D/', '', $x->t->nama) ?: 99999, $x->t->nama])->values();

        return view('anggaran.index', ['baris' => $tahap, 'semua' => $semua]);
    }

    public function edit(Request $request, ProyekTahap $tahap): View
    {
        $tahap->load('proyek', 'anggaran.komponen');
        $pakaiTemplate = $request->boolean('template');
        $baris = $pakaiTemplate ? Anggaran::template($tahap) : $tahap->anggaran;
        [$ritasi, $kas, $bbm] = [DaftarProyek::ritasi(), DaftarProyek::kas(), DaftarProyek::bbm()];

        return view('anggaran.edit', [
            'tahap' => $tahap, 'baris' => $baris, 'pakaiTemplate' => $pakaiTemplate,
            'komponen' => KomponenAnggaran::where('aktif', true)->orWhereIn('id', $baris->pluck('komponen_id'))->orderBy('urutan')->get(),
            'rincian' => DaftarProyek::rincian($tahap, $ritasi, $kas, $bbm),
            'm3' => $ritasi['per_tahap'][$tahap->id]['m3'] ?? 0,
            'rataKubikasi' => Anggaran::rataKubikasi(),
        ]);
    }

    public function simpan(Request $request, ProyekTahap $tahap): RedirectResponse
    {
        $komponen = KomponenAnggaran::all()->keyBy('id');
        $baris = [];
        $galat = [];
        foreach (array_values((array) $request->input('baris', [])) as $i => $r) {
            $k = $komponen[(int) ($r['komponen_id'] ?? 0)] ?? null;
            $isi = ['volume' => self::angka($r['volume'] ?? null), 'harga' => self::angka($r['harga'] ?? null), 'persen' => self::angka($r['persen'] ?? null)];
            $uraian = trim(preg_replace('/\s+/', ' ', (string) ($r['uraian'] ?? ''))) ?: null;
            if (! $k) {
                if (array_filter($isi, fn ($v) => $v !== null) || $uraian) {
                    $galat[] = 'Baris '.($i + 1).': pilih komponennya.';
                }
                continue;
            }
            foreach (['volume' => 'Volume', 'harga' => 'Harga satuan', 'persen' => 'Persen'] as $f => $label) {
                if ($isi[$f] === false) {
                    $galat[] = "{$k->nama}: {$label} harus angka.";
                    $isi[$f] = null;
                } elseif ($isi[$f] !== null && $isi[$f] < 0) {
                    $galat[] = "{$k->nama}: {$label} tidak boleh minus.";
                }
            }
            if ($k->cara === 'persen') {
                $isi['volume'] = $isi['harga'] = null;
                if ($isi['persen'] !== null && $isi['persen'] > 100) {
                    $galat[] = "{$k->nama}: persen lebih dari 100.";
                }
            } else {
                $isi['persen'] = null;
            }
            $baris[] = ['komponen_id' => $k->id, 'uraian' => mb_substr((string) $uraian, 0, 150) ?: null, ...$isi, 'urutan' => count($baris)];
        }
        if ($galat) {
            throw ValidationException::withMessages(['baris' => $galat]);
        }
        $lama = Anggaran::hitung($tahap->anggaran()->with('komponen')->get());
        DB::transaction(function () use ($tahap, $baris, $request) {
            $tahap->anggaran()->delete();
            foreach ($baris as $b) {
                $tahap->anggaran()->create([...$b, 'user_id' => $request->user()->id]);
            }
        });
        $baru = Anggaran::hitung($tahap->anggaran()->with('komponen')->get());
        $rp = fn ($v) => 'Rp '.number_format($v, 0, ',', '.');
        KasRiwayat::create(['aksi' => 'anggaran-simpan', 'lembar' => 'Proyek', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("Anggaran {$tahap->nama}: omset {$rp($lama['omset'])} → {$rp($baru['omset'])}, HPP {$rp($lama['hpp'])} → {$rp($baru['hpp'])}", 0, 490, '…'),
            'isi' => ['tahap_id' => $tahap->id, 'baris' => $baris], 'user_id' => $request->user()->id]);

        return redirect()->route('anggaran.edit', $tahap)->with('success', "Anggaran {$tahap->nama} disimpan: omset {$rp($baru['omset'])}, HPP {$rp($baru['hpp'])}, margin ".
            ($baru['persen_margin'] !== null ? number_format($baru['persen_margin'], 1, ',', '.').'%' : '–').'.');
    }

    /** Kunci anggaran saat ini sebagai baseline (rencana awal); bisa diperbarui bila ada addendum. */
    public function kunci(Request $request, ProyekTahap $tahap): RedirectResponse
    {
        $baris = $tahap->anggaran()->with('komponen')->get();
        if ($baris->isEmpty()) {
            return back()->with('error', 'Anggaran masih kosong — isi & simpan dulu sebelum dikunci.');
        }
        $h = Anggaran::hitung($baris);
        $tahap->forceFill([
            'anggaran_baseline' => ['omset' => $h['omset'], 'hpp' => $h['hpp'], 'baris' => $baris->map(fn ($b, $i) => [
                'komponen' => $b->komponen->nama, 'jenis' => $b->komponen->jenis, 'uraian' => $b->uraian, 'volume' => $b->volume, 'harga' => $b->harga,
                'persen' => $b->persen, 'nilai' => $h['nilai'][$i] ?? 0])->all()],
            'anggaran_dikunci_at' => now(), 'anggaran_dikunci_oleh' => $request->user()->id,
        ])->save();
        KasRiwayat::create(['aksi' => 'anggaran-kunci', 'lembar' => 'Proyek', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => "Baseline anggaran {$tahap->nama} dikunci (omset ".number_format($h['omset'], 0, ',', '.').', HPP '.number_format($h['hpp'], 0, ',', '.').')',
            'isi' => $tahap->anggaran_baseline, 'user_id' => $request->user()->id]);

        return back()->with('success', "Baseline anggaran {$tahap->nama} dikunci.");
    }

    /** "2.551,75" / "3.5" / "139.000" → angka; kosong → null; bukan angka → false. */
    private static function angka($v): float|false|null
    {
        $v = str_replace([' ', 'Rp', 'rp', '%'], '', trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (! preg_match('/^\d+\.\d{1,2}$/', $v)) {
            $v = str_replace('.', '', $v);
        }

        return is_numeric($v) ? (float) $v : false;
    }
}
