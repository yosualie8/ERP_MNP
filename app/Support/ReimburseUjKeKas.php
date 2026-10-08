<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasReimburseUj;
use App\Models\KasTransfer;
use App\Models\Ritasi;
use App\Models\UjDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Input Kas → "Input reimburse Kas UJ": uang jalan yang sudah direimburse (Kas Seabank kolom N) dicatat di Kas Harian sebagai satu
 * transfer "Reimburse Uang Jalan" per tanggal reimburse, satu transaksi detail per baris UJ (pola Kas Harian sejak Sep 2026:
 * tanggal transfer = tanggal reimburse, total & jumlah baris sama persis).
 * Saat disimpan, tiap detail ditautkan ke baris UJ-nya (tabel kas_reimburse_uj) supaya Mutasi Reimburse kolom C, E, H–L terisi.
 */
class ReimburseUjKeKas
{
    public const KETERANGAN = 'Reimburse Uang Jalan';

    /**
     * Ringkasan per tanggal reimburse (terbaru dulu).
     *
     * @return array<int, array{tanggal: string, judul: string, jumlah: int, total: int, kas: ?array}>
     */
    public static function daftar(int $hari = 60): array
    {
        $grup = UjDetail::whereNotNull('tanggal_reimburse')->where('tanggal_reimburse', '>=', now()->subDays($hari)->toDateString())
            ->selectRaw('tanggal_reimburse, COUNT(*) n, SUM(nominal) s')->groupBy('tanggal_reimburse')->orderByDesc('tanggal_reimburse')->get();

        return $grup->map(fn ($g) => [
            'tanggal' => $g->tanggal_reimburse->toDateString(),
            'judul' => $g->tanggal_reimburse->translatedFormat('l, j M Y'),
            'jumlah' => (int) $g->n,
            'total' => (int) $g->s,
            'kas' => self::sudahDiKas($g->tanggal_reimburse),
        ])->all();
    }

    /**
     * Transfer Kas Harian yang sudah mencatat reimburse tanggal ini: yang dibuat lewat fitur ini, atau (data lama)
     * transfer "Reimburse Uang Jalan" bertanggal sama.
     *
     * @return array{no_id: int, nominal: int, tanggal: string, lewat_aplikasi: bool}|null
     */
    public static function sudahDiKas(Carbon $tanggal): ?array
    {
        if ($noId = KasReimburseUj::whereDate('tanggal_reimburse', $tanggal)->value('no_id_transfer')) {
            $t = KasTransfer::where('no_id', $noId)->first();

            return ['no_id' => (int) $noId, 'nominal' => (int) ($t->kredit ?? 0), 'tanggal' => $t?->tanggal?->translatedFormat('j M Y') ?? '', 'lewat_aplikasi' => true];
        }
        $t = KasTransfer::where('keterangan', self::KETERANGAN)->whereDate('tanggal', $tanggal)->orderBy('baris')->first();

        return $t ? ['no_id' => (int) $t->no_id, 'nominal' => (int) $t->kredit, 'tanggal' => $t->tanggal->translatedFormat('j M Y'), 'lewat_aplikasi' => false] : null;
    }

    /** Baris UJ yang direimburse pada tanggal itu, urut seperti di Kas Seabank. */
    public static function baris(Carbon|string $tanggal): Collection
    {
        return UjDetail::whereDate('tanggal_reimburse', $tanggal)->orderBy('baris')->get();
    }

    /** Kunci baris UJ yang dibawa form: ID UJ, atau nomor baris sheet bila tak ber-ID. */
    public static function kunci(UjDetail $d): string
    {
        return $d->id_uj ?: 'b'.$d->baris;
    }

    /** Keterangan detail seperti yang selama ini ditulis admin: keterangan UJ + " DO No. 15211". */
    public static function keterangan(UjDetail $d): string
    {
        $ket = trim(preg_replace('/\s+/', ' ', (string) $d->keterangan)) ?: ($d->biaya_transfer ? 'Biaya Transfer' : (string) $d->kategori);

        return ! $d->biaya_transfer && $d->no_do && ! str_contains($ket, 'DO No') ? "{$ket} DO No. {$d->no_do}" : $ket;
    }

