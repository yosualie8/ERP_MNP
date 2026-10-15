<?php

namespace App\Http\Controllers;

use App\Models\AlokasiOverhead;
use App\Models\KasRiwayat;
use App\Models\ProyekTahap;
use App\Support\Overhead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Alokasi Biaya Umum: tiap bulan, biaya pos biaya umum (Kas Harian yang terpetakan ke tahap pos) dibagi ke tahap yang
 * berjalan. Centang awal = rekomendasi (lihat Overhead::rekomendasi); owner mengubah lalu menyimpan → baru dihitung.
 */
class AlokasiOverheadController extends Controller
{
    public function index(Request $request): View
    {
        $data = Overhead::data();
        $semua = ProyekTahap::with(['proyek', 'anggaran.komponen'])->get();
        $pos = $semua->whereNotNull('pos_overhead')->sortBy(fn ($t) => [$t->pos_overhead === 'semua' ? 1 : 0, $t->nama])->values();
        $tersimpan = AlokasiOverhead::all()->groupBy('bulan');

        // Bulan yang punya biaya pos (18 bulan terakhir), terbaru dulu.
        $bulanAda = $pos->flatMap(fn ($p) => array_keys(array_filter($data['kas'][$p->id] ?? [], fn ($v) => $v != 0)))->unique()
            ->filter(fn ($ym) => $ym >= now()->subMonths(17)->format('Y-m') && $ym <= now()->format('Y-m'))->sortDesc()->values();
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) ? $request->query('bulan') : ($bulanAda->first() ?? now()->format('Y-m'));
        $statusBulan = $bulanAda->mapWithKeys(fn ($ym) => [$ym => [
            'pos' => $pos->filter(fn ($p) => ($data['kas'][$p->id][$ym] ?? 0) != 0)->count(),
            'disimpan' => ($tersimpan[$ym] ?? collect())->count(),
        ]]);

        $berjalan = Overhead::berjalan($semua, $bulan);
        $basis = Overhead::basisOmset($berjalan);
        $simpanan = ($tersimpan[$bulan] ?? collect())->keyBy('pos_tahap_id');
        $kartu = $pos->filter(fn ($p) => ($data['kas'][$p->id][$bulan] ?? 0) != 0)->map(function ($p) use ($data, $bulan, $berjalan, $basis, $simpanan) {
            $s = $simpanan[$p->id] ?? null;
            $rekom = Overhead::rekomendasi($p, $berjalan);
            $pilih = $s ? $s->tahap_ids : $rekom;
            $metode = $s ? $s->metode : $p->metode_overhead;
            $biaya = $data['kas'][$p->id][$bulan];
            $bagian = Overhead::bagi($biaya, $pilih, $metode, $basis);
            // Rekomendasi tampil di atas, lalu tahap berjalan lainnya.
            $calon = $berjalan->sortBy(fn ($t) => [in_array($t->id, $rekom, true) ? 0 : 1, $t->proyek->urutan, (int) preg_replace('/\D/', '', $t->nama) ?: 99999, $t->nama])->values()
                ->map(fn ($t) => (object) ['t' => $t, 'rekom' => in_array($t->id, $rekom, true), 'pilih' => in_array($t->id, $pilih, true), 'basis' => $basis[$t->id] ?? 0, 'bagian' => $bagian[$t->id] ?? 0]);

            return (object) ['p' => $p, 'biaya' => $biaya, 'simpan' => $s, 'metode' => $metode, 'calon' => $calon, 'beda' => $s && collect($s->tahap_ids)->sort()->values()->all() !== collect($rekom)->sort()->values()->all()];
        })->values();

        return view('alokasi-overhead.index', [
            'bulan' => $bulan, 'bulanAda' => $bulanAda, 'statusBulan' => $statusBulan, 'kartu' => $kartu,
            'calonPos' => $semua->filter(fn ($t) => $t->pos_overhead || $t->tipe === 'biaya' || preg_match('/tanpa tahap|belum dirinci|biaya lokasi/i', $t->nama))
                ->sortBy(fn ($t) => [$t->pos_overhead ? 0 : 1, $t->nama])->values(),
            'kasPos' => $data['kas'],
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'pos' => ['nullable', 'array'],
            'pos.*.metode' => ['required', Rule::in(array_keys(AlokasiOverhead::METODE))],
            'pos.*.tahap' => ['nullable', 'array'], 'pos.*.tahap.*' => ['integer', 'exists:proyek_tahap,id'],
        ]);
        $ringkas = [];
        foreach ($d['pos'] ?? [] as $posId => $isi) {
            $pos = ProyekTahap::whereKey($posId)->whereNotNull('pos_overhead')->first();
            if (! $pos) {
                continue;
            }
            $tahap = array_values(array_unique(array_map('intval', $isi['tahap'] ?? [])));
            if (! $tahap) {
                AlokasiOverhead::where('bulan', $d['bulan'])->where('pos_tahap_id', $pos->id)->delete();
                $ringkas[] = "{$pos->nama}: tidak dialokasikan";
                continue;
            }
            AlokasiOverhead::updateOrCreate(['bulan' => $d['bulan'], 'pos_tahap_id' => $pos->id], ['metode' => $isi['metode'], 'tahap_ids' => $tahap, 'user_id' => $request->user()->id]);
            $ringkas[] = "{$pos->nama} → ".count($tahap).' tahap ('.AlokasiOverhead::METODE[$isi['metode']].')';
        }
        KasRiwayat::create(['aksi' => 'alokasi-overhead', 'lembar' => 'Proyek', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth("Alokasi biaya umum {$d['bulan']}: ".implode('; ', $ringkas), 0, 490, '…'), 'isi' => $d, 'user_id' => $request->user()->id]);

        return redirect()->route('alokasi-overhead.index', ['bulan' => $d['bulan']])->with('success', "Alokasi biaya umum {$d['bulan']} disimpan.");
    }

    /** Pos biaya umum: tahap mana yang biayanya dibagi ke proyek, cakupan rekomendasi & metode bawaan. */
    public function aturPos(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'pos' => ['required', 'array'],
            'pos.*.cakupan' => ['nullable', Rule::in(['', ...array_keys(Overhead::CAKUPAN)])],
            'pos.*.metode' => ['required', Rule::in(array_keys(AlokasiOverhead::METODE))],
        ]);
        foreach ($d['pos'] as $id => $isi) {
            ProyekTahap::whereKey($id)->update(['pos_overhead' => ($isi['cakupan'] ?? '') ?: null, 'metode_overhead' => $isi['metode']]);
        }
        KasRiwayat::create(['aksi' => 'alokasi-overhead', 'lembar' => 'Proyek', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => 'Pengaturan pos biaya umum diubah', 'isi' => $d, 'user_id' => $request->user()->id]);

        return back()->with('success', 'Pengaturan pos biaya umum disimpan.');
    }
}
