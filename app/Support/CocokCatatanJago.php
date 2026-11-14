<?php

namespace App\Support;

use App\Models\JagoMutasi;

/**
 * Aturan J (owner 11 Nov 2026): isian Input UJ dibandingkan dengan catatan transfer Jago yang dipilih (ditulis Eddy saat transfer).
 * Kasus asal: transfer 8 Nov ke rekening Eddy "UJ 4 rit DT 043 agung" diinput sebagai Sinaga DT 069 → hutang Sinaga lebih Rp1.080.000.
 *  - DT: catatan menyebut "DT …" tetapi nomor DT baris (mis. 069 / 69) tidak ada sama sekali di catatan. Semua angka di catatan
 *    dihitung, supaya daftar "DT 34 35 36 …" (MOB) dan "DT 039 059 061" tidak dianggap beda.
 *  - Sopir: catatan menyebut nama sopir (nama Data Karyawan / nama lainnya, mis. "naga" = Sinaga) tetapi sopir baris lain orang.
 *    Dilewati bila satu transfer untuk beberapa sopir (bayar tanah gabungan: catatan hanya menyebut pembayarnya).
 *  - DO (owner 14 Nov 2026): catatan menyebut "DO …" tetapi No DO baris kosong → tombol "Pakai DO". Uang MOB dikecualikan (aturan 21:
 *    MOB tanpa DO; DO di catatan MOB = DO perjalanan, lihat DoMob). Kasus asal: UJ-16761 sparepart DT 028 10 Nov, catatan
 *    "Impek dan dongkrak DT 028 DO 17259 ban no 6", diinput tanpa DO dan keterangan "DT 026".
 * Hasilnya FLAG tinggi = wajib konfirmasi admin; bila catatan transfernya yang salah (mis. Eddy salah tulis DT), admin cukup menulis itu.
 * Uji balik 11 Nov 2026 pada 1.622 baris Kas UJ bertautan mutasi: 7 FLAG, semuanya memang berbeda dari catatan.
 */
class CocokCatatanJago
{
    /**
     * @param  array  $input  hasil bacaInput (nama, detail[...])
     * @return array<int, array<int, array{kode: string, prioritas: string, pesan: string}>> indeks detail => temuan
     */
    public static function periksa(?JagoMutasi $m, array $input): array
    {
        $catatan = trim(preg_replace('/\s+/', ' ', ($m?->catatan ?? '').' '.($m?->catatan_bank ?? '')));
        if (! $m || $catatan === '') {
            return [];
        }
        $dt = self::dtDari($catatan);
        $angka = self::angkaDari($catatan);
        $sopir = self::sopirDari($catatan);
        $doCatatan = self::doDari($catatan);
        if (! $dt && ! $sopir && ! $doCatatan) {
            return [];
        }
        $sopirBaris = collect($input['detail'])->map(fn ($d, $i) => DaftarSopir::cari(($d['nama'] ?? null) ?: ($i === 0 ? $input['nama'] : null)));
        $banyakSopir = $sopirBaris->filter()->pluck('id')->unique()->count() > 1;
        $sumber = 'Catatan transfer Jago ('.$m->waktu->format('j M H:i').' ke '.($m->nama ?: 'rekening '.$m->rekening).'): "'.mb_strimwidth($catatan, 0, 160, '…').'"';

        $hasil = [];
        foreach ($input['detail'] as $i => $d) {
            $beda = [];
            $mobil = NomorMobil::rapikan($d['no_mobil'] ?? null);
            if ($dt && $mobil && preg_match('/^DT (\d{3})$/', $mobil, $x) && ! isset($angka[(int) $x[1]])) {
                $beda[] = 'menyebut '.implode(', ', $dt).", tetapi baris ini diisi {$mobil}";
            }
            $s = $sopirBaris[$i];
            if (! $banyakSopir && $sopir && $s && ! isset($sopir[$s['id']]) && ! collect($sopir)->contains(fn ($n) => self::depan($n) === self::depan($s['nama']))) {
                $beda[] = 'menyebut sopir '.implode(', ', $sopir).", tetapi baris ini atas nama {$s['nama']}";
            }
            if ($beda) {
                $hasil[$i][] = ['kode' => 'J', 'prioritas' => 'tinggi', 'pesan' => $sumber.' '.implode('; ', $beda).'. Uang jalan ini menjadi hutang sopir yang tertulis di baris — '
                    .'pastikan nama sopir & No Mobil sesuai catatan transfer. Bila catatan transfernya yang salah, tulis konfirmasi.'];
            }
            if ($doCatatan && trim((string) ($d['no_do'] ?? '')) === '' && ! preg_match('/\bmob\b|mobilisasi|biaya transfer/i', (string) ($d['kategori'] ?? ''))) {
                $sebut = implode(', ', $doCatatan);
                $hasil[$i][] = ['kode' => 'J', 'prioritas' => 'tinggi', 'saran' => collect($doCatatan)->take(3)->map(fn ($v) => ['no_do' => $v, 'id' => 'catatan transfer'])->values()->all(),
                    'pesan' => $sumber." menyebut DO {$sebut}, tetapi No DO baris ini kosong — tanpa DO transaksi tidak tertelusur ke perjalanan sopirnya."
                        .' Klik "Pakai DO"; bila memang tidak terkait DO itu, tulis konfirmasi.'];
            }
        }

        return $hasil;
    }

