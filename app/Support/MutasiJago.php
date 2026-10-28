<?php

namespace App\Support;

use App\Models\JagoMutasi;
use App\Models\JagoUnggah;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mutasi Jago tahap 1 = PENCOCOK (owner 28 Okt 2026): PDF riwayat transaksi kantong Jago diunggah (Eddy 2× sehari; owner / Valen boleh),
 * disimpan sekali per ID# bank, lalu dicocokkan dengan buku aplikasi — input admin TIDAK diubah:
 *  - kantong Uang Jalan DT ↔ Kas UJ (master transfer = satu transfer bank; biaya Rp2.500 = baris bank sesudahnya; transfer masuk = Reimburse UJ);
 *  - kantong Kas MNP ↔ Kas Harian (satu baris bank = satu baris transfer, termasuk "Biaya Transfer Keluar"; saldo per baris sama).
 * Dicocokkan mulai 1 Okt 2026. Hasil per baris: cocok / ada catatan (tanggal, rekening, biaya, DO beda) / ada di bank belum diinput / diinput tidak ada di bank.
 */
class MutasiJago
{
    public const KANTONG = [
        'uj' => ['nama' => 'Uang Jalan DT', 'nomor' => '103180595342', 'buku' => 'Kas UJ', 'menu' => ['mutasi-jago']],
        'kas' => ['nama' => 'Kas MNP', 'nomor' => '102466547411', 'buku' => 'Kas Harian', 'menu' => ['input-kas', 'kas-harian']],
    ];

    public const MULAI = '2026-10-01';

    public const FOLDER = 'mutasi-jago';

    /** Kantong Kas MNP (Kas Harian) hanya untuk akun yang punya menu Kas Harian; kantong UJ untuk semua yang punya menu Mutasi Jago. */
    public static function bolehLihat(?User $u, string $kantong): bool
    {
        return $u && isset(self::KANTONG[$kantong]) && ($u->isSuperAdmin() || collect(self::KANTONG[$kantong]['menu'])->contains(fn ($m) => MenuAkses::boleh($u, $m)));
    }

    public static function pathPdf(string $sha): string
    {
        return storage_path('app/'.self::FOLDER.'/'.$sha.'.pdf');
    }

