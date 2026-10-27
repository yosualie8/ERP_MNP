<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasReimburseUj;
use App\Models\KasTransfer;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjReimburse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Input Kas → "Input reimburse Kas UJ": uang jalan yang sudah direimburse (Kas Seabank kolom N) dicatat di Kas Harian sebagai satu
 * transfer "Reimburse Uang Jalan" per reimburse (batch menu Reimburse UJ; data lama tanpa batch: per tanggal), satu transaksi detail per baris UJ (pola Kas Harian sejak Sep 2026:
 * tanggal transfer = tanggal reimburse, total & jumlah baris sama persis).
 * Saat disimpan, tiap detail ditautkan ke baris UJ-nya (tabel kas_reimburse_uj) supaya Mutasi Reimburse kolom C, E, H–L terisi.
 */
class ReimburseUjKeKas
{
    public const KETERANGAN = 'Reimburse Uang Jalan';

    /**
     * Ringkasan per reimburse (terbaru dulu). Setiap batch menu Reimburse UJ (tabel uj_reimburse) berdiri sendiri dengan kunci
     * "r{id}" (RUJ-0007), jadi beberapa reimburse dalam satu hari bisa dicatat terpisah. Baris UJ yang ber-tanggal reimburse
     * tetapi tidak termasuk batch mana pun (data lama / diisi langsung di sheet) dikelompokkan per tanggal (kunci = tanggal).
     *
     * @return array<int, array{kunci: string, tanggal: string, judul: string, kode: ?string, jumlah: int, total: int, kas: ?array}>
     */
    public static function daftar(int $hari = 30): array
    {
        $dari = now()->subDays($hari)->toDateString();
        $hasil = [];
        $tercakup = [];
        foreach (UjReimburse::where('tanggal', '>=', $dari)->orderByDesc('tanggal')->orderByDesc('id')->get() as $r) {
            $baris = self::barisBatch($r);
            foreach ($baris as $d) {
                $tercakup[$d->id] = true;
            }
            $hasil[] = [
                'kunci' => 'r'.$r->id, 'tanggal' => $r->tanggal->toDateString(), 'kode' => self::kode($r),
                'judul' => $r->tanggal->translatedFormat('l, j M Y'), 'jam' => $r->created_at?->format('H:i'),
                'jumlah' => $baris->count(), 'total' => (int) $baris->sum('nominal'), 'kas' => self::sudahDiKasBatch($r, $baris),
            ];
        }
        // Sisa per tanggal (tanpa batch).
        $sisa = UjDetail::whereNotNull('tanggal_reimburse')->where('tanggal_reimburse', '>=', $dari)->whereNotIn('id', array_keys($tercakup) ?: [0])
            ->get()->groupBy(fn ($d) => $d->tanggal_reimburse->toDateString());
        foreach ($sisa as $tgl => $baris) {
            $t = Carbon::parse($tgl);
            $hasil[] = ['kunci' => $tgl, 'tanggal' => $tgl, 'kode' => null, 'judul' => $t->translatedFormat('l, j M Y'), 'jam' => null,
                'jumlah' => $baris->count(), 'total' => (int) $baris->sum('nominal'), 'kas' => self::sudahDiKas($t, (int) $baris->sum('nominal'))];
        }
        usort($hasil, fn ($x, $y) => [$y['tanggal'], $y['kunci']] <=> [$x['tanggal'], $x['kunci']]);

        return $hasil;
    }

    public static function kode(UjReimburse $r): string
    {
        return 'RUJ-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT);
    }

    /** Batch dari kunci "r{id}"; null bila kunci berupa tanggal (data tanpa batch). */
    public static function batch(string $kunci): ?UjReimburse
    {
        return preg_match('/^r(\d+)$/', $kunci, $m) ? UjReimburse::find((int) $m[1]) : null;
    }

    /** Baris UJ satu batch: lewat ID UJ yang disalin saat reimburse; baris tanpa ID lewat nomor baris + nominal. */
    public static function barisBatch(UjReimburse $r): Collection
    {
        $isi = collect($r->isi ?? []);
        $id = $isi->pluck('id_uj')->filter()->unique()->values();
        // Baris yang saat reimburse belum ber-ID (mis. biaya transfer yang ID-nya diberikan belakangan): cocokkan nomor baris + nominal.
        $tanpaId = $isi->filter(fn ($x) => empty($x['id_uj']) && ! empty($x['baris']));
        return UjDetail::query()
            ->where(fn ($q) => $q->whereIn('id_uj', $id->all() ?: ['-'])
                ->orWhere(fn ($q2) => $tanpaId->each(fn ($x) => $q2->orWhere(fn ($q3) => $q3->where('baris', $x['baris'])->where('nominal', $x['nominal'] ?? 0)))))
            ->orderBy('baris')->get();
    }

