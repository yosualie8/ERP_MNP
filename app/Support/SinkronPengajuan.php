<?php

namespace App\Support;

use App\Models\KasRiwayat;
use App\Models\Ritasi;
use App\Models\UjPengajuan;
use App\Models\UjPengajuanDetail;
use App\Models\UjPengalihan;
use Illuminate\Support\Facades\DB;

/**
 * Sinkron data bongkaran (upload Excel surat jalan di Monitor Ritasi) dengan satu Pengajuan UJ (owner 22 Okt 2026): DO pengajuan yang
 * tidak punya bongkaran dibatalkan. Hanya pengajuan yang belum ada realisasinya (daftarPengajuan / tolak). Dicocokkan per DT: jumlah
 * surat jalan DT itu di Excel = jumlah DO pengajuan yang dipertahankan — DO yang sudah tercatat bongkar di Data Ritasi selalu
 * dipertahankan, sisanya urut nomor DO. Untuk DO yang dibuang, SEMUA baris pengajuannya
 * (Uang Jalan, Uang Tanah, dst.) dibatalkan bila masih menunggu; yang sudah ditransfer (terealisasi / dialihkan) hanya dilaporkan.
 */
class SinkronPengajuan
{
    /** Upload surat jalan yang tercatat (KasRiwayat aksi rit-unggah), terbaru dulu. */
    public static function daftarUnggah(int $batas = 15)
    {
        return KasRiwayat::with('user')->where('aksi', 'rit-unggah')->latest('id')->limit($batas)->get()
            ->filter(fn ($r) => ! empty($r->isi['per_dt']))->values();
    }

    /**
     * Pengajuan yang bisa disinkronkan (owner 22 Okt 2026): belum ada satu pun baris yang terealisasi / dialihkan — satu pengajuan
     * utuh = satu rombongan rit. Pengajuan "sebagian" (mis. UJ sudah ditransfer, uang tanah belum) tidak bisa: DO dari hari lain
     * bisa ikut terbatalkan.
     */
    public static function daftarPengajuan(int $batas = 40)
    {
        return UjPengajuan::where('status', 'diajukan')->whereDoesntHave('detail', fn ($q) => $q->whereIn('status', ['terealisasi', 'dialihkan']))
            ->latest('id')->limit($batas)->get();
    }

    /** Alasan pengajuan tidak bisa disinkronkan, atau null bila bisa. */
    public static function tolak(UjPengajuan $p): ?string
    {
        $real = $p->detail()->whereIn('status', ['terealisasi', 'dialihkan'])->count();
        if ($real) {
            return "{$p->kode()} sudah punya {$real} baris yang terealisasi / dialihkan — hanya pengajuan yang belum ada realisasinya yang bisa disinkronkan. Batalkan baris yang tidak jadi lewat menu Pengajuan UJ.";
        }

        return $p->status === 'diajukan' ? null : "{$p->kode()} berstatus ".(UjPengajuan::STATUS[$p->status] ?? $p->status).' — tidak ada baris yang bisa disinkronkan.';
    }

    /** @return array<string, int> DT → jumlah surat jalan di upload itu */
    public static function ritPerDt(KasRiwayat $unggah): array
    {
        $per = [];
        foreach ($unggah->isi['per_dt'] ?? [] as $g) {
            if ($dt = NomorMobil::rapikan($g['dt'] ?? null)) {
                $per[$dt] = ($per[$dt] ?? 0) + (int) ($g['sj'] ?? 0);
            }
        }
        ksort($per, SORT_NATURAL);

        return $per;
    }