    /**
     * @return array{unggah: JagoUnggah, kantong: string, baru: int, sudah: int, peringatan: string[]}
     */
    public static function impor(string $path, ?string $namaFile, int $userId): array
    {
        $sha = hash_file('sha256', $path);
        $h = BacaMutasiJago::baca($path);
        $kantong = collect(self::KANTONG)->search(fn ($k) => $k['nomor'] === $h['nomor']);
        if ($kantong === false) {
            throw new RuntimeException("Kantong \"{$h['kantong']}\" ({$h['nomor']}) bukan kantong Kas MNP / Uang Jalan DT — file tidak diimpor.");
        }
        if ($masalah = BacaMutasiJago::periksaSaldo($h['baris'])) {
            throw new RuntimeException('File ditolak — saldo per baris tidak bersambung, kemungkinan file difilter / dicari atau terpotong: '.$masalah[0]
                .' Unduh ulang riwayat transaksi tanpa filter.');
        }
        $peringatan = [];
        if (JagoUnggah::where('sha256', $sha)->exists()) {
            $peringatan[] = 'File yang sama persis sudah pernah diunggah.';
        }
        $baris = $h['baris'];

        $hasil = DB::transaction(function () use ($h, $kantong, $baris, $sha, $namaFile, $userId) {
            $u = JagoUnggah::create(['kantong' => $kantong, 'nomor_kantong' => $h['nomor'], 'pemilik' => mb_substr($h['pemilik'], 0, 100), 'dari' => $h['dari'], 'sampai' => $h['sampai'],
                'nama_file' => $namaFile ? mb_substr($namaFile, 0, 255) : null, 'sha256' => $sha, 'jumlah_baris' => count($baris), 'user_id' => $userId]);
            $baru = 0;
            foreach ($baris as $b) {
                $id = $b['id_bank'] ?: 'TANPA-ID-'.$b['waktu']->format('YmdHi').'-'.(int) round($b['jumlah']).'-'.(int) round($b['saldo']);
                $m = JagoMutasi::firstOrCreate(['kantong' => $kantong, 'id_bank' => $id], [
                    'waktu' => $b['waktu'], 'nama' => mb_substr($b['nama'], 0, 150), 'bank' => $b['bank'] ? mb_substr($b['bank'], 0, 40) : null, 'rekening' => $b['rekening'],
                    'jenis' => mb_substr($b['jenis'], 0, 60), 'arah' => $b['arah'], 'biaya' => (bool) preg_match('/biaya|fee/i', $b['jenis']),
                    'catatan' => $b['catatan'] !== '' && $b['catatan'] !== '-' ? mb_substr($b['catatan'], 0, 500) : null, 'jumlah' => $b['jumlah'], 'saldo' => $b['saldo'], 'jago_unggah_id' => $u->id,
                ]);
                $baru += $m->wasRecentlyCreated ? 1 : 0;
            }
            $u->update(['baris_baru' => $baru]);

            return [$u, $baru];
        });
        [$u, $baru] = $hasil;

        // Sambungan dengan unggahan lain: saldo baris pertama file harus = saldo transaksi tersimpan sebelumnya + jumlahnya (begitu pula baris sesudah file).
        $pertama = $baris[0];
        $akhir = $baris[count($baris) - 1];
        $sebelum = JagoMutasi::where('kantong', $kantong)->where('waktu', '<', $pertama['waktu'])->orderByDesc('waktu')->orderByDesc('id')->first();
        if ($sebelum && abs($sebelum->saldo + $pertama['jumlah'] - $pertama['saldo']) >= 1) {
            $peringatan[] = 'Ada transaksi yang belum diunggah antara '.$sebelum->waktu->format('d-m-Y H:i').' dan '.$pertama['waktu']->format('d-m-Y H:i')
                .' (saldo tidak bersambung). Unggah riwayat untuk rentang itu.';
        }
        $sesudah = JagoMutasi::where('kantong', $kantong)->where('waktu', '>', $akhir['waktu'])->orderBy('waktu')->orderBy('id')->first();
        if ($sesudah && abs($akhir['saldo'] + $sesudah->jumlah - $sesudah->saldo) >= 1) {
            $peringatan[] = 'Ada transaksi yang belum diunggah antara '.$akhir['waktu']->format('d-m-Y H:i').' dan '.$sesudah->waktu->format('d-m-Y H:i').' (saldo tidak bersambung).';
        }
        $u->update(['peringatan' => $peringatan ? implode("\n", $peringatan) : null]);

        $file = self::pathPdf($sha);
        if (! is_file($file)) {
            @mkdir(dirname($file), 0775, true);
            copy($path, $file);
        }

        return ['unggah' => $u, 'kantong' => $kantong, 'baru' => $baru, 'sudah' => count($baris) - $baru, 'peringatan' => $peringatan];
    }