    /** Baris UJ untuk kunci form: batch (r{id}) atau tanggal reimburse (baris yang tidak termasuk batch mana pun). */
    public static function barisKunci(string $kunci): Collection
    {
        if ($r = self::batch($kunci)) {
            return self::barisBatch($r);
        }
        $tercakup = UjReimburse::whereDate('tanggal', $kunci)->get()->flatMap(fn ($r) => self::barisBatch($r)->pluck('id'))->all();

        return UjDetail::whereDate('tanggal_reimburse', $kunci)->whereNotIn('id', $tercakup ?: [0])->orderBy('baris')->get();
    }

    /**
     * Transfer Kas Harian yang sudah mencatat batch ini: tautan batch, tautan ID UJ-nya (dicatat sebelum ada batch), atau (data
     * lama) transfer "Reimburse Uang Jalan" bertanggal sama dengan nominal sama yang belum dipakai batch lain.
     *
     * @return array{no_id: int, nominal: int, tanggal: string, lewat_aplikasi: bool}|null
     */
    public static function sudahDiKasBatch(UjReimburse $r, ?Collection $baris = null): ?array
    {
        $baris ??= self::barisBatch($r);
        $noId = KasReimburseUj::where('uj_reimburse_id', $r->id)->value('no_id_transfer')
            ?? KasReimburseUj::whereIn('id_uj', $baris->pluck('id_uj')->filter()->all() ?: ['-'])->value('no_id_transfer');
        if ($noId) {
            $t = KasTransfer::where('no_id', $noId)->first();

            return ['no_id' => (int) $noId, 'nominal' => (int) ($t->kredit ?? 0), 'tanggal' => $t?->tanggal?->translatedFormat('j M Y') ?? '', 'lewat_aplikasi' => true];
        }
        $dipakai = KasReimburseUj::whereNotNull('no_id_transfer')->distinct()->pluck('no_id_transfer')->all();
        $t = KasTransfer::where('keterangan', self::KETERANGAN)->whereDate('tanggal', $r->tanggal)->where('kredit', (int) $baris->sum('nominal'))
            ->whereNotIn('no_id', $dipakai ?: [0])->orderBy('baris')->first();

        return $t ? ['no_id' => (int) $t->no_id, 'nominal' => (int) $t->kredit, 'tanggal' => $t->tanggal->translatedFormat('j M Y'), 'lewat_aplikasi' => false] : null;
    }

    /** Status Kas Harian untuk kunci form (batch atau tanggal). */
    public static function sudahDiKasKunci(string $kunci): ?array
    {
        return ($r = self::batch($kunci)) ? self::sudahDiKasBatch($r) : self::sudahDiKas(Carbon::parse($kunci), (int) self::barisKunci($kunci)->sum('nominal'));
    }

