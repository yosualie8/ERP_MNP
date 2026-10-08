<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DO yang sudah punya transaksi di Kas UJ tetapi nomornya belum ada di data Ritasi (= belum bongkar / ritasinya belum diinput).
 * Nomor DO dicocokkan dengan LembarRitasi::kunciAngka (tanpa spasi & nol di depan). Isian "No DO" yang bukan angka
 * (mis. "Marunda", "Gagal Bongkar", "FRK001") bukan nomor DO, jadi dipisahkan.
 */
class MonitorRitasi
{
    /**
     * @return array{do: Collection<int, array>, bukan_angka: Collection<int, string>}
     */
    public static function belumBongkar(): array
    {
        $kunci = fn ($v) => LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $v));
        $sudah = Ritasi::whereNotNull('no_do')->pluck('no_do')->map($kunci)->filter()->flip();
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
            if (! isset($sudah[$k])) {
                $per[$k][] = $d;
            }
        }
        $hariIni = Carbon::today();
        $do = collect($per)->map(function (array $baris, string $k) use ($hariIni) {
            $c = collect($baris);
            $pertama = $c->first()->tanggal;

            return [
                'do' => $k,
                'pertama' => $pertama,
                'terakhir' => $c->last()->tanggal,
                'umur' => $pertama ? (int) $pertama->diffInDays($hariIni) : null,
                'mobil' => $c->pluck('no_mobil')->filter()->unique()->values()->all(),
                'driver' => $c->pluck('nama')->filter()->unique()->values()->all(),
                'tujuan' => TebakGalian::tempat($c->pluck('keterangan')),
                'kategori' => $c->pluck('kategori')->filter()->unique()->values()->all(),
                'total' => (int) $c->sum('nominal'),
                'detail' => $c->all(),
            ];
        })->sortByDesc(fn ($d) => ($d['pertama']?->format('Ymd') ?? '0').str_pad($d['do'], 10, '0', STR_PAD_LEFT))->values();

        return ['do' => $do, 'bukan_angka' => $bukanAngka->unique()->values()];
    }
}
