<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monitor DO dari Kas UJ:
 * - belumBongkar(): DO yang sudah punya transaksi di Kas UJ tetapi nomornya belum ada di data Ritasi (belum bongkar / ritasinya belum diinput).
 * - belumBayarTanah(): DO yang sudah ada Uang Jalan-nya tetapi belum ada transaksi Uang Tanah-nya di Kas UJ.
 * Nomor DO dicocokkan dengan LembarRitasi::kunciAngka (tanpa spasi & nol di depan). Isian "No DO" yang bukan angka
 * (mis. "Marunda", "Gagal Bongkar", "FRK001") bukan nomor DO, jadi dipisahkan.
 */
class MonitorRitasi
{
    /** @return array{do: Collection<int, array>, bukan_angka: Collection<int, string>} */
    public static function belumBongkar(): array
    {
        return self::kelompok(fn (string $do, Collection $baris, array $ritasi) => ! isset($ritasi[$do]));
    }

    /** @return array{do: Collection<int, array>, bukan_angka: Collection<int, string>} */
    public static function belumBayarTanah(): array
    {
        $jenis = fn (Collection $baris, string $k) => $baris->contains(fn ($d) => ValidasiUj::jenisKategori($d->kategori) === $k);

        return self::kelompok(fn (string $do, Collection $baris) => $jenis($baris, 'uj') && ! $jenis($baris, 'ut'));
    }

    /**
     * Semua transaksi Kas UJ ber-No DO, dikelompokkan per DO; hanya DO yang lolos $termasuk yang diambil.
     *
     * @param  callable(string, Collection, array): bool  $termasuk  (kunci DO, transaksi DO itu, peta DO ritasi => tanggal rit pertama)
     */
    private static function kelompok(callable $termasuk): array
    {
        $kunci = fn ($v) => LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $v));
        $ritasi = [];
        foreach (Ritasi::whereNotNull('no_do')->orderBy('tanggal')->get(['no_do', 'tanggal']) as $r) {
            if ($k = $kunci($r->no_do)) {
                $ritasi[$k] ??= $r->tanggal;
            }
        }
        $detail = UjDetail::where('biaya_transfer', false)->whereNotNull('no_do')->where('no_do', '!=', '')
            ->orderBy('tanggal')->orderBy('baris')
            ->get(['id_uj', 'baris', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do', 'status']);

        $bukanAngka = collect();
        $per = [];
        foreach ($detail as $d) {
            $k = $kunci($d->no_do);
            if ($k === null || ! ctype_digit($k)) {
                $bukanAngka->push(trim((string) $d->no_do));

                continue;
            }
            $per[$k][] = $d;
        }
        $hariIni = Carbon::today();
        $do = collect($per)->map(fn ($b) => collect($b))->filter(fn (Collection $c, $k) => $termasuk((string) $k, $c, $ritasi))
            ->map(function (Collection $c, $k) use ($hariIni, $ritasi) {
                $pertama = $c->first()->tanggal;

                return [
                    'do' => (string) $k,
                    'pertama' => $pertama,
                    'terakhir' => $c->last()->tanggal,
                    'umur' => $pertama ? (int) $pertama->diffInDays($hariIni) : null,
                    'mobil' => $c->pluck('no_mobil')->filter()->unique()->values()->all(),
                    'driver' => $c->pluck('nama')->filter()->unique()->values()->all(),
                    'tujuan' => TebakGalian::tempat($c->pluck('keterangan')),
                    'kategori' => $c->pluck('kategori')->filter()->unique()->values()->all(),
                    'total' => (int) $c->sum('nominal'),
                    'bongkar' => $ritasi[(string) $k] ?? null,
                    'detail' => $c->all(),
                ];
            })->sortByDesc(fn ($d) => ($d['pertama']?->format('Ymd') ?? '0').str_pad($d['do'], 10, '0', STR_PAD_LEFT))->values();

        return ['do' => $do, 'bukan_angka' => $bukanAngka->unique()->values()];
    }
}
