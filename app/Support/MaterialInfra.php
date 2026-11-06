<?php

namespace App\Support;

use App\Models\BoqItem;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use App\Models\UjDetail;
use Illuminate\Support\Collection;

/**
 * Material tahap tipe Infra (owner 6 Nov 2026):
 *  - Uang Material Kas UJ (beli batu belah, Base A, sirdam, pasir, …) ikut biaya tahap lewat No DO rit-nya di Ritasi — sebelumnya tidak dihitung;
 *  - rit material (Harga Jual truk) & Uang Material masuk langsung ke item BOQ menurut jenis tanah rit (uraian item):
 *    batu → "batu kali", sirdam/Base A/Baskos → material sirdam/basecourse kelas A, makadam → material makadam, pasir → item pasir, tanah → cut and fill.
 * Jenis yang tidak punya item tetap di "biaya belum masuk item BOQ". Rit boulder = proyek ritasi tersendiri (bukan tahap Infra).
 */
class MaterialInfra
{
    /** [pola jenis tanah, pola uraian item BOQ] — urut: pertama yang cocok dipakai. */
    private const ATURAN = [
        ['/boulder|belah|tunel|batu/i', '/batu kali/i'],
        ['/sirdam|baskos|base ?a/i', '/material.*(sirdam|basecourse kelas a)/i'],
        ['/makadam/i', '/material.*makadam/i'],
        ['/pasir/i', '/pasir/i'],
        ['/tanah/i', '/cut and fill/i'],
    ];

    private static ?array $data = null;

    /** @return array<int, array{ritasi: array<int, object>, material: array<int, object>}> per tahap Infra */
    public static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $infra = ProyekTahap::where('tipe', 'infra')->pluck('id')->flip();
        $nama = Ritasi::whereNotNull('tahap')->distinct()->pluck('tahap')->mapWithKeys(fn ($n) => [$n => DaftarProyek::tahapId('ritasi', $n)])
            ->filter(fn ($id) => $id && isset($infra[$id]));
        $hasil = [];
        $perDo = [];
        foreach (Ritasi::whereIn('tahap', $nama->keys())->orderBy('tanggal')->get(['tanggal', 'tahap', 'no_do', 'no_seri', 'jenis_tanah', 'jenis_buangan', 'harga_jual', 'kubikasi', 'no_lambung']) as $r) {
            $id = $nama[$r->tahap];
            $x = (object) ['tanggal' => $r->tanggal?->toDateString(), 'jenis' => (string) $r->jenis_tanah, 'rp' => (float) $r->harga_jual, 'do' => $r->no_do,
                'ket' => trim('Angkutan '.$r->jenis_tanah.' '.$r->no_lambung.' · DO '.$r->no_do.' · '.$r->no_seri.($r->kubikasi ? ' · '.(float) $r->kubikasi.' m³' : '')),
                'ngepok' => strcasecmp((string) $r->jenis_buangan, 'Ngepok') === 0];
            $hasil[$id]['ritasi'][] = $x;
            if ($k = LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $r->no_do))) {
                $perDo[$k] ??= [$id, $x->jenis];
            }
        }
        foreach (UjDetail::where('biaya_transfer', false)->where('kategori', 'like', '%material%')->whereNotNull('no_do')
            ->get(['id_uj', 'tanggal', 'nama', 'keterangan', 'nominal', 'no_do']) as $u) {
            $k = LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $u->no_do));
            if ($k && isset($perDo[$k])) {
                [$id, $jenis] = $perDo[$k];
                $hasil[$id]['material'][] = (object) ['tanggal' => $u->tanggal?->toDateString(), 'jenis' => $jenis, 'rp' => (float) $u->nominal, 'do' => $u->no_do,
                    'ket' => trim($u->keterangan.' · '.$u->nama.' · DO '.$u->no_do), 'id_uj' => $u->id_uj];
            }
        }

        return self::$data = $hasil;
    }

    /** Jumlah Uang Material Kas UJ tahap (lewat No DO rit tahap itu). */
    public static function uangMaterial(int $tahapId): float
    {
        return (float) collect(self::data()[$tahapId]['material'] ?? [])->sum('rp');
    }

    /** Item BOQ untuk satu jenis tanah (null bila tidak ada yang cocok). */
    public static function item(string $jenis, Collection $items): ?BoqItem
    {
        foreach (self::ATURAN as [$pJenis, $pUraian]) {
            if (preg_match($pJenis, $jenis)) {
                return $items->first(fn (BoqItem $i) => preg_match($pUraian, (string) $i->uraian));
            }
        }

        return null;
    }

    /**
     * Rit material & Uang Material yang masuk langsung ke item BOQ tahap ini.
     *
     * @return array{item: array<int, float>, trx: array<int, array<int, object>>, ritasi: float, material: float}
     */
    public static function keBoq(ProyekTahap $t): array
    {
        $items = BoqItem::where('proyek_tahap_id', $t->id)->get();
        $hasil = ['item' => [], 'trx' => [], 'ritasi' => 0.0, 'material' => 0.0];
        if ($items->isEmpty()) {
            return $hasil;
        }
        $d = self::data()[$t->id] ?? [];
        foreach (['ritasi' => 'Ritasi', 'material' => 'Kas UJ'] as $k => $sumber) {
            foreach ($d[$k] ?? [] as $x) {
                if (! empty($x->ngepok) || ! ($i = self::item($x->jenis, $items))) {
                    continue;
                }
                $hasil['item'][$i->id] = ($hasil['item'][$i->id] ?? 0) + $x->rp;
                $hasil[$k] += $x->rp;
                $hasil['trx'][$i->id][] = (object) ['sumber' => $k === 'ritasi' ? 'ritasi' : 'kasuj', 'tanggal' => $x->tanggal, 'keterangan' => $x->ket, 'catatan' => null,
                    'pic' => null, 'akun' => $k === 'ritasi' ? 'Harga Jual ritasi (truk)' : 'Uang Material (Kas UJ)', 'kode' => $x->id_uj ?? null, 'no_id' => null, 'nominal' => $x->rp];
            }
        }

        return $hasil;
    }
}
