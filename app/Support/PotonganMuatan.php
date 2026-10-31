<?php

namespace App\Support;

use App\Models\AsetTruk;
use App\Models\PengaturanApp;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengalihan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Potongan muatan kurang (owner 31 Okt 2026): hanya rit yang diukur loadscanner (No Seri "LS-…") di tahap ber-harga per m³ (Kendal,
 * Jogja). Standar volume per jenis truk (Data Aset): Faw 28 m³, Mercy 27,5 m³; toleransi 1 m³ — tidak dipotong selama volume ≥
 * standar − toleransi. Di bawah batas: potongan = (standar − volume) × Rp41.000/m³. Direkap HARIAN per sopir (sopir dari Kas UJ lewat
 * No DO) lalu dicatat ke buku Hutang Supir (UjPengalihan jenis "muatan") bertanggal hari berikutnya → terpotong dari uang jalan besoknya.
 * Hanya hari bongkar ≥ tanggal mulai yang boleh dicatat; sebelum itu hanya laporan. Semua angka di pengaturan_app (bisa diubah di halaman).
 */
class PotonganMuatan
{
    public const BAWAAN = ['standar' => ['Faw' => 28.0, 'Mercy' => 27.5], 'toleransi' => 1.0, 'tarif' => 41000, 'mulai' => null];

    /** Penanda catatan di buku Hutang Supir: satu catatan per sopir per hari bongkar. */
    private const PENANDA = '[MUATAN %s]';

    /** @return array{standar: array<string, float>, toleransi: float, tarif: int, mulai: ?string} */
    public static function aturan(): array
    {
        $a = json_decode((string) PengaturanApp::ambil('potongan_muatan'), true) ?: [];

        return array_replace_recursive(self::BAWAAN, array_intersect_key($a, self::BAWAAN));
    }

    public static function simpanAturan(array $a, ?int $user): void
    {
        PengaturanApp::simpan('potongan_muatan', json_encode(array_intersect_key($a, self::BAWAAN)), $user);
    }

