<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use Illuminate\Support\Collection;

/**
 * Pemeriksaan rit saat input (pilihan user 7 Okt 2026: "DO dobel + cocokkan ke Kas UJ"). FLAG = perlu diverifikasi admin.
 * R1 No DO sudah dipakai rit lain · R2 No Seri sudah dipakai di tahap yang sama · R3 No DO belum ada di Kas UJ ·
 * R4 DT berbeda dengan uang jalan DO itu · R5 driver berbeda dengan uang jalan DO itu · R6 jenis kendaraan beda dari histori DT.
 */
class ValidasiRitasi
{
    /**
     * @param  array  $kepala  tahap, tanggal, …
     * @param  array<int, array>  $rit  indeks dipertahankan
     * @param  int|null  $kecualiBaris  baris yang sedang diedit (tidak dihitung sebagai pembanding)
     * @return array<int, array<int, array{kode: string, prioritas: string, pesan: string}>>
     */
    public static function periksa(array $kepala, array $rit, ?int $kecualiBaris = null): array
    {
        $doInput = collect($rit)->pluck('no_do')->map(fn ($d) => LembarRitasi::kunciAngka($d))->filter()->unique();
        $ritDo = Ritasi::query()->whereNotNull('no_do')->when($kecualiBaris, fn ($q) => $q->where('baris', '!=', $kecualiBaris))
            ->get(['baris', 'no_seri', 'tanggal', 'no_lambung', 'no_do', 'tahap'])
            ->filter(fn ($r) => $doInput->contains(LembarRitasi::kunciAngka($r->no_do)))
            ->groupBy(fn ($r) => LembarRitasi::kunciAngka($r->no_do));
        $seriInput = collect($rit)->pluck('no_seri')->map(fn ($s) => LembarRitasi::kunciAngka($s))->filter()->unique();
        $ritSeri = Ritasi::query()->where('tahap', $kepala['tahap'])->when($kecualiBaris, fn ($q) => $q->where('baris', '!=', $kecualiBaris))
            ->get(['baris', 'no_seri', 'tanggal', 'no_lambung'])
            ->filter(fn ($r) => $seriInput->contains(LembarRitasi::kunciAngka($r->no_seri)))
            ->groupBy(fn ($r) => LembarRitasi::kunciAngka($r->no_seri));
        $ujDo = UjDetail::query()->whereNotNull('no_do')->where('biaya_transfer', false)
            ->get(['id_uj', 'tanggal', 'nama', 'no_mobil', 'no_do', 'kategori'])
            ->filter(fn ($d) => $doInput->contains(LembarRitasi::kunciAngka($d->no_do)))
            ->groupBy(fn ($d) => LembarRitasi::kunciAngka($d->no_do));
        $jenisDt = Ritasi::whereNotNull('no_lambung')->whereNotNull('jenis_kendaraan')->where('tanggal', '>=', now()->subYear())
            ->selectRaw('no_lambung, jenis_kendaraan, COUNT(*) n')->groupBy('no_lambung', 'jenis_kendaraan')->get()
            ->groupBy('no_lambung')->map(fn ($g) => $g->sortByDesc('n')->first()->jenis_kendaraan);

        $temuan = [];
        $doSebelum = collect();
        $seriSebelum = collect();
        foreach ($rit as $i => $r) {
            $t = [];
            $flag = function (string $kode, string $prioritas, string $pesan) use (&$t) {
                $t[] = ['kode' => $kode, 'prioritas' => $prioritas, 'pesan' => $pesan];
            };
            $do = LembarRitasi::kunciAngka($r['no_do']);
            $seri = LembarRitasi::kunciAngka($r['no_seri']);
            $sebut = fn ($x) => 'baris '.$x->baris.' (No Seri '.$x->no_seri.', '.$x->tanggal?->translatedFormat('j M Y').($x->no_lambung ? ', '.$x->no_lambung : '').')';

            if ($do) {
                $lain = $ritDo->get($do, collect());
                if ($lain->isNotEmpty() || $doSebelum->has($do)) {
                    $flag('R1', 'tinggi', 'No DO '.$r['no_do'].' sudah dipakai rit lain: '.collect([...$lain->take(3)->map($sebut)->all(), ...($doSebelum->has($do) ? ['baris '.($doSebelum[$do] + 1).' input ini'] : [])])->implode('; ')
                        .' — satu DO normalnya satu rit.');
                }
                $uj = $ujDo->get($do, collect());
                if ($uj->isEmpty()) {
                    $flag('R3', 'sedang', 'No DO '.$r['no_do'].' belum ditemukan di Kas UJ (uang jalan) — pastikan uang jalannya sudah dicatat dan No DO-nya benar.');
                } else {
                    $dtUj = $uj->pluck('no_mobil')->filter()->unique()->values();
                    if ($r['no_lambung'] && $dtUj->isNotEmpty() && ! $dtUj->contains($r['no_lambung'])) {
                        $flag('R4', 'tinggi', "DT berbeda dengan uang jalan DO {$r['no_do']}: di sini {$r['no_lambung']}, di Kas UJ ".$dtUj->implode(', ').' ('.$uj->pluck('id_uj')->filter()->take(3)->implode(', ').').');
                    }
                    $kunciNama = fn ($n) => preg_replace('/[^a-z]/', '', strtolower((string) $n));
                    $namaUj = $uj->pluck('nama')->filter()->unique();
                    if ($r['driver'] && $namaUj->isNotEmpty() && ! $namaUj->contains(fn ($n) => $kunciNama($n) === $kunciNama($r['driver']))) {
                        $flag('R5', 'rendah', "Driver berbeda dengan uang jalan DO {$r['no_do']}: di sini {$r['driver']}, di Kas UJ ".$namaUj->take(3)->implode(', ').'.');
                    }
                }
                $doSebelum[$do] = $i;
            }
            if ($seri) {
                $lain = $ritSeri->get($seri, collect());
                if ($lain->isNotEmpty() || $seriSebelum->has($seri)) {
                    $flag('R2', 'tinggi', 'No Seri '.$r['no_seri'].' sudah dipakai di '.$kepala['tahap'].': '.collect([...$lain->take(3)->map($sebut)->all(), ...($seriSebelum->has($seri) ? ['baris '.($seriSebelum[$seri] + 1).' input ini'] : [])])->implode('; ').'.');
                }
                $seriSebelum[$seri] = $i;
            }
            if ($r['no_lambung'] && $r['jenis_kendaraan'] && ($seharusnya = $jenisDt[$r['no_lambung']] ?? null) && $seharusnya !== $r['jenis_kendaraan']) {
                $flag('R6', 'sedang', "{$r['no_lambung']} di histori ritasi tercatat {$seharusnya}, di sini {$r['jenis_kendaraan']}.");
            }
            if ($t) {
                $temuan[$i] = $t;
            }
        }

        return $temuan;
    }
}