    /**
     * @return array{per_dt: array<int, array>, batal: int[], lapor: array<int, array>, lebih: array<string, int>, tanpa_mobil: int}
     */
    public static function rencana(KasRiwayat $unggah, UjPengajuan $p): array
    {
        $rit = self::ritPerDt($unggah);
        $kunci = fn ($v) => LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $v));
        $detail = $p->detail()->orderBy('urut')->get();
        $sudahBongkar = Ritasi::whereIn('no_do', $detail->pluck('no_do')->filter()->map(fn ($d) => $kunci($d))->filter()->unique()->values())
            ->pluck('no_do')->map(fn ($d) => $kunci($d))->flip();

        $perDt = [];
        $batal = [];
        $lapor = [];
        $tanpaMobil = 0;
        foreach ($detail->groupBy(fn ($d) => NomorMobil::rapikan($d->no_mobil) ?: '') as $dt => $baris) {
            if ($dt === '') {
                $tanpaMobil += $baris->count();

                continue;
            }
            // Per DO (satu DO bisa beberapa baris: Uang Jalan + Uang Tanah …); baris tanpa No DO tidak ikut dibuang.
            $perDo = $baris->filter(fn ($d) => $kunci($d->no_do))->groupBy(fn ($d) => $kunci($d->no_do));
            $urut = $perDo->keys()->sortBy(fn ($do) => [isset($sudahBongkar[$do]) ? 0 : 1, str_pad((string) $do, 12, '0', STR_PAD_LEFT)])->values();
            $n = $rit[$dt] ?? 0;
            // DO yang sudah tercatat bongkar di Data Ritasi TIDAK PERNAH dibatalkan (walau Excel menyebut lebih sedikit rit);
            // sisa kuota dipakai DO lain urut nomor.
            $bongkar = $urut->filter(fn ($do) => isset($sudahBongkar[$do]))->values();
            $lain = $urut->reject(fn ($do) => isset($sudahBongkar[$do]))->values();
            $sisaKuota = max(0, $n - $bongkar->count());
            $simpan = $bongkar->concat($lain->take($sisaKuota))->values();
            $buang = $lain->slice($sisaKuota)->values();
            foreach ($buang as $do) {
                foreach ($perDo[$do] as $d) {
                    if ($d->status === 'menunggu') {
                        $batal[] = $d->id;
                    } elseif (in_array($d->status, ['terealisasi', 'dialihkan'], true)) {
                        $lapor[] = ['dt' => $dt, 'do' => (string) $do, 'nama' => $d->nama, 'kategori' => $d->kategori, 'nominal' => (int) $d->nominal, 'id_uj' => $d->id_uj, 'status' => $d->status];
                    }
                }
            }
            $perDt[] = ['dt' => $dt, 'sopir' => $baris->pluck('nama')->filter()->unique()->implode(', '), 'rit_excel' => $n, 'do_pengajuan' => $urut->count(),
                'simpan' => $simpan->all(), 'buang' => $buang->all(), 'sudah_bongkar' => $urut->filter(fn ($do) => isset($sudahBongkar[$do]))->values()->all(),
                'baris_batal' => $buang->sum(fn ($do) => $perDo[$do]->where('status', 'menunggu')->count()),
                // Rincian per kategori (satu DO biasanya 2 baris: Uang Jalan + Uang Tanah).
                'kategori_batal' => $buang->flatMap(fn ($do) => $perDo[$do]->where('status', 'menunggu'))->groupBy('kategori')
                    ->map(fn ($g) => ['n' => $g->count(), 'rp' => (int) $g->sum(fn ($d) => $d->nominal - $d->saldo)])->all(),
                'nominal_batal' => (int) $buang->sum(fn ($do) => $perDo[$do]->where('status', 'menunggu')->sum(fn ($d) => $d->nominal - $d->saldo))];
        }
        $diPengajuan = collect($perDt)->pluck('dt')->flip();
        $lebih = collect($rit)->filter(fn ($n, $dt) => ! isset($diPengajuan[$dt]))->all();
        foreach ($perDt as $x) {
            if ($x['rit_excel'] > $x['do_pengajuan']) {
                $lebih[$x['dt']] = $x['rit_excel'] - $x['do_pengajuan'];
            }
        }
        sort($batal);

        return ['per_dt' => $perDt, 'batal' => $batal, 'lapor' => $lapor, 'lebih' => $lebih, 'tanpa_mobil' => $tanpaMobil];
    }

    /** Batalkan baris pengajuan yang dibuang (pemakaian saldo sopir baris itu dikembalikan ke saldo). @return int jumlah baris */
    public static function jalankan(KasRiwayat $unggah, UjPengajuan $p, array $batal, int $userId): int
    {
        $file = $unggah->isi['file'] ?? 'Excel';
        $alasan = mb_strimwidth("Sinkron bongkaran \"{$file}\" (".$unggah->created_at->translatedFormat('j M H:i').'): DO ini tidak ada di data bongkar', 0, 250, '…');

        return DB::transaction(function () use ($p, $batal, $alasan, $unggah, $userId, $file) {
            $ids = UjPengajuanDetail::where('uj_pengajuan_id', $p->id)->whereIn('id', $batal)->where('status', 'menunggu')->pluck('id');
            UjPengalihan::whereIn('uj_pengajuan_detail_id', $ids)->delete();
            UjPengajuanDetail::whereIn('id', $ids)->update(['status' => 'batal', 'alasan' => $alasan]);
            $p->refresh()->hitungStatus();
            KasRiwayat::create(['aksi' => 'uj-ajukan-batal', 'lembar' => 'Pengajuan', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $userId,
                'ringkasan' => mb_strimwidth("{$p->kode()}: {$ids->count()} baris dibatalkan lewat sinkron bongkaran \"{$file}\"", 0, 490, '…'),
                'isi' => ['pengajuan_id' => $p->id, 'unggah' => $unggah->id, 'detail' => $ids->all()]]);

            return $ids->count();
        });
    }
}