    /**
     * Pencocokan satu kantong untuk rentang tanggal.
     *
     * @return array{baris: Collection, hari: Collection, cakupan: array{0: ?Carbon, 1: ?Carbon}, buku: string}
     */
    public static function cocokkan(string $kantong, Carbon $dari, Carbon $sampai): array
    {
        $dari = $dari->copy()->startOfDay();
        $sampai = $sampai->copy()->endOfDay();
        $cakupan = [JagoMutasi::where('kantong', $kantong)->min('waktu'), JagoMutasi::where('kantong', $kantong)->max('waktu')];
        $cakupan = array_map(fn ($w) => $w ? Carbon::parse($w) : null, $cakupan);
        $bank = JagoMutasi::where('kantong', $kantong)->whereBetween('waktu', [$dari, $sampai])->orderBy('waktu')->orderBy('id')->get();

        // Unit bank: Kas UJ — transfer + baris biaya sesudahnya (menit sama) = satu unit; Kas Harian — tiap baris berdiri sendiri.
        $unit = [];
        foreach ($bank as $m) {
            $n = count($unit);
            if ($kantong === 'uj' && $m->biaya && $n && ! $unit[$n - 1]['m']->biaya && ! $unit[$n - 1]['fee'] && $unit[$n - 1]['m']->waktu->format('YmdHi') === $m->waktu->format('YmdHi')) {
                $unit[$n - 1]['fee'] = $m;
                $unit[$n - 1]['saldo'] = $m->saldo;

                continue;
            }
            $unit[] = ['m' => $m, 'fee' => null, 'saldo' => $m->saldo];
        }

        $buku = $kantong === 'uj' ? self::bukuUj($dari, $sampai) : self::bukuKas($dari, $sampai);
        $perNominal = collect($buku)->groupBy(fn ($b) => (string) round($b['nominal']));

        // Pasangan terbaik lebih dulu (greedy): nominal wajib sama, tanggal ±3 hari; rekening / saldo / nama / tanggal sama menambah skor.
        $calon = [];
        foreach ($unit as $i => $u) {
            foreach ($perNominal[(string) round($u['m']->jumlah)] ?? [] as $b) {
                if (($s = self::skor($u, $b, $kantong)) !== null) {
                    $calon[] = [$s, $i, $b['kunci']];
                }
            }
        }
        usort($calon, fn ($a, $b) => $b[0] <=> $a[0]);
        $pasang = $dipakai = [];
        foreach ($calon as [$s, $i, $k]) {
            if (! isset($pasang[$i]) && ! isset($dipakai[$k])) {
                $pasang[$i] = $k;
                $dipakai[$k] = true;
            }
        }
        $bukuKunci = collect($buku)->keyBy('kunci');

        $baris = collect();
        foreach ($unit as $i => $u) {
            $b = isset($pasang[$i]) ? $bukuKunci[$pasang[$i]] : null;
            $catatan = $b ? self::catatan($u, $b, $kantong) : [];
            $saran = null;
            if (! $b && ! $u['m']->biaya) {
                // Nominal beda tetapi rekening sama di hari yang sama → kemungkinan salah ketik nominal.
                $mirip = collect($buku)->first(fn ($x) => ! isset($dipakai[$x['kunci']]) && $x['tanggal']->isSameDay($u['m']->waktu) && self::rekMirip($x['rek'], $u['m']->rekening));
                $saran = $mirip ? 'Mungkin '.$mirip['id'].' ('.rp((int) abs($mirip['nominal'])).', '.$mirip['nama'].') — nominalnya beda.' : null;
            }
            $baris->push(['jenis' => 'bank', 'waktu' => $u['m']->waktu, 'tanggal' => $u['m']->waktu->copy()->startOfDay(), 'm' => $u['m'], 'fee' => $u['fee'], 'saldo' => $u['saldo'],
                'b' => $b, 'catatan' => $catatan, 'saran' => $saran,
                'status' => $b ? ($catatan ? 'catatan' : 'cocok') : ($u['m']->biaya ? 'biaya' : 'belum')]);
        }
        // Buku tanpa pasangan di rentang ini: "tidak ada di bank" bila tanggalnya sudah tercakup mutasi; sesudah unggahan terakhir = menunggu.
        foreach ($buku as $b) {
            if (isset($dipakai[$b['kunci']]) || $b['tanggal']->lt($dari) || $b['tanggal']->gt($sampai)) {
                continue;
            }
            $tercakup = $cakupan[0] && $b['tanggal']->gte($cakupan[0]->copy()->startOfDay()) && $b['tanggal']->lte($cakupan[1]->copy()->startOfDay());
            $baris->push(['jenis' => 'buku', 'waktu' => $b['tanggal']->copy()->endOfDay(), 'tanggal' => $b['tanggal'], 'm' => null, 'fee' => null, 'saldo' => null, 'b' => $b,
                'catatan' => [], 'saran' => null, 'status' => $tercakup ? 'tidak-di-bank' : 'menunggu']);
        }
        $baris = $baris->sortBy(fn ($r) => $r['waktu']->format('YmdHis').($r['m']?->id ? sprintf('%010d', $r['m']->id) : '9'))->values();

        $hari = $baris->groupBy(fn ($r) => $r['tanggal']->toDateString())->map(function (Collection $g, $tgl) use ($kantong) {
            $bankHari = $g->where('jenis', 'bank');
            $saldoBank = $bankHari->last()['saldo'] ?? null;
            $saldoBuku = $kantong === 'kas' ? DB::table('kas_transfer')->whereDate('tanggal', $tgl)->orderByDesc('baris')->value('saldo') : null;
            $n = $g->countBy('status');
            $masalah = ($n['catatan'] ?? 0) + ($n['belum'] ?? 0) + ($n['tidak-di-bank'] ?? 0) + ($n['biaya'] ?? 0);

            return ['tanggal' => Carbon::parse($tgl), 'bank' => $bankHari->count(), 'n' => $n, 'saldo_bank' => $saldoBank, 'saldo_buku' => $saldoBuku,
                'cocok100' => $bankHari->isNotEmpty() && $masalah === 0 && ($saldoBuku === null || $saldoBank === null || abs($saldoBank - $saldoBuku) < 1)];
        })->sortKeys();

        return ['baris' => $baris, 'hari' => $hari, 'cakupan' => $cakupan, 'buku' => self::KANTONG[$kantong]['buku']];
    }