    /** "DO 17259", "DO No. 15211", "do17259" → [kunci DO => nomor seperti ditulis]. */
    public static function doDari(string $t): array
    {
        preg_match_all('/\bDO\s*(?:No\.?\s*)?[:.\-]?\s*(\d{3,7})\b/i', $t, $m);
        $hasil = [];
        foreach ($m[1] as $v) {
            if (($k = SaldoSopir::kunciDo($v)) !== null) {
                $hasil[$k] ??= $v;
            }
        }

        return $hasil;
    }

    /** "DT 43", "dt069", "DT-064" → DT 043 / DT 069 / DT 064. */
    public static function dtDari(string $t): array
    {
        preg_match_all('/\bDT\s*[-.]?\s*([O0-9]{1,3})(?![0-9])/i', $t, $m);

        return collect($m[1])->map(fn ($n) => NomorMobil::rapikan('DT '.$n))->unique()->values()->all();
    }

    /** Semua angka 1–3 digit di catatan sebagai bilangan (069 = 69) — nomor DT bisa ditulis tanpa awalan "DT" di daftar. */
    public static function angkaDari(string $t): array
    {
        preg_match_all('/(?<![0-9])[O0-9]{1,3}(?![0-9])/i', $t, $m);

        return collect($m[0])->map(fn ($x) => (int) str_ireplace('O', '0', $x))->flip()->all();
    }

    /** Sopir terdaftar yang disebut di catatan (dua kata dulu, mis. "aris b", lalu satu kata ≥ 3 huruf): [id karyawan => nama baku]. */
    private static function sopirDari(string $t): array
    {
        $peta = DaftarSopir::peta();
        $kata = preg_split('/[^A-Z]+/', mb_strtoupper($t), -1, PREG_SPLIT_NO_EMPTY);
        $hasil = [];
        foreach ($kata as $i => $k) {
            foreach ([isset($kata[$i + 1]) ? $k.' '.$kata[$i + 1] : null, $k] as $c) {
                if ($c !== null && strlen($c) >= 3 && ($s = $peta[$c] ?? null) && $s['id']) {
                    $hasil[$s['id']] = $s['nama'];
                    break;
                }
            }
        }

        return $hasil;
    }

    /** Kata pertama nama ("Irpan" = "Irpan Setiawan": sopir sama yang tercatat dua kali di Data Karyawan). */
    private static function depan(string $nama): string
    {
        return explode(' ', mb_strtoupper(trim($nama)))[0];
    }
}