    /**
     * Semua rit ber-LS di tahap per m³ dalam rentang, dengan standar & potongannya.
     *
     * @return Collection<int, object>
     */
    public static function rit(Carbon $awal, Carbon $akhir, ?array $aturan = null): Collection
    {
        $a = $aturan ?? self::aturan();
        $jenis = AsetTruk::truk()->pluck('jenis', 'no_lambung');
        $rit = Ritasi::whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])->where('no_seri', 'like', 'LS-%')
            ->whereIn(DB::raw('LOWER(tahap)'), array_keys(LembarRitasi::HARGA_PER_M3))->orderBy('tanggal')->orderBy('no_seri')
            ->get(['id', 'tanggal', 'tahap', 'no_seri', 'no_lambung', 'no_polisi', 'galian', 'kubikasi', 'no_do']);
        // Sopir = nama di Kas UJ uang jalan DO itu (baku); bila tidak ketemu, nama driver di Ritasi.
        $sopirDo = UjDetail::where('kategori', 'Uang Jalan')->whereIn('no_do', $rit->pluck('no_do')->filter()->unique())->get(['no_do', 'nama'])
            ->keyBy(fn ($u) => (string) SaldoSopir::kunciDo($u->no_do))->map(fn ($u) => SaldoSopir::kunci($u->nama));

        return $rit->map(function ($r) use ($a, $jenis, $sopirDo) {
            $j = $jenis[$r->no_lambung] ?? null;
            $std = $a['standar'][$j] ?? null;
            $v = (float) $r->kubikasi;
            $kurang = $std !== null && $v > 0 && $v < $std - $a['toleransi'];

            return (object) [
                'id' => $r->id, 'tanggal' => $r->tanggal, 'tahap' => $r->tahap, 'ls' => $r->no_seri, 'dt' => $r->no_lambung, 'jenis' => $j, 'galian' => $r->galian,
                'do' => $r->no_do, 'sopir' => $sopirDo[(string) SaldoSopir::kunciDo($r->no_do)] ?? SaldoSopir::kunci($r->driver), 'volume' => $v,
                'standar' => $std, 'batas' => $std !== null ? round($std - $a['toleransi'], 2) : null, 'kurang' => $kurang,
                'potongan' => $kurang ? (int) round(($std - $v) * $a['tarif']) : 0,
            ];
        });
    }

    /**
     * Rekap harian per sopir (hanya yang punya rit kurang) + status pencatatan di Hutang Supir.
     *
     * @return Collection<int, object>
     */
    public static function harian(Carbon $awal, Carbon $akhir, ?array $aturan = null): Collection
    {
        $a = $aturan ?? self::aturan();
        $rit = self::rit($awal, $akhir, $a);
        $tercatat = UjPengalihan::where('jenis', 'muatan')->where('keterangan', 'like', '[MUATAN %')->get()
            ->keyBy(fn ($p) => self::kunciCatat($p->sopir, substr((string) $p->keterangan, 8, 10)));

        return $rit->groupBy(fn ($r) => $r->tanggal->toDateString().'|'.$r->sopir)->map(function ($g) use ($a, $tercatat) {
            $r0 = $g->first();
            $kurang = $g->where('kurang', true)->values();
            $tgl = $r0->tanggal->toDateString();
            $c = $tercatat[self::kunciCatat($r0->sopir, $tgl)] ?? null;

            return (object) [
                'tanggal' => $r0->tanggal, 'sopir' => $r0->sopir, 'dt' => $g->pluck('dt')->unique()->implode(', '), 'jenis' => $r0->jenis,
                'tahap' => $g->pluck('tahap')->unique()->implode(', '), 'rit' => $g->count(), 'rit_kurang' => $kurang->count(), 'kurang' => $kurang,
                'volume' => round($g->avg('volume'), 2), 'potongan' => (int) $kurang->sum('potongan'),
                'boleh' => $a['mulai'] && $tgl >= $a['mulai'], 'catat' => $c, 'beda' => $c && (int) round($c->nominal) !== (int) $kurang->sum('potongan'),
            ];
        })->sortBy(fn ($h) => $h->tanggal->format('Ymd').'|'.$h->sopir)->values();
    }

    /**
     * Catat (atau perbarui) potongan satu hari bongkar ke buku Hutang Supir. Sopir tanpa potongan / sudah sama dilewati.
     *
     * @return array{baru: int, ubah: int, hapus: int, total: int}
     */
    public static function catat(Carbon $hari, int $user): array
    {
        $a = self::aturan();
        if (! $a['mulai'] || $hari->toDateString() < $a['mulai']) {
            throw new \RuntimeException('Potongan muatan baru berlaku mulai '.($a['mulai'] ? Carbon::parse($a['mulai'])->translatedFormat('j M Y') : '(tanggal mulai belum diisi)').'.');
        }
        $n = ['baru' => 0, 'ubah' => 0, 'hapus' => 0, 'total' => 0];
        DB::transaction(function () use ($hari, $user, $a, &$n) {
            foreach (self::harian($hari, $hari, $a) as $h) {
                $ket = sprintf(self::PENANDA, $h->tanggal->toDateString()).' bongkar '.$h->tanggal->translatedFormat('j M').' '.$h->dt.': '
                    .$h->kurang->map(fn ($r) => $r->ls.' '.self::m3($r->volume).' m³ (std '.self::m3($r->standar).')')->implode('; ');
                if (! $h->potongan) {
                    if ($h->catat) {
                        $h->catat->delete();
                        $n['hapus']++;
                    }

                    continue;
                }
                $isi = ['jenis' => 'muatan', 'no_mobil' => $h->dt, 'nama' => $h->sopir, 'sopir' => $h->sopir, 'tanggal' => $h->tanggal->copy()->addDay()->toDateString(),
                    'nominal' => $h->potongan, 'keterangan' => mb_strimwidth($ket, 0, 490, '…'), 'user_id' => $user];
                if ($h->catat) {
                    if ($h->beda || $h->catat->keterangan !== $isi['keterangan']) {
                        $h->catat->update($isi);
                        $n['ubah']++;
                    }
                } else {
                    UjPengalihan::create($isi);
                    $n['baru']++;
                }
                $n['total'] += $h->potongan;
            }
        });
        SaldoSopir::lupakan();

        return $n;
    }

    public static function m3(?float $v): string
    {
        return $v === null ? '–' : rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }

    private static function kunciCatat(?string $sopir, string $tanggal): string
    {
        return mb_strtolower((string) $sopir).'|'.$tanggal;
    }
}
