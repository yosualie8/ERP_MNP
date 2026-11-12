<?php

namespace App\Models;

use App\Support\LembarRitasi;
use App\Support\UnggahBongkar;
use App\Support\ValidasiUj;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Rit yang sudah bongkar tetapi belum punya uang jalan / DO (owner 11 Nov 2026): dari upload surat jalan Monitor Ritasi yang
 * "menunggu uang jalan". Tampil di menu Bayar Tanah; uang tanahnya boleh dibayar lebih dulu memakai No Surat Jalan — detail Uang Tanah
 * tanpa No DO dengan penanda "[SJ <No Surat Jalan>]" di keterangan. Begitu rit itu tersimpan di Ritasi dengan DO-nya, baris ini selesai
 * sendiri (tidak tampil lagi) dan DO itu dianggap sudah bayar tanah lewat penandanya (MonitorRitasi::belumBayarTanah).
 */
class RitasiMenunggu extends Model
{
    protected $table = 'ritasi_menunggu';

    protected $fillable = ['no_mobil', 'sopir', 'jenis_kendaraan', 'tanggal', 'jam', 'tahap', 'galian', 'jenis_tanah', 'no_seri', 'kubikasi', 'keterangan', 'user_id'];

    protected $casts = ['tanggal' => 'date'];

    /** Penanda uang tanah yang dibayar lewat No Surat Jalan, ditulis di keterangan detail Uang Tanah. */
    public static function penanda(string $noSeri): string
    {
        return '[SJ '.trim($noSeri).']';
    }

    /** No Surat Jalan dari penanda di keterangan, null bila tidak ada. */
    public static function seriDariKeterangan(?string $ket): ?string
    {
        return preg_match('/\[SJ\s+([^\]]+)\]/i', (string) $ket, $m) ? trim($m[1]) : null;
    }

    /** Kode pilihan di menu Bayar Tanah / isi Rekap Bayar Tanah (pengganti nomor DO). */
    public function kode(): string
    {
        return 'SJ'.$this->id;
    }

    /** @return int|null id dari kode "SJ12" */
    public static function idDariKode(?string $kode): ?int
    {
        return preg_match('/^SJ(\d+)$/', trim((string) $kode), $m) ? (int) $m[1] : null;
    }

    /**
     * Uang tanah yang sudah dibayar lewat No Surat Jalan.
     *
     * @return array<string, string> kunci No Surat Jalan → ID UJ
     */
    public static function sjDibayar(): array
    {
        $hasil = [];
        foreach (UjDetail::where('biaya_transfer', false)->where('keterangan', 'like', '%[SJ %')->get(['id_uj', 'keterangan', 'kategori']) as $d) {
            if (ValidasiUj::jenisKategori($d->kategori) === 'ut' && ($s = self::seriDariKeterangan($d->keterangan)) && ($k = LembarRitasi::kunciAngka($s))) {
                $hasil[$k] ??= (string) $d->id_uj;
            }
        }

        return $hasil;
    }

