<?php

namespace App\Support;

use App\Models\Karyawan;
use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\Rekening;
use App\Models\UjTransaksi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Database rekening bersama Kas Harian & Kas UJ (owner 22 Okt 2026): satu nomor rekening = satu nama pemilik.
 * - Kas Harian (transfer Bank Jago) menulis nama pemilik rekening → sumber utama; tulisan beda tipis (salah ketik, singkatan,
 *   nama e-wallet yang disamarkan) tetap orang yang sama.
 * - Kas UJ TIDAK punya kolom penerima: kolom F baris pertama = nama sopir, jadi nama di Kas UJ hanya "dipakai untuk sopir".
 * - Data Karyawan: kolom Nama Rekening = nama pemilik yang paling bisa dipercaya.
 */
class DaftarRekening
{
    public static function baku(?string $n): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} ]/u', ' ', (string) $n))));
    }

    /** Nama e-wallet yang disamarkan bank, mis. "DNID Yosxx Dxx Perxxxx". */
    public static function samar(?string $n): bool
    {
        return (bool) preg_match('/xx/i', (string) $n);
    }

    /** Dua tulisan nama dianggap orang yang sama: sama, singkatan, beda tipis (salah ketik), atau versi tersamar e-wallet. */
    public static function mirip(?string $a, ?string $b): bool
    {
        $inti = fn ($n) => trim(preg_replace('/^(ovo|dnid|dind|dana|gopay|shopeepay|linkaja|spay)\s+/', '', self::baku($n)));
        [$x, $y] = [$inti($a), $inti($b)];
        if ($x === '' || $y === '') {
            return false;
        }
        if ($x === $y || str_starts_with($x, $y) || str_starts_with($y, $x)) {
            return true;
        }
        if (self::samar($x) || self::samar($y)) {
            [$m, $o] = self::samar($x) ? [$x, $y] : [$y, $x];
            $pm = explode(' ', $m);
            $po = explode(' ', $o);
            foreach ($pm as $i => $w) {
                if (! isset($po[$i]) || ! preg_match('/^'.str_replace('x', '.', preg_quote(mb_substr($w, 0, 2), '/')).'/u', $po[$i])) {
                    return false;
                }
            }

            return true;
        }
        if (levenshtein($x, $y) <= 2) {
            return true;
        }
        similar_text($x, $y, $persen);

        return $persen >= 85;
    }

    /** Tulisan nama terbaik dari [tulisan => jumlah]: yang paling sering, utamakan yang berhuruf & tidak disamarkan. */
    public static function pilihNama(array $tulisan): ?string
    {
        $calon = array_filter($tulisan, fn ($n, $t) => preg_match('/\p{L}/u', $t) && ! self::samar($t), ARRAY_FILTER_USE_BOTH)
            ?: array_filter($tulisan, fn ($n, $t) => preg_match('/\p{L}/u', $t), ARRAY_FILTER_USE_BOTH);
        if (! $calon) {
            return null;
        }
        uksort($calon, fn ($a, $b) => [$calon[$b], mb_strlen($b)] <=> [$calon[$a], mb_strlen($a)]);

        return (string) array_key_first($calon);
    }

    /**
     * Pemakaian setiap nomor rekening di Kas Harian & Kas UJ.
     *
     * @return array{kas: array<string, array>, uj: array<string, array>}
     */
    public static function statistik(): array
    {
        $kumpul = function ($baris) {
            $per = [];
            foreach ($baris as $r) {
                $k = Rekening::kunci($r->rek);
                $nama = trim(preg_replace('/\s+/', ' ', (string) $r->nama));
                if (strlen($k) < 5) {
                    continue;
                }
                $e = $per[$k] ?? ['n' => 0, 'akhir' => null, 'nama' => [], 'bank' => [], 'no' => []];
                $e['n'] += $r->n;
                $e['akhir'] = max((string) $e['akhir'], (string) $r->akhir) ?: null;
                if ($nama !== '') {
                    $e['nama'][$nama] = ($e['nama'][$nama] ?? 0) + $r->n;
                }
                if ($b = DaftarBank::rapikan($r->bank)) {
                    $e['bank'][$b] = ($e['bank'][$b] ?? 0) + $r->n;
                }
                $no = preg_replace('/\D/', '', (string) $r->rek);
                $e['no'][$no] = ($e['no'][$no] ?? 0) + $r->n;
                $per[$k] = $e;
            }

            return $per;
        };

        return [
            'kas' => $kumpul(KasTransfer::whereNotNull('no_rek_tujuan')->where('no_rek_tujuan', '!=', '')
                ->select('nama_tujuan as nama', 'no_rek_tujuan as rek', 'bank_tujuan as bank', DB::raw('COUNT(*) as n'), DB::raw('MAX(tanggal) as akhir'))
                ->groupBy('nama_tujuan', 'no_rek_tujuan', 'bank_tujuan')->get()),
            'uj' => $kumpul(UjTransaksi::whereNotNull('rekening')->where('rekening', '!=', '')
                ->select('nama', 'rekening as rek', 'bank', DB::raw('COUNT(*) as n'), DB::raw('MAX(tanggal) as akhir'))
                ->groupBy('nama', 'rekening', 'bank')->get()),
        ];
    }

    /** @return array<string, array<int, Karyawan>> kunci rekening → karyawan (yang aktif dulu) */
    public static function karyawan(): array
    {
        $per = [];
        foreach (Karyawan::whereNotNull('no_rekening')->where('no_rekening', '!=', '')->orderByRaw("status = 'aktif' DESC")->get() as $k) {
            if (strlen($kunci = Rekening::kunci($k->no_rekening)) >= 5) {
                $per[$kunci][] = $k;
            }
        }

        return $per;
    }

    /**
     * Usulan pemilik satu rekening: Nama Rekening di Data Karyawan → nama terbanyak di Kas Harian → nama karyawan pemilik →
     * satu-satunya nama di Kas UJ (sopir yang memakai rekeningnya sendiri; perlu dicek) → kosong (rekening dipakai banyak sopir).
     *
     * @return array{nama: ?string, sumber: string, bank: ?string, no: string, catatan: ?string}
     */
    public static function usulan(string $kunci, ?array $kas, ?array $uj, array $kar): array
    {
        $catatan = [];
        $namaKas = $kas ? self::pilihNama($kas['nama']) : null;
        $karRek = collect($kar)->first(fn ($k) => trim((string) $k->nama_rekening) !== '');
        if ($karRek) {
            [$nama, $sumber] = [trim($karRek->nama_rekening), 'karyawan'];
            if ($namaKas && ! self::mirip($namaKas, $nama)) {
                $catatan[] = "Kas Harian memakai nama {$namaKas}";
            }
        } elseif ($namaKas) {
            [$nama, $sumber] = [$namaKas, 'kas_harian'];
        } elseif ($kar) {
            [$nama, $sumber] = [trim($kar[0]->nama), 'karyawan'];
        } else {
            $sopir = collect(array_keys($uj['nama'] ?? []))->unique(fn ($n) => self::baku($n));
            [$nama, $sumber] = $sopir->count() === 1 ? [self::pilihNama($uj['nama']), 'kas_uj'] : [null, 'kas_uj'];
            if ($sopir->count() > 1) {
                $catatan[] = 'dipakai untuk '.$sopir->count().' sopir di Kas UJ — pemilik belum diketahui';
            }
        }
        $lain = $kas ? collect(array_keys($kas['nama']))->filter(fn ($t) => $t !== $nama)->count() : 0;
        if ($lain) {
            $catatan[] = "{$lain} tulisan lain di Kas Harian";
        }
        $bank = collect(($kas['bank'] ?? []) ?: ($uj['bank'] ?? []))->sortDesc()->keys()->first() ?? DaftarBank::rapikan($kar[0]->bank ?? null);
        $nomor = collect(($kas['no'] ?? []) + ($uj['no'] ?? []))->sortByDesc(fn ($n, $no) => [$n, strlen($no)])->keys()->first()
            ?? preg_replace('/\D/', '', (string) ($kar[0]->no_rekening ?? $kunci));

        return ['nama' => $nama, 'sumber' => $sumber, 'bank' => $bank, 'no' => (string) $nomor, 'catatan' => $catatan ? implode('; ', $catatan) : null];
    }

    /**
     * Isi database rekening dari Kas Harian, Kas UJ & Data Karyawan. Rekening yang sudah ada tidak diubah, kecuali $perbarui
     * (hanya yang terisi otomatis — yang diisi admin tidak pernah ditimpa).
     *
     * @return array{baru: int, diperbarui: int, total: int}
     */
    public static function isi(bool $perbarui = false, ?int $userId = null): array
    {
        $st = self::statistik();
        $kar = self::karyawan();
        $ada = Rekening::get()->keyBy('kunci');
        [$baru, $ubah] = [0, 0];
        DB::transaction(function () use ($st, $kar, $ada, $perbarui, $userId, &$baru, &$ubah) {
            foreach (array_unique([...array_keys($st['kas']), ...array_keys($st['uj']), ...array_keys($kar)]) as $k) {
                $k = (string) $k;
                $u = self::usulan($k, $st['kas'][$k] ?? null, $st['uj'][$k] ?? null, $kar[$k] ?? []);
                $isi = ['no_rekening' => $u['no'], 'bank' => $u['bank'], 'nama' => $u['nama'], 'sumber' => $u['sumber'], 'catatan' => $u['catatan']];
                if (! ($r = $ada[$k] ?? null)) {
                    Rekening::create(['kunci' => $k, 'user_id' => $userId] + $isi);
                    $baru++;
                } elseif ($perbarui && in_array($r->sumber, ['kas_harian', 'karyawan', 'kas_uj'], true)) {
                    $r->fill($isi);
                    if ($r->isDirty()) {
                        $r->save();
                        $ubah++;
                    }
                }
            }
        });

        return ['baru' => $baru, 'diperbarui' => $ubah, 'total' => Rekening::count()];
    }

    /**
     * Rekening baru dari Input Kas / Input UJ dicatat ke database. Nama hanya diambil bila bisa dipercaya sebagai pemilik
     * ($namaPemilik: Kas Harian ya; Kas UJ tidak, karena nama penerima di Kas UJ sering nama sopir).
     */
    public static function catat(?string $noRek, ?string $bank, ?string $nama, ?int $userId, bool $namaPemilik): void
    {
        $k = Rekening::kunci($noRek);
        $nama = trim(preg_replace('/\s+/', ' ', (string) $nama));
        if (strlen($k) < 5) {
            return;
        }
        $r = Rekening::where('kunci', $k)->first();
        $namaOk = $nama !== '' && preg_match('/\p{L}/u', $nama) && ! self::samar($nama);
        if (! $r) {
            // Rekening baru dari Input UJ: nama penerima yang diketik admin disimpan sebagai "Kas UJ (perlu dicek)".
            Rekening::create(['kunci' => $k, 'no_rekening' => preg_replace('/\D/', '', (string) $noRek), 'bank' => DaftarBank::rapikan($bank),
                'nama' => $namaOk ? $nama : null, 'sumber' => $namaPemilik ? 'kas_harian' : 'kas_uj', 'user_id' => $userId]);
        } elseif (! $r->nama && $namaOk && $namaPemilik) {
            $r->update(['nama' => $nama, 'sumber' => 'kas_harian']);
        }
    }

    /**
     * Saran rekening untuk Input Kas & Input UJ: satu baris per nomor rekening dengan nama pemiliknya. 'alias' = tulisan lain
     * di Kas Harian & nama sopir yang memakai rekening itu di Kas UJ (ikut dicari, mis. ketik "Bambang" → rekening Jumagar).
     *
     * @return array<int, array{nama: string, bank: ?string, no_rek: string, kunci: string, dipakai: int, terakhir: string, alias: array, belum: bool}>
     */
    public static function saran(): array
    {
        $st = self::statistik();

        return Rekening::get()->map(function ($r) use ($st) {
            $kas = $st['kas'][$r->kunci] ?? null;
            $uj = $st['uj'][$r->kunci] ?? null;
            $nama = $r->nama ?? ($uj ? self::pilihNama($uj['nama']) : null) ?? '';
            $alias = collect(array_keys([...($kas['nama'] ?? []), ...($uj['nama'] ?? [])]))
                ->reject(fn ($n) => self::baku($n) === self::baku($nama) || self::samar($n))->unique(fn ($n) => self::baku($n))->take(15)->values()->all();
            $akhir = max((string) ($kas['akhir'] ?? ''), (string) ($uj['akhir'] ?? '')) ?: null;

            // sopir = nama di Kas UJ (kolom Nama = sopir): Input UJ mengisi baris pertama dengan sopir, bukan pemilik rekening.
            $sopir = collect(array_keys($uj['nama'] ?? []))->reject(fn ($n) => self::samar($n))->unique(fn ($n) => self::baku($n))->values()->all();

            return ['nama' => $nama, 'bank' => $r->bank, 'no_rek' => $r->no_rekening, 'kunci' => $r->kunci,
                'dipakai' => (int) (($kas['n'] ?? 0) + ($uj['n'] ?? 0)), 'terakhir' => $akhir ? Carbon::parse($akhir)->translatedFormat('j M Y') : '-',
                'alias' => $alias, 'sopir' => $sopir, 'belum' => ! $r->nama, 'urut' => (string) $akhir];
        })->sortByDesc('urut')->map(fn ($r) => collect($r)->except('urut')->all())->values()->all();
    }

    /** Pemilik per kunci rekening (untuk tampilan Kas UJ). @return array<string, string> */
    public static function pemilik(iterable $nomor): array
    {
        $kunci = collect($nomor)->map(fn ($n) => Rekening::kunci($n))->filter(fn ($k) => strlen($k) >= 5)->unique()->values();

        return $kunci->isEmpty() ? [] : Rekening::whereIn('kunci', $kunci)->whereNotNull('nama')->pluck('nama', 'kunci')->all();
    }

    public static function log(string $ringkasan, array $isi, ?int $userId): void
    {
        KasRiwayat::create(['aksi' => 'rekening', 'lembar' => 'Rekening', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $userId,
            'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'), 'isi' => $isi]);
    }
}
