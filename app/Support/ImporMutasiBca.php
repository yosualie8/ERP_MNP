<?php

namespace App\Support;

use App\Models\KasRiwayat;
use App\Models\MutasiPt;
use App\Models\MutasiPtUnggah;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Simpan file mutasi KlikBCA Bisnis yang diupload ke Mutasi Rekening PT (owner 10 Nov 2026).
 * - Hanya rekening PT MNP (config mnp.rekening_pt); isi file diperiksa dulu oleh BacaMutasiBca (total, jumlah baris, saldo berjalan).
 * - Baris di rentang Periode file DIGANTI isi file (upload ulang / periode tumpang tindih aman). Kunci penandaan biaya
 *   (TandaMutasiPt::kunci = tanggal|DB/CR|jumlah|saldo|keterangan) sama dengan data lama, jadi tanda Dihitung tetap menempel.
 * - Baris "PEND" lama di luar rentang dihapus bila file ini memuat transaksi yang sama (sudah dibukukan, tanggal bisa bergeser).
 * - Peringatan (tidak menolak): saldo awal file tidak nyambung dengan baris sebelumnya / saldo akhir dengan baris sesudahnya
 *   → ada mutasi yang belum diupload.
 */
class ImporMutasiBca
{
    /** @return array{unggah: MutasiPtUnggah, baru: int, sama: int, diganti: int, pending: int, peringatan: string[]} */
    public static function simpan(string $path, string $namaAsli, ?int $userId): array
    {
        $d = BacaMutasiBca::baca($path, $namaAsli);
        $rek = (string) config('mnp.rekening_pt');
        if ($d['rekening'] !== $rek) {
            throw new RuntimeException("File ini mutasi rekening {$d['rekening']} ({$d['nama']}), bukan rekening PT MNP {$rek}.");
        }
        $kunci = fn ($b) => TandaMutasiPt::kunci(new MutasiPt($b));
        $tanpaSaldo = fn ($b) => implode('|', [$b['dbcr'], number_format((float) $b['jumlah'], 2, '.', ''), $b['keterangan']]);

        return DB::transaction(function () use ($d, $namaAsli, $userId, $kunci, $tanpaSaldo) {
            $lama = MutasiPt::whereBetween('tanggal', [$d['dari'], $d['sampai']])->lockForUpdate()->get();
            $kunciLama = $lama->map(fn ($m) => TandaMutasiPt::kunci($m))->countBy()->all();
            $baru = $sama = 0;
            foreach ($d['baris'] as $b) {
                $k = $kunci($b);
                if (($kunciLama[$k] ?? 0) > 0) {
                    $sama++;
                    $kunciLama[$k]--;
                } else {
                    $baru++;
                }
            }
            $diganti = array_sum($kunciLama);   // baris lama di rentang yang tidak ada lagi di file (mis. PEND yang sudah dibukukan)

            // PEND lama di luar rentang yang kini muncul di file (sudah dibukukan) → hapus supaya tidak dobel.
            $isiFile = collect($d['baris'])->map($tanpaSaldo)->countBy()->all();
            $pendLama = MutasiPt::where('pending', true)->where('tanggal', '<', $d['dari'])->get()
                ->filter(function ($m) use (&$isiFile, $tanpaSaldo) {
                    $k = $tanpaSaldo(['dbcr' => $m->dbcr, 'jumlah' => $m->jumlah, 'keterangan' => $m->keterangan]);
                    if (($isiFile[$k] ?? 0) > 0) {
                        $isiFile[$k]--;

                        return true;
                    }

                    return false;
                });

            $u = MutasiPtUnggah::create(['nama_file' => mb_strimwidth($namaAsli, 0, 200), 'rekening' => $d['rekening'], 'dari' => $d['dari'], 'sampai' => $d['sampai'],
                'baris' => count($d['baris']), 'pending' => count(array_filter($d['baris'], fn ($b) => $b['pending'])),
                'saldo_awal' => $d['saldo_awal'], 'saldo_akhir' => $d['saldo_akhir'], 'user_id' => $userId]);

            MutasiPt::whereBetween('tanggal', [$d['dari'], $d['sampai']])->delete();
            MutasiPt::whereIn('id', $pendLama->pluck('id'))->delete();
            $no = (int) MutasiPt::max('baris');
            $kini = now();
            MutasiPt::insert(array_map(function ($b) use (&$no, $u, $kini) {
                return ['baris' => ++$no, 'tanggal' => $b['tanggal'], 'keterangan' => $b['keterangan'], 'cabang' => $b['cabang'], 'dbcr' => $b['dbcr'],
                    'jumlah' => $b['jumlah'], 'saldo' => $b['saldo'], 'pending' => $b['pending'], 'unggah_id' => $u->id, 'created_at' => $kini, 'updated_at' => $kini];
            }, $d['baris']));

            // Kesinambungan saldo dengan data di luar rentang.
            $peringatan = [];
            $sebelum = MutasiPt::where('tanggal', '<', $d['dari'])->orderByDesc('tanggal')->orderByDesc('baris')->first();
            if ($sebelum && $sebelum->saldo !== null && abs($sebelum->saldo - $d['saldo_awal']) > 0.01) {
                $peringatan[] = 'Saldo awal file '.self::rp($d['saldo_awal']).' tidak sama dengan saldo terakhir sebelumnya ('.$sebelum->tanggal->translatedFormat('j M Y').': '
                    .self::rp($sebelum->saldo).') — ada mutasi '.$sebelum->tanggal->copy()->addDay()->translatedFormat('j M').' s/d '.\Carbon\Carbon::parse($d['dari'])->subDay()->translatedFormat('j M Y').' yang belum diupload.';
            }
            $sesudah = MutasiPt::where('tanggal', '>', $d['sampai'])->orderBy('tanggal')->orderBy('baris')->first();
            if ($sesudah && $sesudah->saldo !== null && abs($sesudah->saldo - ($sesudah->dbcr === 'CR' ? $sesudah->jumlah : -$sesudah->jumlah) - $d['saldo_akhir']) > 0.01) {
                $peringatan[] = 'Saldo akhir file '.self::rp($d['saldo_akhir']).' tidak nyambung dengan mutasi sesudahnya ('.$sesudah->tanggal->translatedFormat('j M Y').') — ada celah yang belum diupload.';
            }
            if ($u->pending) {
                $peringatan[] = "{$u->pending} transaksi masih \"PEND\" (belum dibukukan bank) — akan diganti otomatis saat mutasi hari itu diupload lagi.";
            }
            $u->update(['ringkasan' => ['baru' => $baru, 'sama' => $sama, 'diganti' => $diganti + $pendLama->count(), 'peringatan' => $peringatan]]);

            KasRiwayat::create(['aksi' => 'mutasi-pt-unggah', 'lembar' => 'Mutasi PT', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $userId,
                'ringkasan' => mb_strimwidth("Upload mutasi BCA {$namaAsli}: periode {$d['dari']} s/d {$d['sampai']}, ".count($d['baris'])." baris ({$baru} baru, {$sama} sudah ada)"
                    .($u->pending ? ", {$u->pending} PEND" : ''), 0, 490, '…'), 'isi' => ['unggah_id' => $u->id]]);

            return ['unggah' => $u, 'baru' => $baru, 'sama' => $sama, 'diganti' => $diganti + $pendLama->count(), 'pending' => $u->pending, 'peringatan' => $peringatan];
        });
    }

    private static function rp(float $v): string
    {
        return 'Rp'.number_format($v, 2, ',', '.');
    }
}
