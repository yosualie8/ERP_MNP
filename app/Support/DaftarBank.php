<?php

namespace App\Support;

/**
 * Daftar bank & e-wallet di Indonesia dengan satu nama baku (kode) per lembaga, supaya kolom Bank rapi untuk filter/rekap.
 * Kode = nama singkat yang biasa dipakai di sheet (BCA, Mandiri, Seabank, GoPay, …); nama = nama lengkap untuk saran;
 * alias = singkatan/ejaan lain/salah ketik yang dikenali (mis. "Mandri", "Bank Central Asia", "Gopay").
 */
class DaftarBank
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string[]}> kode, nama lengkap, jenis, alias */
    private const DAFTAR = [
        // Bank BUMN
        ['BRI', 'Bank Rakyat Indonesia', 'bank', ['bank bri', 'rakyat', 'brimo']],
        ['Mandiri', 'Bank Mandiri', 'bank', ['mandri', 'madiri', 'livin', 'bank mandiri']],
        ['BNI', 'Bank Negara Indonesia', 'bank', ['bank bni', 'negara', 'wondr']],
        ['BTN', 'Bank Tabungan Negara', 'bank', ['tabungan negara']],
        ['BSI', 'Bank Syariah Indonesia', 'bank', ['syariah indonesia', 'bsm', 'bni syariah', 'bri syariah', 'mandiri syariah']],
        // Bank swasta nasional
        ['BCA', 'Bank Central Asia', 'bank', ['bc', 'central asia', 'klikbca', 'mybca']],
        ['BCA Syariah', 'Bank BCA Syariah', 'bank', ['bcas']],
        ['CIMB Niaga', 'Bank CIMB Niaga', 'bank', ['cimb', 'niaga', 'octo', 'cimb niaga syariah']],
        ['Danamon', 'Bank Danamon Indonesia', 'bank', ['danamon syariah']],
        ['Permata', 'Bank Permata', 'bank', ['permatabank', 'permata syariah']],
        ['Panin', 'Panin Bank', 'bank', ['bank panin', 'pan indonesia']],
        ['Panin Dubai Syariah', 'Bank Panin Dubai Syariah', 'bank', ['panin syariah']],
        ['OCBC', 'Bank OCBC Indonesia', 'bank', ['ocbc nisp', 'nisp', 'commonwealth']],
        ['Maybank', 'Maybank Indonesia', 'bank', ['bii', 'maybank syariah']],
        ['UOB', 'Bank UOB Indonesia', 'bank', ['tmrw']],
        ['SMBC Indonesia', 'Bank SMBC Indonesia (d/h BTPN)', 'bank', ['btpn', 'jenius', 'smbc']],
        ['BTPN Syariah', 'Bank BTPN Syariah', 'bank', []],
        ['Mega', 'Bank Mega', 'bank', ['bank mega']],
        ['Mega Syariah', 'Bank Mega Syariah', 'bank', []],
        ['Sinarmas', 'Bank Sinarmas', 'bank', ['bank sinarmas', 'simobi']],
        ['KB Bank', 'Bank KB Indonesia (d/h KB Bukopin)', 'bank', ['bukopin', 'kb bukopin']],
        ['KB Bank Syariah', 'Bank KB Bank Syariah', 'bank', ['bukopin syariah']],
        ['Muamalat', 'Bank Muamalat Indonesia', 'bank', []],
        ['MNC Bank', 'Bank MNC Internasional', 'bank', ['mnc', 'motionbank']],
        ['Artha Graha', 'Bank Artha Graha Internasional', 'bank', []],
        ['Mayapada', 'Bank Mayapada Internasional', 'bank', []],
        ['Maspion', 'Bank Maspion Indonesia', 'bank', []],
        ['Mestika', 'Bank Mestika Dharma', 'bank', []],
        ['Index', 'Bank Index Selindo', 'bank', ['index selindo']],
        ['Victoria', 'Bank Victoria International', 'bank', []],
        ['Capital', 'Bank Capital Indonesia', 'bank', []],
        ['Ina Perdana', 'Bank Ina Perdana', 'bank', ['bank ina']],
        ['Sahabat Sampoerna', 'Bank Sahabat Sampoerna', 'bank', ['sampoerna']],
        ['JTrust', 'Bank JTrust Indonesia', 'bank', ['j trust']],
        ['Shinhan', 'Bank Shinhan Indonesia', 'bank', []],
        ['Woori Saudara', 'Bank Woori Saudara Indonesia', 'bank', ['woori', 'saudara']],
        ['IBK', 'Bank IBK Indonesia', 'bank', []],
        ['QNB', 'Bank QNB Indonesia', 'bank', []],
        ['Multiarta Sentosa', 'Bank Multiarta Sentosa', 'bank', ['bank mas']],
        ['Mandiri Taspen', 'Bank Mandiri Taspen (Bank Mantap)', 'bank', ['mantap', 'taspen']],
        ['Nobu', 'Nobu Bank (Bank Nationalnobu)', 'bank', ['nationalnobu']],
        ['Oke Bank', 'Bank Oke Indonesia', 'bank', ['oke']],
        ['Ganesha', 'Bank Ganesha', 'bank', []],
        ['Resona Perdania', 'Bank Resona Perdania', 'bank', []],
        ['Mizuho', 'Bank Mizuho Indonesia', 'bank', []],
        ['ICBC', 'Bank ICBC Indonesia', 'bank', []],
        ['Bank of China', 'Bank of China (Hong Kong) Indonesia', 'bank', ['boc']],
        ['CCB Indonesia', 'Bank China Construction Bank Indonesia', 'bank', ['ccb']],
        ['HSBC', 'Bank HSBC Indonesia', 'bank', []],
        ['Citibank', 'Citibank Indonesia', 'bank', ['citi']],
        ['Standard Chartered', 'Standard Chartered Bank Indonesia', 'bank', ['stanchart', 'scb']],
        ['DBS', 'Bank DBS Indonesia', 'bank', ['digibank']],
        ['BNP Paribas', 'Bank BNP Paribas Indonesia', 'bank', []],
        ['Hana Bank', 'Bank KEB Hana Indonesia', 'bank', ['keb hana', 'hana', 'line bank']],
        // Bank digital
        ['Jago', 'Bank Jago', 'bank', ['bank jago', 'jago syariah']],
        ['Seabank', 'SeaBank Indonesia', 'bank', ['sea bank', 'bank seabank', 'bke']],
        ['blu', 'blu by BCA Digital', 'bank', ['bca digital', 'blu bca']],
        ['Neo Commerce', 'Bank Neo Commerce', 'bank', ['bnc', 'neobank', 'neo bank']],
        ['Allo Bank', 'Allo Bank Indonesia', 'bank', ['allo']],
        ['Superbank', 'Superbank (Bank Fama Internasional)', 'bank', ['super bank', 'fama']],
        ['Krom', 'Krom Bank Indonesia', 'bank', []],
        ['Hibank', 'Bank Hibank Indonesia', 'bank', ['hi bank']],
        ['Bank Raya', 'Bank Raya Indonesia', 'bank', ['raya']],
        ['Aladin', 'Bank Aladin Syariah', 'bank', []],
        ['Amar Bank', 'Bank Amar Indonesia', 'bank', ['amar', 'tunaiku']],
        ['Saqu', 'Bank Saqu (Bank Jasa Jakarta)', 'bank', ['jasa jakarta']],
        // Bank Pembangunan Daerah
        ['Bank DKI', 'Bank DKI', 'bank', ['dki', 'jakone']],
        ['BJB', 'Bank BJB (Jawa Barat dan Banten)', 'bank', ['jawa barat', 'bank jabar']],
        ['BJB Syariah', 'Bank BJB Syariah', 'bank', []],
        ['Bank Banten', 'Bank Banten', 'bank', ['banten']],
        ['Bank Jateng', 'Bank Jateng (Jawa Tengah)', 'bank', ['jateng', 'jawa tengah']],
        ['Bank Jatim', 'Bank Jatim (Jawa Timur)', 'bank', ['jatim', 'jawa timur']],
        ['BPD DIY', 'Bank BPD DIY (Yogyakarta)', 'bank', ['bpd diy', 'yogyakarta', 'jogja']],
        ['Bank Aceh', 'Bank Aceh Syariah', 'bank', ['aceh']],
        ['Bank Sumut', 'Bank Sumut (Sumatera Utara)', 'bank', ['sumut', 'sumatera utara']],
        ['Bank Nagari', 'Bank Nagari (Sumatera Barat)', 'bank', ['nagari', 'sumbar']],
        ['BRK Syariah', 'Bank Riau Kepri Syariah', 'bank', ['riau', 'bank riau kepri', 'brk']],
        ['Bank Jambi', 'Bank Jambi', 'bank', ['jambi']],
        ['Bank Sumsel Babel', 'Bank Sumsel Babel', 'bank', ['sumsel', 'babel']],
        ['Bank Bengkulu', 'Bank Bengkulu', 'bank', ['bengkulu']],
        ['Bank Lampung', 'Bank Lampung', 'bank', ['lampung']],
        ['Bank Kalbar', 'Bank Kalbar (Kalimantan Barat)', 'bank', ['kalbar', 'kalimantan barat']],
        ['Bank Kalteng', 'Bank Kalteng (Kalimantan Tengah)', 'bank', ['kalteng', 'kalimantan tengah']],
        ['Bank Kalsel', 'Bank Kalsel (Kalimantan Selatan)', 'bank', ['kalsel', 'kalimantan selatan']],
        ['Bankaltimtara', 'Bank Kaltimtara (Kalimantan Timur & Utara)', 'bank', ['kaltim', 'kaltimtara', 'bank kaltim']],
        ['Bank Sulselbar', 'Bank Sulselbar (Sulawesi Selatan & Barat)', 'bank', ['sulselbar', 'sulsel']],
        ['Bank SulutGo', 'Bank SulutGo (Sulawesi Utara & Gorontalo)', 'bank', ['sulut', 'sulutgo']],
        ['Bank Sulteng', 'Bank Sulteng (Sulawesi Tengah)', 'bank', ['sulteng']],
        ['Bank Sultra', 'Bank Sultra (Sulawesi Tenggara)', 'bank', ['sultra']],
        ['Bank Bali', 'Bank BPD Bali', 'bank', ['bpd bali', 'bali']],
        ['Bank NTB Syariah', 'Bank NTB Syariah', 'bank', ['ntb']],
        ['Bank NTT', 'Bank NTT', 'bank', ['ntt']],
        ['Bank Maluku Malut', 'Bank Maluku Malut', 'bank', ['maluku']],
        ['Bank Papua', 'Bank Papua', 'bank', ['papua']],
        // E-wallet
        ['GoPay', 'GoPay', 'e-wallet', ['gojek', 'go pay', 'gopy']],
        ['OVO', 'OVO', 'e-wallet', []],
        ['DANA', 'DANA', 'e-wallet', []],
        ['ShopeePay', 'ShopeePay', 'e-wallet', ['shopee', 'shopee pay', 'spay']],
        ['LinkAja', 'LinkAja', 'e-wallet', ['link aja']],
        ['i.saku', 'i.saku (Indomaret)', 'e-wallet', ['isaku', 'i saku']],
        ['Sakuku', 'Sakuku (BCA)', 'e-wallet', []],
        ['AstraPay', 'AstraPay', 'e-wallet', ['astra pay']],
        ['DOKU', 'DOKU Wallet', 'e-wallet', []],
        ['Flip', 'Flip', 'e-wallet', []],
    ];

    /** Untuk saran di form: [kode, nama lengkap, jenis, kunci-kunci pencarian]. */
    public static function untukForm(): array
    {
        return array_map(fn ($b) => ['kode' => $b[0], 'nama' => $b[1], 'jenis' => $b[2],
            'kunci' => array_values(array_unique(array_map([self::class, 'kunci'], [$b[0], $b[1], ...$b[3]])))], self::DAFTAR);
    }

    /** Kode baku untuk teks bank apa pun (singkatan, nama lengkap, salah ketik yang dikenal), atau null bila tidak dikenal. */
    public static function kode(?string $teks): ?string
    {
        $k = self::kunci($teks);
        if ($k === '') {
            return null;
        }
        static $peta = null;
        if ($peta === null) {
            $peta = [];
            foreach (self::DAFTAR as $b) {
                foreach ([$b[0], $b[1], ...$b[3]] as $nama) {
                    $peta[self::kunci($nama)] ??= $b[0];
                }
            }
        }

        return $peta[$k] ?? null;
    }

    /** Aturan validasi: isian Bank harus ada di daftar (singkatan/nama lengkap/alias apa pun diterima, lalu dibakukan). */
    public static function aturan(): \Closure
    {
        return function (string $atribut, mixed $nilai, \Closure $gagal) {
            if (trim((string) $nilai) !== '' && self::kode((string) $nilai) === null) {
                $gagal("Bank/e-wallet \"{$nilai}\" tidak ada di daftar — pilih dari saran (ketik singkatan atau nama, mis. BCA, Mandiri, GoPay).");
            }
        };
    }

    /** Untuk data lama dari sheet: kode baku bila dikenali, selain itu teks aslinya (dirapikan spasinya). */
    public static function rapikan(?string $teks): ?string
    {
        $teks = trim(preg_replace('/\s+/', ' ', (string) $teks));

        return $teks === '' ? null : (self::kode($teks) ?? $teks);
    }

    /** "PT Bank Central Asia Tbk" → "centralasia"; "BCA" → "bca". */
    private static function kunci(?string $teks): string
    {
        $t = mb_strtolower(trim((string) $teks));
        $t = preg_replace('/\b(pt|tbk|persero)\b/u', ' ', $t);
        $t = preg_replace('/[^a-z0-9]+/', '', $t);
        $tanpaBank = preg_replace('/^bank/', '', $t);

        return $tanpaBank !== '' ? $tanpaBank : $t;
    }
}