    /**
     * Data tanpa batch: transfer Kas Harian yang sudah mencatat reimburse tanggal ini (tautan per tanggal tanpa batch, atau
     * transfer "Reimburse Uang Jalan" bertanggal sama — bila nominal diberikan, harus sama).
     *
     * @return array{no_id: int, nominal: int, tanggal: string, lewat_aplikasi: bool}|null
     */
    public static function sudahDiKas(Carbon $tanggal, ?int $nominal = null): ?array
    {
        if ($noId = KasReimburseUj::whereDate('tanggal_reimburse', $tanggal)->whereNull('uj_reimburse_id')->value('no_id_transfer')) {
            $t = KasTransfer::where('no_id', $noId)->first();

            return ['no_id' => (int) $noId, 'nominal' => (int) ($t->kredit ?? 0), 'tanggal' => $t?->tanggal?->translatedFormat('j M Y') ?? '', 'lewat_aplikasi' => true];
        }
        $dipakai = KasReimburseUj::whereNotNull('no_id_transfer')->distinct()->pluck('no_id_transfer')->all();
        $calon = KasTransfer::where('keterangan', self::KETERANGAN)->whereDate('tanggal', $tanggal)->whereNotIn('no_id', $dipakai ?: [0])->orderBy('baris')->get();
        // Nominal sama diutamakan; data lama (satu reimburse per hari) cukup tanggalnya.
        $t = ($nominal ? $calon->firstWhere('kredit', $nominal) : null) ?? $calon->first();

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

    /** Isi form untuk satu reimburse (kunci batch r{id} atau tanggal): master + detail (dengan kunci baris UJ). */
    public static function untukForm(string $kunci): array
    {
        $r = self::batch($kunci);
        $tgl = $r ? $r->tanggal : Carbon::parse($kunci);
        $b = self::bawaan();
        $baris = self::barisKunci($kunci);
        $galian = self::galian($baris);
        $kas = self::sudahDiKasKunci($kunci);
        // Kelebihan reimburse sebelumnya (mis. UJ dobel yang sudah ikut ditransfer) dikurangkan dari reimburse yang belum dicatat.
        $potongan = $kas ? [] : PotonganReimburseUj::untukForm($b['pic'], $b['kode_gl']);

        return [
            'kunci' => $kunci, 'kode' => $r ? self::kode($r) : null,
            'tanggal' => $tgl->toDateString(),
            'judul' => $tgl->translatedFormat('l, j M Y').($r ? ' · '.self::kode($r) : ''),
            'kas' => $kas,
            'master' => [...$b, 'nominal' => (int) $baris->sum('nominal') + array_sum(array_column($potongan, 'nominal'))],
            'detail' => $baris->toBase()->map(fn (UjDetail $d) => [
                'uj' => self::kunci($d), 'id_uj' => $d->id_uj, 'tanggal_uj' => $d->tanggal?->translatedFormat('j M'), 'nama' => $d->nama,
                'kategori' => $d->biaya_transfer ? 'Biaya Transfer' : $d->kategori, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do,
                'galian' => $galian[self::kunci($d)] ?? null,
                'nominal' => (int) $d->nominal, 'pic' => $b['pic'], 'keterangan' => self::keterangan($d), 'kode_gl' => $b['kode_gl'],
            ])->values()->merge($potongan)->all(),
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
     * @param  ?\Carbon\CarbonInterface  $tanggalKas  tanggal transfer Kas Harian (untuk ID transaksi detail potongan)
     */
    public static function catat(string $kunci, array $bon, array $noIds, ?int $userId, ?\Carbon\CarbonInterface $tanggalKas = null): int
    {
        $r = self::batch($kunci);
        $tanggal = $r ? $r->tanggal->toDateString() : $kunci;
        $baris = self::barisKunci($kunci)->keyBy(fn ($d) => self::kunci($d));
        $galian = self::galian($baris->values());
        $n = 0;
        foreach (array_values($bon) as $i => $b) {
            if (($p = PotonganReimburseUj::dariKunci($b['uj'] ?? null)) && isset($noIds[$i])) {
                PotonganReimburseUj::tandaiDipakai($p['id'], $noIds[$i], $r ? self::kode($r) : $tanggal, $userId);
                // Kelebihannya dulu sudah ditandai "Sudah reimburse" di Kas Harian tanpa dibayar owner → detail minus ini juga,
                // supaya tidak terpotong dua kali.
                if (! empty($p['lunas_kas']) && $tanggalKas) {
                    StatusReimburse::tandai([$tanggalKas->format('ymd').'-Jago-'.$noIds[$i]], now(), $userId);
                }

                continue;
            }
            $d = $baris[$b['uj'] ?? ''] ?? null;
            if (! $d || ! isset($noIds[$i])) {
                continue;
            }
            KasReimburseUj::updateOrCreate(['no_id' => $noIds[$i]], [
                'no_id_transfer' => $noIds[0], 'uj_reimburse_id' => $r?->id, 'tanggal_reimburse' => $tanggal, 'id_uj' => $d->id_uj, 'baris_uj' => $d->baris,
                'tanggal_uj' => $d->tanggal, 'jenis_kendaraan' => $d->jenis_kendaraan, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do,
                'galian' => $galian[self::kunci($d)] ?? null, 'kategori' => $d->biaya_transfer ? 'Biaya Transfer' : $d->kategori,
                'nominal' => (int) $b['nominal'], 'user_id' => $userId,
            ]);
            $n++;
        }

        return $n;
    }
}