    /**
     * Isian master & PIC/Kode GL bawaan, mengikuti transfer "Reimburse Uang Jalan" terakhir di Kas Harian.
     *
     * @return array{nama_tujuan: ?string, no_rek: ?string, bank: ?string, keterangan: string, pic: ?string, kode_gl: ?string}
     */
    public static function bawaan(): array
    {
        $t = KasTransfer::where('keterangan', self::KETERANGAN)->whereNotNull('nama_tujuan')->orderByDesc('tanggal')->orderByDesc('baris')->first();
        $bon = $t ? KasBon::with('kodeGl')->where('kas_transfer_id', $t->id)->get() : collect();

        return [
            'nama_tujuan' => $t?->nama_tujuan, 'no_rek' => $t ? preg_replace('/\D/', '', (string) $t->no_rek_tujuan) : null, 'bank' => $t?->bank_tujuan,
            'keterangan' => self::KETERANGAN,
            'pic' => $bon->pluck('pic')->filter()->countBy()->sortDesc()->keys()->first(),
            'kode_gl' => $bon->map(fn ($b) => $b->kodeGl?->kode_asli)->filter()->countBy()->sortDesc()->keys()->first(),
        ];
    }

    /** Isi form untuk satu tanggal reimburse: master + detail (dengan kunci baris UJ). */
    public static function untukForm(string $tanggal): array
    {
        $tgl = Carbon::parse($tanggal);
        $b = self::bawaan();
        $baris = self::baris($tgl);
        $galian = self::galian($baris);

        return [
            'tanggal' => $tgl->toDateString(),
            'judul' => $tgl->translatedFormat('l, j M Y'),
            'kas' => self::sudahDiKas($tgl),
            'master' => [...$b, 'nominal' => (int) $baris->sum('nominal')],
            'detail' => $baris->map(fn (UjDetail $d) => [
                'uj' => self::kunci($d), 'id_uj' => $d->id_uj, 'tanggal_uj' => $d->tanggal?->translatedFormat('j M'), 'nama' => $d->nama,
                'kategori' => $d->biaya_transfer ? 'Biaya Transfer' : $d->kategori, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do,
                'galian' => $galian[self::kunci($d)] ?? null,
                'nominal' => (int) $d->nominal, 'pic' => $b['pic'], 'keterangan' => self::keterangan($d), 'kode_gl' => $b['kode_gl'],
            ])->values()->all(),
        ];
    }

    /**
     * Galian per baris UJ: dari rit dengan No DO yang sama (paling pasti), kalau belum ada ditebak dari keterangan UJ DO itu.
     *
     * @return array<string, string> kunci baris UJ => galian
     */
    public static function galian(Collection $baris): array
    {
        $do = $baris->pluck('no_do')->filter()->map(fn ($d) => LembarRitasi::kunciAngka($d))->filter()->unique()->values();
        if ($do->isEmpty()) {
            return [];
        }
        $dariRit = Ritasi::whereNotNull('no_do')->whereNotNull('galian')->orderBy('baris')->get(['no_do', 'galian'])
            ->filter(fn ($r) => $do->contains(LembarRitasi::kunciAngka($r->no_do)))
            ->mapWithKeys(fn ($r) => [LembarRitasi::kunciAngka($r->no_do) => $r->galian]);
        $uj = ValidasiRitasi::dariUj($do->all());
        $hasil = [];
        foreach ($baris as $d) {
            $k = LembarRitasi::kunciAngka($d->no_do);
            if ($k && ! $d->biaya_transfer && ($g = $dariRit[$k] ?? TebakGalian::galian($uj[$k]['tempat'] ?? null, null))) {
                $hasil[self::kunci($d)] = $g;
            }
        }

        return $hasil;
    }

    /**
     * Sesudah transfer ditulis ke Kas Harian: catat tautan detail (NO ID) ↔ baris UJ.
     *
     * @param  array<int, array>  $bon  bon dari form (urutan sama dengan NO ID yang ditulis), masing-masing boleh membawa 'uj'
     * @param  int[]  $noIds  NO ID baris yang ditulis (bon ke-i = NO ID ke-i)
     */
    public static function catat(string $tanggal, array $bon, array $noIds, ?int $userId): int
    {
        $baris = self::baris($tanggal)->keyBy(fn ($d) => self::kunci($d));
        $galian = self::galian($baris->values());
        $n = 0;
        foreach (array_values($bon) as $i => $b) {
            $d = $baris[$b['uj'] ?? ''] ?? null;
            if (! $d || ! isset($noIds[$i])) {
                continue;
            }
            KasReimburseUj::updateOrCreate(['no_id' => $noIds[$i]], [
                'no_id_transfer' => $noIds[0], 'tanggal_reimburse' => $tanggal, 'id_uj' => $d->id_uj, 'baris_uj' => $d->baris,
                'tanggal_uj' => $d->tanggal, 'jenis_kendaraan' => $d->jenis_kendaraan, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do,
                'galian' => $galian[self::kunci($d)] ?? null, 'kategori' => $d->biaya_transfer ? 'Biaya Transfer' : $d->kategori,
                'nominal' => (int) $b['nominal'], 'user_id' => $userId,
            ]);
            $n++;
        }

        return $n;
    }
}
