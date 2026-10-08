<?php

namespace App\Support;

use App\Models\AsetTruk;
use App\Models\KasRiwayat;
use App\Models\UjDetail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Perbaikan penulisan Kas UJ (lembar "Kas Seabank" kolom I Kategori, J Jenis Kendaraan, K No Mobil): ditulis ke sheet dan aplikasi
 * sekaligus (selama Kas UJ masih diimpor dari sheet). Setiap baris dicek dulu masih sama di sheet (ID UJ & nominal).
 */
class RapikanUj
{
    /**
     * @param  array<int, array{baris: int, kategori?: string, jenis?: ?string, no_mobil?: ?string}>  $ubah  per baris sheet
     * @return array{ditulis: int, dilewati: array<int, string>}
     */
    public static function tulis(array $ubah, ?int $userId, string $alasan): array
    {
        if (! $ubah) {
            return ['ditulis' => 0, 'dilewati' => []];
        }

        return Cache::lock('tulis-uj-sheet', 300)->block(120, function () use ($ubah, $userId, $alasan) {
            $sheets = GoogleSheets::wajib();
            $l = KasSeabank::LEMBAR;
            $baris = collect($ubah)->keyBy('baris');
            $db = UjDetail::whereIn('baris', $baris->keys())->get()->keyBy('baris');
            [$dari, $sampai] = [$baris->keys()->min(), $baris->keys()->max()];
            $isi = $sheets->nilaiMentah(KasSeabank::id(), ["{$l}!A{$dari}:K{$sampai}"])["{$l}!A{$dari}:K{$sampai}"];

            $data = [];
            $dilewati = [];
            $sebelum = [];
            foreach ($baris as $n => $u) {
                $d = $db[$n] ?? null;
                $r = $isi[$n - $dari] ?? [];
                if (! $d || trim((string) ($r[0] ?? '')) !== (string) $d->id_uj || KasSeabank::angka($r[7] ?? null) !== (int) $d->nominal) {
                    $dilewati[$n] = 'baris '.$n.' di sheet sudah berubah';

                    continue;
                }
                $sebelum[$n] = ['id_uj' => $d->id_uj, 'kategori' => $r[8] ?? null, 'jenis' => $r[9] ?? null, 'no_mobil' => $r[10] ?? null];
                foreach (['kategori' => 'I', 'jenis' => 'J', 'no_mobil' => 'K'] as $f => $kol) {
                    if (array_key_exists($f, $u)) {
                        $data["{$l}!{$kol}{$n}"] = [[(string) ($u[$f] ?? '')]];
                    }
                }
            }
            foreach (array_chunk($data, 400, true) as $potong) {
                $sheets->tulis(KasSeabank::id(), $potong);
            }
            DB::transaction(function () use ($baris, $sebelum) {
                foreach (array_keys($sebelum) as $n) {
                    $u = $baris[$n];
                    $kolom = [];
                    foreach (['kategori' => 'kategori', 'jenis' => 'jenis_kendaraan', 'no_mobil' => 'no_mobil'] as $f => $db) {
                        if (array_key_exists($f, $u)) {
                            $kolom[$db] = $u[$f] === '' ? null : $u[$f];
                        }
                    }
                    UjDetail::where('baris', $n)->update($kolom);
                }
            });
            KasRiwayat::create(['aksi' => 'uj-rapikan', 'lembar' => 'Seabank', 'baris_awal' => (int) $dari, 'baris_akhir' => (int) $sampai,
                'ringkasan' => mb_strimwidth($alasan.': '.count($sebelum).' baris', 0, 490, '…'),
                'isi' => ['sebelum' => $sebelum, 'sesudah' => $baris->only(array_keys($sebelum))->all()], 'user_id' => $userId]);

            return ['ditulis' => count($sebelum), 'dilewati' => $dilewati];
        });
    }

    /**
     * Perbaikan otomatis yang pasti: kategori → nama baku; No Mobil kosong diisi dari baris lain ber-DO sama (tepat satu DT);
     * Jenis Kendaraan kosong/berbeda diisi dari Data Aset untuk baris ber-No Mobil.
     *
     * @return array<int, array> per baris sheet
     */
    public static function rencanaOtomatis(): array
    {
        $k = fn ($v) => LembarRitasi::kunciAngka($v);
        $aset = AsetTruk::pluck('jenis', 'no_lambung');
        $uj = UjDetail::where('biaya_transfer', false)->get(['baris', 'id_uj', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do']);
        $doDt = $uj->filter(fn ($u) => $u->no_mobil && $k($u->no_do))->groupBy(fn ($u) => $k($u->no_do))->map(fn ($g) => $g->pluck('no_mobil')->unique()->values());

        $rencana = [];
        foreach ($uj as $u) {
            $r = [];
            $baku = KategoriUj::baku($u->kategori);
            if ($baku !== null && $baku !== $u->kategori) {
                $r['kategori'] = $baku;
            }
            $mobil = $u->no_mobil;
            if (! $mobil && $k($u->no_do) && ($dt = $doDt[$k($u->no_do)] ?? null) && $dt->count() === 1) {
                $r['no_mobil'] = $mobil = $dt[0];
            }
            if ($mobil && ($j = $aset[$mobil] ?? null) && $j !== $u->jenis_kendaraan) {
                $r['jenis'] = $j;
            }
            if ($r) {
                $rencana[$u->baris] = ['baris' => $u->baris, ...$r];
            }
        }

        return $rencana;
    }
}
