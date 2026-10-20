<?php

namespace App\Support;

use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
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
        // UJ yang sudah dialihkan / dikembalikan / dibatalkan di Pengalihan UJ bukan lagi "belum bongkar"; transaksi lain DO itu sampai
        // tanggal pengalihannya (uang makan, sparepart perjalanan lama) ikut tertutup. Transaksi DO lama SESUDAH pengalihan (mis.
        // kekurangan UJ untuk tujuan baru) tetap dipantau. Pemakaian saldo sopir ber-DO baru (Pengajuan UJ) ikut dipantau bongkarnya.
        $tutup = UjPengalihan::whereIn('jenis', UjPengalihan::MENUTUP)->whereNotNull('id_uj')->get(['id_uj', 'tanggal']);
        $doTutup = [];
        foreach (UjDetail::whereIn('id_uj', $tutup->pluck('id_uj'))->get(['id_uj', 'no_do']) as $u) {
            if ($k = SaldoSopir::kunciDo($u->no_do)) {
                $tgl = $tutup->firstWhere('id_uj', $u->id_uj)->tanggal;
                $doTutup[$k] = isset($doTutup[$k]) ? max($doTutup[$k], $tgl) : $tgl;
            }
        }
        $idTutup = $tutup->pluck('id_uj')->flip();
        $saring = fn ($d, string $k) => ! isset($idTutup[$d->id_uj]) && ! (isset($doTutup[$k]) && $d->tanggal && $d->tanggal->lte($doTutup[$k]));

        return self::kelompok(fn (string $do, Collection $baris, array $ritasi) => ! isset($ritasi[$do]), $saring, self::dariSaldo());
    }

    /** Pemakaian saldo sopir ber-DO (Pengajuan UJ → Pakai saldo) dalam bentuk baris Kas UJ supaya ikut dipantau bongkarnya. */
    public static function dariSaldo(): array
    {
        return UjPengalihan::where('jenis', 'pakai')->whereNotNull('no_do')->orderBy('tanggal')->get()
            ->map(fn ($p) => (new UjDetail)->forceFill([
                'id_uj' => 'SALDO-'.$p->id, 'baris' => null, 'tanggal' => $p->tanggal, 'nama' => $p->nama, 'nominal' => $p->nominal,
                'keterangan' => 'Dibayar dari saldo sopir'.($p->keterangan ? ' — '.$p->keterangan : ''), 'kategori' => 'Uang Jalan',
                'jenis_kendaraan' => null, 'no_mobil' => $p->no_mobil, 'no_do' => $p->no_do, 'status' => 'saldo sopir', 'tahap_saldo' => SaldoSopir::tahapPakai($p),
            ]))->all();
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
     * @param  callable(UjDetail, string): bool|null  $saring  transaksi yang ikut dihitung (null = semua)
     * @param  array<int, UjDetail>  $tambahan  baris tambahan (mis. pemakaian saldo sopir) yang diperlakukan seperti transaksi Kas UJ
     */
    private static function kelompok(callable $termasuk, ?callable $saring = null, array $tambahan = []): array
    {
        $kunci = fn ($v) => LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $v));
        $ritasi = [];
        $suratJalan = [];
        foreach (Ritasi::whereNotNull('no_do')->orderBy('tanggal')->get(['no_do', 'tanggal', 'no_seri']) as $r) {
            if ($k = $kunci($r->no_do)) {
                $ritasi[$k] ??= $r->tanggal;
                if ($r->no_seri) {
                    $suratJalan[$k][] = $r->no_seri;
                }
            }
        }
        $detail = UjDetail::where('biaya_transfer', false)->whereNotNull('no_do')->where('no_do', '!=', '')
            ->orderBy('tanggal')->orderBy('baris')
            ->get(['id_uj', 'baris', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do', 'status']);

        $bukanAngka = collect();
        $per = [];
        foreach ([...$detail, ...$tambahan] as $d) {
            $k = $kunci($d->no_do);
            if ($k === null || ! ctype_digit($k)) {
                $bukanAngka->push(trim((string) $d->no_do));

                continue;
            }
            if ($saring && ! $saring($d, $k)) {
                continue;
            }
            $per[$k][] = $d;
        }
        $hariIni = Carbon::today();
        $do = collect($per)->map(fn ($b) => collect($b)->sortBy(fn ($d) => $d->tanggal?->format('Ymd'))->values())->filter(fn (Collection $c, $k) => $termasuk((string) $k, $c, $ritasi))
            ->map(function (Collection $c, $k) use ($hariIni, $ritasi, $suratJalan) {
                $pertama = $c->first()->tanggal;

                return [
                    'do' => (string) $k,
                    'pertama' => $pertama,
                    'terakhir' => $c->last()->tanggal,
                    'umur' => $pertama ? (int) $pertama->diffInDays($hariIni) : null,
                    'mobil' => $c->pluck('no_mobil')->filter()->unique()->values()->all(),
                    'jenis' => $c->pluck('jenis_kendaraan')->filter()->unique()->values()->all(),
                    'driver' => $c->pluck('nama')->filter()->unique()->values()->all(),
                    'tujuan' => TebakGalian::tempat($c->pluck('keterangan')),
                    'kategori' => $c->pluck('kategori')->filter()->unique()->values()->all(),
                    'total' => (int) $c->sum('nominal'),
                    'bongkar' => $ritasi[(string) $k] ?? null,
                    // No Surat Jalan (No Seri) rit DO itu di data Ritasi.
                    'surat_jalan' => array_values(array_unique($suratJalan[(string) $k] ?? [])),
                    'detail' => $c->all(),
                ];
            })->sortByDesc(fn ($d) => ($d['pertama']?->format('Ymd') ?? '0').str_pad($d['do'], 10, '0', STR_PAD_LEFT))->values();

        return ['do' => $do, 'bukan_angka' => $bukanAngka->unique()->values()];
    }
}