    /**
     * DO yang uang tanahnya sudah dibayar lewat No Surat Jalan: rit di Ritasi yang No Seri-nya sama dengan penanda, atau penanda + akhiran
     * ("LS-2611-0720-Kendal" bila saat disimpan bentrok dengan proyek lain).
     *
     * @return array<string, string> kunci DO → ID UJ uang tanahnya
     */
    public static function doDibayarLewatSj(): array
    {
        $sj = self::sjDibayar();
        if (! $sj) {
            return [];
        }
        $hasil = [];
        $rit = Ritasi::whereNotNull('no_do')->where(function ($q) use ($sj) {
            $q->whereIn(\Illuminate\Support\Facades\DB::raw("TRIM(LEADING '0' FROM REPLACE(no_seri, ' ', ''))"), array_map('strval', array_keys($sj)));
            foreach (array_keys($sj) as $k) {
                $q->orWhere('no_seri', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $k).'-%');
            }
        })->get(['no_do', 'no_seri']);
        foreach ($rit as $r) {
            $kSeri = (string) LembarRitasi::kunciAngka($r->no_seri);
            foreach ($sj as $k => $idUj) {
                if ($kSeri === (string) $k || str_starts_with($kSeri, $k.'-')) {
                    if ($kDo = LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $r->no_do))) {
                        $hasil[$kDo] = $idUj;
                    }
                    break;
                }
            }
        }

        return $hasil;
    }

    /**
     * Rit menunggu yang No Surat Jalan-nya belum tercatat di Ritasi, beserta status uang tanahnya (dibayar = ID UJ, null = belum).
     *
     * @return Collection<int, self>
     */
    public static function aktif(): Collection
    {
        $semua = self::orderBy('tanggal')->orderBy('jam')->get();
        if ($semua->isEmpty()) {
            return $semua;
        }
        $sudah = UnggahBongkar::sudahAda($semua->map(fn ($r) => ['no_seri' => $r->no_seri, 'tahap' => $r->tahap, 'dt' => $r->no_mobil])->all());
        $dibayar = self::sjDibayar();

        return $semua->reject(fn ($r, $i) => isset($sudah[$i]))->values()
            ->each(fn ($r) => $r->setAttribute('dibayar', $dibayar[LembarRitasi::kunciAngka($r->no_seri)] ?? null));
    }

    /**
     * Pasangkan rit menunggu ke DO yang kini sudah ada (owner 12 Nov 2026 — kasus Aris DT 056 LS-2611-0730: uang jalannya masuk
     * sesudah upload, lalu tidak ada yang memasangkan ulang sehingga rit tidak pernah masuk Ritasi). Aturan pemasangan sama dengan
     * upload Excel (UnggahBongkar::cocokkan: per DT + galian, FIFO); belum disimpan — hasilnya mengisi form Monitor Ritasi.
     *
     * @param  Collection<int, array>  $belum  DO belum bongkar (MonitorRitasi::belumBongkar()['do'])
     * @return array{rit: Collection<int, self>, isian: array<string, array>, per_dt: array<int, array>, do: array<string, string>}
     *               do = id rit menunggu → DO pasangannya
     */
    public static function pasangkan(Collection $belum): array
    {
        $rit = self::aktif();
        if ($rit->isEmpty()) {
            return ['rit' => $rit, 'isian' => [], 'per_dt' => [], 'do' => []];
        }
        $sj = $rit->map(fn ($r) => [
            'baris' => $r->id, 'dt' => $r->no_mobil, 'tanggal' => $r->tanggal->copy(), 'tanggal_asli' => $r->tanggal->format('d/m/Y'), 'jam' => $r->jam,
            'tahap' => $r->tahap, 'jenis_tanah' => $r->jenis_tanah, 'no_seri' => $r->no_seri, 'galian' => $r->galian, 'keterangan' => $r->keterangan,
            'kubikasi' => $r->kubikasi === null || $r->kubikasi === '' ? null : (float) $r->kubikasi, 'kubikasi_asli' => null, 'pemilik' => null, 'mk' => false,
        ])->values()->all();
        $hasil = UnggahBongkar::cocokkan($sj, $belum);
        $do = [];
        foreach ($hasil['per_dt'] as $p) {
            foreach ($p['pasangan'] as $x) {
                $do[$x['baris']] = $x['do'];
            }
        }

        return ['rit' => $rit, 'isian' => $hasil['isian'], 'per_dt' => $hasil['per_dt'], 'do' => $do];
    }

    /**
     * Simpan surat jalan "menunggu uang jalan" dari satu upload (yang sudah ada diperbarui, kunci tujuan + No Surat Jalan).
     * Sopir & jenis kendaraan = uang jalan terakhir DT itu di Kas UJ.
     *
     * @param  array<int, array>  $perDt  UnggahBongkar::cocokkan()['per_dt']
     * @param  array<int, array>  $sj  baris surat jalan Excel (UnggahBongkar::baca) — sumber kolom lengkapnya
     */
    public static function simpanDariUnggah(array $perDt, array $sj, ?int $userId): int
    {
        $lengkap = collect($sj)->keyBy('baris');
        $n = 0;
        foreach ($perDt as $p) {
            foreach ($p['menunggu'] ?? [] as $x) {
                $s = $lengkap[$x['baris']] ?? null;
                if (! $s) {
                    continue;
                }
                $uj = UjDetail::where('no_mobil', $s['dt'])->where('kategori', 'Uang Jalan')->orderByDesc('tanggal')->orderByDesc('baris')->first(['nama', 'jenis_kendaraan']);
                self::updateOrCreate(['tahap' => $s['tahap'], 'no_seri' => trim((string) $s['no_seri'])], [
                    'no_mobil' => $s['dt'], 'sopir' => $uj?->nama, 'jenis_kendaraan' => $uj?->jenis_kendaraan,
                    'tanggal' => $s['tanggal']->toDateString(), 'jam' => $s['jam'], 'galian' => $s['galian'], 'jenis_tanah' => $s['jenis_tanah'],
                    'kubikasi' => $s['kubikasi'] === null ? null : (string) $s['kubikasi'], 'keterangan' => $s['keterangan'] ? mb_strimwidth($s['keterangan'], 0, 250, '…') : null,
                    'user_id' => $userId,
                ]);
                $n++;
            }
        }

        return $n;
    }
}
