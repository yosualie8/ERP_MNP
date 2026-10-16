<?php

namespace App\Support;

use App\Models\BoqItem;
use App\Models\BoqOpname;
use App\Models\ProyekTahap;
use App\Models\TagihanItem;

/**
 * Progres per item BOQ satu tahap (owner 16 Okt 2026) — tiga sudut:
 *  - MODAL: biaya dihitung yang diberi kode BOQ item ÷ modal item (material + jasa);
 *  - TERPASANG: max(volume opname terakhir, volume sudah ditagih) ÷ volume BOQ — yang sudah ditagih pasti sudah terpasang (owner 16 Okt 2026);
 *  - TERTAGIH: volume ditagih (semua tagihan progres) ÷ volume BOQ.
 * Total terpasang/tertagih = rata-rata tertimbang nilai jual; total modal = semua biaya dihitung tahap (termasuk yang
 * belum diberi kode BOQ) ÷ total modal.
 */
class ProgresBoq
{
    public static function hitung(ProyekTahap $t): array
    {
        $items = BoqItem::where('proyek_tahap_id', $t->id)->orderBy('urutan')->orderBy('id')->get();
        $opname = BoqOpname::whereIn('boq_item_id', $items->pluck('id'))->orderBy('tanggal')->orderBy('id')->get()->groupBy('boq_item_id')->map->last();
        $tagih = TagihanItem::whereIn('boq_item_id', $items->pluck('id'))->selectRaw('boq_item_id, sum(volume) v, sum(nilai) rp')->groupBy('boq_item_id')->get()->keyBy('boq_item_id');

        $rb = PilahTransaksi::realisasiBoq()[$t->id] ?? ['total' => 0.0, 'item' => [], 'n_tanpa' => 0, 'trx' => [], 'tanpa' => []];
        [$biaya, $biayaTotal, $nTanpa] = [$rb['item'], $rb['total'], $rb['n_tanpa']];

        $nilaiTotal = $items->sum(fn ($i) => $i->nilai());
        $baris = $items->map(function (BoqItem $i) use ($opname, $tagih, $biaya, $nilaiTotal, $rb) {
            $vol = (float) $i->volume;
            $op = $opname[$i->id]->volume ?? null;
            $tg = $tagih[$i->id] ?? null;
            $tgV = $tg ? (float) $tg->v : 0.0;
            // terpasang minimal = yang sudah ditagih (tagihan pasti sudah diopname)
            $pasang = $op === null && $tgV <= 0 ? null : max((float) $op, $tgV);

            return (object) [
                'item' => $i, 'trx' => $rb['trx'][$i->id] ?? [], 'nilai' => $i->nilai(), 'bobot' => $nilaiTotal > 0 ? $i->nilai() / $nilaiTotal * 100 : null, 'modal' => $i->modal(),
                'biaya' => $biaya[$i->id] ?? 0.0, 'p_modal' => $i->modal() > 0 ? ($biaya[$i->id] ?? 0) / $i->modal() * 100 : null,
                'terpasang' => $pasang, 'opname' => $op, 'pasang_dari' => $pasang === null ? null : ($op !== null && $op >= $tgV ? 'opname' : 'tagihan'), 'tgl_opname' => $opname[$i->id]->tanggal ?? null, 'p_pasang' => $vol > 0 && $pasang !== null ? $pasang / $vol * 100 : null,
                'tertagih' => $tg ? (float) $tg->v : 0.0, 'rp_tagih' => $tg ? (float) $tg->rp : 0.0, 'p_tagih' => $vol > 0 && $tg ? $tg->v / $vol * 100 : null,
            ];
        });
        $modalTotal = $baris->sum('modal');
        $biayaBoq = array_sum($biaya);

        return [
            'baris' => $baris, 'nilai' => $nilaiTotal, 'modal' => $modalTotal, 'biaya' => $biayaTotal, 'biaya_boq' => $biayaBoq,
            'biaya_tanpa' => $biayaTotal - $biayaBoq, 'n_tanpa' => $nTanpa, 'trx_tanpa' => $rb['tanpa'],
            'p_modal' => $modalTotal > 0 ? $biayaTotal / $modalTotal * 100 : null,
            'p_pasang' => $nilaiTotal > 0 ? $baris->sum(fn ($b) => $b->nilai * min(1, ($b->p_pasang ?? 0) / 100)) / $nilaiTotal * 100 : null,
            'p_tagih' => $nilaiTotal > 0 ? $baris->sum('rp_tagih') / $nilaiTotal * 100 : null,
            'rp_tagih' => $baris->sum('rp_tagih'),
            'ada_opname' => $baris->contains(fn ($b) => $b->terpasang !== null), // ada data terpasang (opname atau tagihan)
        ];
    }

    /**
     * Baca item BOQ dari lembar penawaran berformat "Monitoring" (sheet Infra): kolom A kode (A1, B12, …), B uraian,
     * C satuan, D volume, E harga satuan, K total modal material, N total jasa borongan. Baris tanpa kode dilewati.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function bacaSheet(string $id, string $lembar): array
    {
        $r = "'".str_replace("'", "''", $lembar)."'!A1:N300";
        $v = GoogleSheets::wajib()->nilaiMentah($id, [$r])[$r] ?? [];
        $angka = fn ($x) => is_numeric($x) ? (float) $x : (is_string($x) && preg_match('/^-?[\d.]+(,\d+)?$/', trim($x)) ? (float) str_replace(['.', ','], ['', '.'], trim($x)) : null);
        $hasil = [];
        foreach ($v as $x) {
            $kode = strtoupper(trim((string) ($x[0] ?? '')));
            if (! preg_match('/^[A-Z]\d{1,2}$/', $kode) || trim((string) ($x[1] ?? '')) === '') {
                continue;
            }
            $hasil[] = ['kode' => $kode, 'uraian' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $x[1])), 0, 255), 'satuan' => mb_substr(trim((string) ($x[2] ?? '')), 0, 20) ?: null,
                'volume' => $angka($x[3] ?? null), 'harga' => $angka($x[4] ?? null), 'modal_material' => $angka($x[10] ?? null), 'modal_jasa' => $angka($x[13] ?? null)];
        }

        return $hasil;
    }
}