    /** Kas UJ: master transfer (nominal keluar, biaya dari detail Biaya Transfer, DO detail) + Reimburse UJ (transfer masuk). */
    private static function bukuUj(Carbon $dari, Carbon $sampai): array
    {
        $rentang = [$dari->copy()->subDays(3)->toDateString(), $sampai->copy()->addDays(3)->toDateString()];
        $hasil = [];
        $detail = DB::table('uj_detail')->whereIn('uj_transaksi_id', DB::table('uj_transaksi')->whereBetween('tanggal', $rentang)->select('id'))
            ->get(['uj_transaksi_id', 'id_uj', 'nominal', 'no_do', 'biaya_transfer', 'keterangan'])->groupBy('uj_transaksi_id');
        foreach (DB::table('uj_transaksi')->whereBetween('tanggal', $rentang)->orderBy('baris')->get() as $t) {
            if ((float) $t->nominal <= 0) {
                continue;
            }
            $d = $detail[$t->id] ?? collect();
            $hasil[] = ['kunci' => 'uj'.$t->id, 'id' => 'UJ-'.$t->no_uj, 'tanggal' => Carbon::parse($t->tanggal)->startOfDay(), 'nominal' => -(float) $t->nominal,
                'rek' => $t->rekening, 'bank' => $t->bank, 'nama' => (string) $t->nama, 'biaya' => (float) $d->where('biaya_transfer', true)->sum('nominal'),
                'do' => $d->where('biaya_transfer', false)->pluck('no_do')->map(fn ($v) => SaldoSopir::kunciDo($v))->filter()->unique()->values()->all(),
                'ket' => (string) ($d->firstWhere('biaya_transfer', false)->keterangan ?? ''), 'n' => $d->where('biaya_transfer', false)->count(), 'saldo' => null, 'fee' => false,
                'url' => route('uj.edit', $t->no_uj)];
        }
        foreach (DB::table('uj_reimburse')->whereBetween('tanggal', $rentang)->get(['id', 'tanggal', 'total']) as $r) {
            $hasil[] = ['kunci' => 'ruj'.$r->id, 'id' => sprintf('RUJ-%04d', $r->id), 'tanggal' => Carbon::parse($r->tanggal)->startOfDay(), 'nominal' => (float) $r->total,
                'rek' => null, 'bank' => null, 'nama' => 'Reimburse UJ', 'biaya' => 0.0, 'do' => [], 'ket' => 'Reimburse UJ', 'n' => 0, 'saldo' => null, 'fee' => false, 'url' => null];
        }

        return $hasil;
    }

    /** Kas Harian: tiap baris transfer (debet = masuk, kredit = keluar), termasuk baris "Biaya Transfer Keluar". */
    private static function bukuKas(Carbon $dari, Carbon $sampai): array
    {
        $rentang = [$dari->copy()->subDays(3)->toDateString(), $sampai->copy()->addDays(3)->toDateString()];

        return DB::table('kas_transfer')->whereBetween('tanggal', $rentang)->orderBy('tanggal')->orderBy('baris')->get()->map(fn ($t) => [
            'kunci' => 'kas'.$t->id, 'id' => $t->no_id ? 'NO ID '.$t->no_id : 'baris '.$t->baris, 'tanggal' => Carbon::parse($t->tanggal)->startOfDay(),
            'nominal' => (float) $t->debet - (float) $t->kredit, 'rek' => $t->no_rek_tujuan, 'bank' => $t->bank_tujuan, 'nama' => (string) $t->nama_tujuan, 'biaya' => 0.0,
            'do' => [], 'ket' => (string) $t->keterangan, 'n' => 0, 'saldo' => (float) $t->saldo, 'fee' => (bool) preg_match('/^biaya transfer/i', trim((string) $t->keterangan)), 'url' => null,
        ])->reject(fn ($b) => abs($b['nominal']) < 0.5)->values()->all();
    }

