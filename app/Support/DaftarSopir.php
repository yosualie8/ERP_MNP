<?php

namespace App\Support;

use App\Models\AsetTruk;
use App\Models\Karyawan;
use App\Models\KaryawanAlias;
use App\Models\KasRiwayat;
use Illuminate\Support\Facades\DB;

/**
 * Master sopir (owner 20 Okt 2026): sopir = karyawan berjabatan "Supir" + nama lainnya (karyawan_alias). Nama sopir di Kas UJ /
 * Pengajuan UJ dikenali lewat kunci (huruf besar, spasi tunggal) dan ditulis dengan nama bakunya (= nama di Data Karyawan). Nama baru pada baris
 * dump truck ditanyakan ke admin: ganti sopir (sopir lama truk itu nonaktif), sopir tambahan, nama lain sopir yang ada, atau bukan sopir.
 */
class DaftarSopir
{
    public const JABATAN = 'Supir';

    /** Pilihan admin untuk nama sopir baru (isian detail.*.sopir_baru): ganti | tambah | alias:{id karyawan} | bukan. */
    public const PILIHAN = ['ganti', 'tambah', 'bukan'];

    private static ?array $peta = null;

    public static function kunci(?string $nama): ?string
    {
        $v = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $nama)));

        return $v === '' ? null : $v;
    }

    public static function lupakan(): void
    {
        self::$peta = null;
    }

    /** Penulisan nama sopir baru: huruf besar di awal kata ("ARIS  b" → "Aris B"), sama dengan kebiasaan Kas UJ. */
    public static function namaBaru(?string $nama): string
    {
        return mb_convert_case(mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $nama))), MB_CASE_TITLE);
    }

    /** kunci nama → ['id', 'nama' (baku = nama di Data Karyawan), 'aktif', 'mobil'] ; nama yang dijawab "bukan sopir" → ['id' => null, 'bukan' => true]. */
    public static function peta(): array
    {
        if (self::$peta !== null) {
            return self::$peta;
        }
        $peta = [];
        $sopir = Karyawan::where('jabatan', self::JABATAN)->get(['id', 'nama', 'status', 'no_mobil'])->keyBy('id');
        $isi = fn (Karyawan $k) => ['id' => $k->id, 'nama' => $k->nama, 'aktif' => $k->status === 'aktif', 'mobil' => $k->no_mobil];
        foreach (KaryawanAlias::get(['karyawan_id', 'kunci']) as $a) {
            if ($a->karyawan_id === null) {
                $peta[$a->kunci] = ['id' => null, 'bukan' => true];
            } elseif ($k = $sopir[$a->karyawan_id] ?? null) {
                $peta[$a->kunci] = $isi($k);
            }
        }
        // nama karyawan sendiri menang atas alias; yang aktif menang atas yang nonaktif
        foreach ($sopir->sortBy(fn ($k) => $k->status === 'aktif' ? 1 : 0) as $k) {
            $peta[self::kunci($k->nama)] = $isi($k);
        }

        return self::$peta = $peta;
    }

    /** Sopir terdaftar untuk nama ini (null = belum terdaftar / bukan sopir). */
    public static function cari(?string $nama): ?array
    {
        $s = self::peta()[self::kunci($nama) ?? ''] ?? null;

        return $s && $s['id'] ? $s : null;
    }

    /** Nama baku (nama di Data Karyawan) untuk ditulis di Kas UJ; nama yang belum terdaftar dikembalikan apa adanya. */
    public static function baku(?string $nama): ?string
    {
        return self::cari($nama)['nama'] ?? $nama;
    }

    /** Baris yang perlu ditanyakan: nama belum dikenal pada baris dump truck terdaftar. @return array<string, array{nama: string, mobil: string, baris: int[]}> per kunci */
    public static function baru(array $detail): array
    {
        $truk = AsetTruk::truk()->pluck('no_lambung')->flip();
        $hasil = [];
        foreach ($detail as $i => $d) {
            $k = self::kunci($d['nama'] ?? null);
            $m = NomorMobil::rapikan($d['no_mobil'] ?? null);
            if (! $k || ! $m || ! isset($truk[$m]) || isset(self::peta()[$k])) {
                continue;
            }
            $hasil[$k] ??= ['nama' => trim(preg_replace('/\s+/', ' ', $d['nama'])), 'mobil' => $m, 'baris' => [], 'pilihan' => null];
            $hasil[$k]['baris'][] = $i + 1;
            $hasil[$k]['pilihan'] ??= ($d['sopir_baru'] ?? null) ?: null;
        }

        return $hasil;
    }

    /**
     * Sopir TERDAFTAR yang muncul di truk lain dari catatannya, atau yang berstatus nonaktif (owner 20 Okt 2026: sopir lama bisa pindah
     * truk / kembali bekerja). Ditanyakan sekali per sopir (truk baris pertamanya). Jawaban (detail.*.sopir_pindah):
     *  - sementara          : sekali jalan / pinjam truk — catatan tidak berubah;
     *  - pindah[|id,id]     : (sopir aktif) pindah tetap ke truk ini; sopir lain truk ini yang dicentang berhenti (nonaktif);
     *  - aktif[|id,id]      : (sopir nonaktif) aktif lagi membawa truk ini; idem.
     *
     * @return array<int, array{id: int, nama: string, aktif: bool, dari: ?string, ke: string, baris: int[], pilihan: ?string}> per id karyawan
     */
    public static function pindah(array $detail): array
    {
        $truk = AsetTruk::truk()->pluck('no_lambung')->flip();
        $hasil = [];
        foreach ($detail as $i => $d) {
            $s = self::cari($d['nama'] ?? null);
            $m = NomorMobil::rapikan($d['no_mobil'] ?? null);
            if (! $s || ! $m || ! isset($truk[$m])) {
                continue;
            }
            if (isset($hasil[$s['id']])) {
                $hasil[$s['id']]['baris'][] = $i + 1;

                continue;
            }
            if ($s['aktif'] && $s['mobil'] === $m) {
                $hasil[$s['id']] = null; // sudah sesuai (baris lain sopir ini di truk lain = sekali jalan)

                continue;
            }
            $hasil[$s['id']] = ['id' => $s['id'], 'nama' => $s['nama'], 'aktif' => $s['aktif'], 'dari' => $s['mobil'], 'ke' => $m, 'baris' => [$i + 1],
                'pilihan' => ($d['sopir_pindah'] ?? null) ?: null];
        }

        return array_filter($hasil);
    }

    /** Pesan galat untuk nama sopir baru yang belum dijawab / jawabannya tidak sah. */
    public static function belumDijawab(array $detail): array
    {
        $pesan = [];
        foreach (self::pindah($detail) as $p) {
            $pola = $p['aktif'] ? '/^(sementara|pindah(\|[\d,]+)?)$/' : '/^(sementara|aktif(\|[\d,]+)?)$/';
            if (! preg_match($pola, (string) $p['pilihan'])) {
                $pesan[] = 'Baris '.$p['baris'][0].': '.($p['aktif']
                    ? "{$p['nama']} tercatat membawa ".($p['dari'] ?: 'truk lain')." — pilih: sekali jalan / pindah tetap ke {$p['ke']}."
                    : "{$p['nama']} tercatat nonaktif — pilih: aktifkan lagi membawa {$p['ke']} / sekali jalan.");
            }
        }
        foreach (self::baru($detail) as $b) {
            $p = $b['pilihan'];
            $sah = in_array($p, self::PILIHAN, true) || (preg_match('/^alias:(\d+)$/', (string) $p, $m) && Karyawan::where('jabatan', self::JABATAN)->whereKey($m[1])->exists());
            if (! $sah) {
                $pesan[] = 'Baris '.implode(', ', $b['baris']).": \"{$b['nama']}\" belum terdaftar sebagai sopir {$b['mobil']} — pilih: ganti sopir / sopir tambahan / nama lain sopir yang ada / bukan sopir.";
            }
        }

        return $pesan;
    }

    /** Nama di detail ditulis dengan nama baku sopir (termasuk jawaban "nama lain dari"). */
    public static function rapikanNama(array $detail): array
    {
        $baru = self::baru($detail);
        foreach ($detail as $i => $d) {
            $k = self::kunci($d['nama'] ?? null);
            if (! $k) {
                continue;
            }
            if (isset($baru[$k]) && preg_match('/^alias:(\d+)$/', (string) $baru[$k]['pilihan'], $m)) {
                $detail[$i]['nama'] = Karyawan::find($m[1])?->nama ?? $d['nama'];
            } elseif (isset($baru[$k]) && in_array($baru[$k]['pilihan'], ['ganti', 'tambah'], true)) {
                $detail[$i]['nama'] = self::namaBaru($d['nama']);
            } elseif ($s = self::cari($k)) {
                $detail[$i]['nama'] = $s['nama'];
            }
        }

        return $detail;
    }

    /**
     * Terapkan jawaban admin SETELAH transaksi tersimpan (detail = isian sebelum rapikanNama). Mengembalikan ringkasan untuk pesan sukses.
     *  - ganti  : sopir aktif lain yang membawa truk itu → nonaktif; nama baru → karyawan Supir aktif membawa truk itu;
     *  - tambah : nama baru → karyawan Supir aktif membawa truk itu (sopir lama tetap aktif);
     *  - alias  : nama itu dicatat sebagai nama lain sopir terpilih;
     *  - bukan  : dicatat bukan sopir (tidak ditanyakan lagi).
     */
    public static function terapkan(array $detail, ?int $userId): array
    {
        $ringkas = [];
        DB::transaction(function () use ($detail, $userId, &$ringkas) {
            // 1. Sopir lama pindah truk / aktif lagi — DULU, supaya "ganti sopir" di truk lamanya tidak ikut menonaktifkan dia.
            foreach (self::pindah($detail) as $p) {
                if (! preg_match('/^(pindah|aktif)(?:\|([\d,]+))?$/', (string) $p['pilihan'], $m) || ! ($s = Karyawan::find($p['id']))) {
                    continue;
                }
                $s->update(['status' => 'aktif', 'no_mobil' => $p['ke']]);
                $berhenti = Karyawan::where('jabatan', self::JABATAN)->where('status', 'aktif')->where('no_mobil', $p['ke'])
                    ->whereIn('id', array_filter(explode(',', $m[2] ?? '')))->whereKeyNot($s->id)->get();
                foreach ($berhenti as $b) {
                    $b->update(['status' => 'nonaktif']);
                }
                $ringkas[] = ($m[1] === 'aktif' ? "{$s->nama} aktif lagi membawa {$p['ke']}" : "{$s->nama} pindah ".($p['dari'] ?: '').' → '.$p['ke'])
                    .($berhenti->isNotEmpty() ? ', '.$berhenti->pluck('nama')->implode(', ').' dinonaktifkan' : '');
            }
            // 2. Nama sopir baru
            foreach (self::baru($detail) as $k => $b) {
                $p = (string) $b['pilihan'];
                if (preg_match('/^alias:(\d+)$/', $p, $m) && ($s = Karyawan::find($m[1]))) {
                    KaryawanAlias::firstOrCreate(['kunci' => $k], ['karyawan_id' => $s->id, 'nama' => $b['nama'], 'user_id' => $userId]);
                    $ringkas[] = "\"{$b['nama']}\" dicatat sebagai nama lain sopir {$s->nama}";
                } elseif ($p === 'bukan') {
                    KaryawanAlias::firstOrCreate(['kunci' => $k], ['karyawan_id' => null, 'nama' => $b['nama'], 'user_id' => $userId]);
                    $ringkas[] = "\"{$b['nama']}\" dicatat bukan sopir";
                } elseif (in_array($p, ['ganti', 'tambah'], true)) {
                    $lama = $p === 'ganti' ? Karyawan::where('jabatan', self::JABATAN)->where('status', 'aktif')->where('no_mobil', $b['mobil'])->get() : collect();
                    foreach ($lama as $l) {
                        $l->update(['status' => 'nonaktif']);
                    }
                    $s = Karyawan::create(['nama' => self::namaBaru($b['nama']), 'jabatan' => self::JABATAN, 'status' => 'aktif', 'no_mobil' => $b['mobil']]);
                    KaryawanAlias::firstOrCreate(['kunci' => $k], ['karyawan_id' => $s->id, 'nama' => $b['nama'], 'user_id' => $userId]);
                    $ringkas[] = "Sopir baru {$s->nama} ({$b['mobil']}) ditambahkan ke Data Karyawan".($lama->isNotEmpty() ? ', '.$lama->pluck('nama')->implode(', ').' dinonaktifkan (ganti sopir)' : '');
                }
            }
            if ($ringkas) {
                KasRiwayat::create(['aksi' => 'sopir-baru', 'lembar' => 'Karyawan', 'baris_awal' => 0, 'baris_akhir' => 0,
                    'ringkasan' => mb_strimwidth(implode('; ', $ringkas), 0, 490, '…'), 'isi' => ['baru' => self::baru($detail), 'pindah' => array_values(self::pindah($detail))], 'user_id' => $userId]);
            }
        });
        self::lupakan();
        SaldoSopir::lupakan();

        return $ringkas;
    }

    /** Data untuk form Input UJ / Pengajuan UJ. */
    public static function untukForm(): array
    {
        $aktif = Karyawan::where('jabatan', self::JABATAN)->where('status', 'aktif')->orderBy('nama')->get(['id', 'nama', 'no_mobil']);

        return [
            'peta' => collect(self::peta())->map(fn ($s) => $s['id'] ? ['id' => $s['id'], 'nama' => $s['nama'], 'aktif' => $s['aktif'], 'mobil' => $s['mobil']] : ['bukan' => true])->all(),
            'aktif' => $aktif->map(fn ($k) => ['id' => $k->id, 'nama' => $k->nama, 'mobil' => $k->no_mobil])->values()->all(),
            'truk' => AsetTruk::truk()->pluck('no_lambung')->values()->all(),
        ];
    }
}