    private static function skor(array $u, array $b, string $kantong): ?int
    {
        $m = $u['m'];
        if (abs($m->jumlah - $b['nominal']) > 0.5) {
            return null;
        }
        // Kas Harian: baris biaya hanya berpasangan dengan baris "Biaya Transfer" (dan sebaliknya).
        if ($kantong === 'kas' && $m->biaya !== $b['fee']) {
            return null;
        }
        $hari = abs($m->waktu->copy()->startOfDay()->diffInDays($b['tanggal'], false));
        if ($hari > 3) {
            return null;
        }
        $s = 100 - (int) $hari * 15;
        $r1 = self::digit($b['rek']);
        $r2 = self::digit($m->rekening);
        if ($r1 !== '' && $r2 !== '') {
            $s += $r1 === $r2 ? 60 : (self::rekMirip($b['rek'], $m->rekening) ? 35 : -25);
        }
        if ($b['saldo'] !== null && abs($b['saldo'] - $u['saldo']) < 1) {
            $s += 80;
        }
        $kata = fn ($t) => collect(preg_split('/[^A-Z]+/', mb_strtoupper((string) $t)))->filter(fn ($w) => strlen($w) >= 3);
        if ($kata($b['nama'])->intersect($kata($m->nama))->isNotEmpty()) {
            $s += 15;
        }

        return $s;
    }

    /** Selisih kecil pada pasangan yang cocok nominalnya. @return string[] */
    private static function catatan(array $u, array $b, string $kantong): array
    {
        $m = $u['m'];
        $c = [];
        if (! $b['tanggal']->isSameDay($m->waktu)) {
            $c[] = 'Tanggal di '.self::KANTONG[$kantong]['buku'].' '.$b['tanggal']->translatedFormat('j M').', di bank '.$m->waktu->translatedFormat('j M').'.';
        }
        $r1 = self::digit($b['rek']);
        $r2 = self::digit($m->rekening);
        if ($r1 !== '' && $r2 !== '' && $r1 !== $r2) {
            $c[] = "Rekening di buku {$b['bank']} {$b['rek']}, di bank {$m->bank} {$m->rekening}.";
        }
        if ($kantong === 'uj' && $m->arah === 'keluar') {
            $feeBank = $u['fee'] ? abs($u['fee']->jumlah) : 0.0;
            if (abs($feeBank - $b['biaya']) >= 0.5) {
                $c[] = 'Biaya transfer: bank '.rp((int) $feeBank).', Kas UJ '.rp((int) $b['biaya']).'.';
            }
            preg_match_all('/\bDO\s*(?:No\.?\s*)?(\d{3,7})\b/i', (string) $m->catatan, $do);
            $lain = collect($do[1] ?? [])->map(fn ($v) => SaldoSopir::kunciDo($v))->filter()->unique()->reject(fn ($v) => in_array($v, $b['do'], true));
            if ($lain->isNotEmpty() && $b['do']) {
                $c[] = 'DO di catatan bank '.$lain->implode(', ').' tidak ada di Kas UJ (DO '.implode(', ', $b['do']).').';
            }
        }

        return $c;
    }

    private static function digit(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    /** Rekening sama atau salah ketik kecil (satu berisi yang lain / beda ≤ 2 karakter). */
    private static function rekMirip(?string $a, ?string $b): bool
    {
        [$a, $b] = [self::digit($a), self::digit($b)];
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || (strlen($a) >= 8 && strlen($b) >= 8 && (str_contains($a, $b) || str_contains($b, $a) || levenshtein($a, $b) <= 2));
    }
}
