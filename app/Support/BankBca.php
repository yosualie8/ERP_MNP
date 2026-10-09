<?php

namespace App\Support;

/**
 * Kode SWIFT/BIC & nama singkat bank tujuan untuk Multi Auto-Transfer KlikBCA Bisnis, per kode bank aplikasi (DaftarBank).
 * Sumber: "Tabel Sandi Bank" KlikBCA Bisnis update 31 Maret 2026
 * (pustaka.bca.co.id/bisnis/layanan/e-banking-bisnis/klikbca-bisnis/data-bank-update-31-maret-2026.pdf).
 * E-wallet tidak ada di tabel itu — tidak bisa jadi tujuan Multi Auto-Transfer.
 */
class BankBca
{
    /** kode DaftarBank => [Sandi BIC, Nama Bank Singkat] */
    private const PETA = [
        'BCA' => ['CENAIDJA', 'BCA'],
        'BRI' => ['BRINIDJA', 'BRI'], 'Mandiri' => ['BMRIIDJA', 'BANK MANDIRI'], 'BNI' => ['BNINIDJA', 'BANK BNI'],
        'BTN' => ['BTANIDJA', 'BTN'], 'BSI' => ['BSMDIDJA', 'BSI'], 'BCA Syariah' => ['BSYAIDJA', 'BANK BCA SYARIAH'],
        'CIMB Niaga' => ['BNIAIDJA', 'BANK CIMB'], 'Danamon' => ['BDINIDJA', 'BANK DANAMON'], 'Permata' => ['BBBAIDJA', 'BANK PERMATA'],
        'Panin' => ['PINBIDJA', 'PANIN BANK'], 'Panin Dubai Syariah' => ['ARFAIDJ1', 'BANK PANIN DUBAI SYA'], 'OCBC' => ['NISPIDJA', 'BANK OCBC NISP'],
        'Maybank' => ['IBBKIDJA', 'BANK MAYBANK'], 'UOB' => ['BBIJIDJA', 'UOB INDONESIA'], 'SMBC Indonesia' => ['SUNIIDJA', 'Bank SMBC'],
        'BTPN Syariah' => ['PUBAIDJ1', 'BANK BTPN SYARIAH'], 'Mega' => ['MEGAIDJA', 'BANK MEGA'], 'Mega Syariah' => ['BUTGIDJ1', 'BANK MEGA SYARIAH'],
        'Sinarmas' => ['SBJKIDJA', 'BANK SINARMAS'], 'KB Bank' => ['BBUKIDJA', 'KB INDONESIA'], 'KB Bank Syariah' => ['SDOBIDJ1', 'KBBS'],
        'Muamalat' => ['MUABIDJA', 'BANK MUAMALAT'], 'MNC Bank' => ['BUMIIDJA', 'MNC BANK'], 'Artha Graha' => ['ARTGIDJA', 'BAG INTERNASIONAL'],
        'Mayapada' => ['MAYAIDJA', 'BANK MAYAPADA'], 'Maspion' => ['MASDIDJ1', 'BANK MASPION'], 'Mestika' => ['MEDHIDS1', 'BANK MESTIKA'],
        'Index' => ['BIDXIDJA', 'BANK INDEX'], 'Victoria' => ['VICTIDJ1', 'BANK VICTORIA'], 'Capital' => ['BCIAIDJA', 'BANK CAPITAL'],
        'Ina Perdana' => ['IAPTIDJA', 'BANK INA'], 'Sahabat Sampoerna' => ['SAHMIDJA', 'BANK SAMPOERNA'], 'JTrust' => ['CICTIDJA', 'BANK JTRUST'],
        'Shinhan' => ['MEEKIDJ1', 'BANK SHINHAN IND'], 'Woori Saudara' => ['BSDRIDJA', 'BANK WOORI SAUDARA'], 'IBK' => ['IBKOIDJA', 'BANK IBK'],
        'QNB' => ['AWANIDJA', 'BANK QNB'], 'Multiarta Sentosa' => ['BMSEIDJA', 'PT. BANK MAS'], 'Mandiri Taspen' => ['SIHBIDJ1', 'BANK MANDIRI TASPEN'],
        'Nobu' => ['LFIBIDJ1', 'BANK NATIONALNOBU'], 'Oke Bank' => ['LMANIDJ1', 'BANK OKE'], 'Ganesha' => ['GNESIDJA', 'BANK GANESHA'],
        'Resona Perdania' => ['BPIAIDJA', 'BANK RESONA'], 'Mizuho' => ['MHCCIDJA', 'BANK MIZUHO'], 'ICBC' => ['ICBKIDJA', 'BANK ICBC'],
        'Bank of China' => ['BKCHIDJA', 'BOC LIMITED'], 'CCB Indonesia' => ['MCORIDJA', 'CHINA CONSTRUCTION B'], 'HSBC' => ['HSBCIDJA', 'BANK HSBC INDONESIA'],
        'Citibank' => ['CITIIDJX', 'CITIBANK'], 'Standard Chartered' => ['SCBLIDJX', 'STANDCHARD'], 'DBS' => ['DBSBIDJA', 'DBS'],
        'BNP Paribas' => ['BNPAIDJA', 'BNP PARIBAS'], 'Hana Bank' => ['HNBNIDJA', 'BANK KEB HANA'],
        'Jago' => ['JAGBIDJA', 'BANK JAGO'], 'Seabank' => ['SSPIIDJA', 'SEABANK'], 'blu' => ['BBLUIDJA', 'BCA Digital'],
        'Neo Commerce' => ['YUDBIDJ1', 'BANK NEO COMMERCE'], 'Allo Bank' => ['ALOBIDJA', 'ALLO BANK INDONESIA'], 'Superbank' => ['FAMAIDJ1', 'SUPER BANK'],
        'Krom' => ['BUSTIDJ1', 'PT KROM BANK INDONES'], 'Hibank' => ['HBNIIDJA', 'HIBANK'], 'Bank Raya' => ['AGTBIDJA', 'BANK RAYA INDONESIA'],
        'Aladin' => ['NETBIDJA', 'BANK ALADIN SYARIAH'], 'Amar Bank' => ['LOMAIDJ1', 'BANK AMAR'], 'Saqu' => ['JSABIDJ1', 'BANK SAQU'],
        'Bank DKI' => ['BDKIIDJ1', 'BANK DKI'], 'BJB' => ['PDJBIDJA', 'BANK BJB'], 'BJB Syariah' => ['SYJBIDJ1', 'BANK JABAR SYARIAH'],
        'Bank Banten' => ['PDBBIDJ1', 'PT. BPD BANTEN'], 'Bank Jateng' => ['PDJGIDJ1', 'BANK JATENG'], 'Bank Jatim' => ['PDJTIDJ1', 'BANK JATIM'],
        'BPD DIY' => ['PDYKIDJ1', 'BANK BPD DIY'], 'Bank Aceh' => ['SYACIDJ1', 'BANK ACEH'], 'Bank Sumut' => ['PDSUIDJ1', 'BANK SUMUT'],
        'Bank Nagari' => ['PDSBIDJ1', 'PT. BANK NAGARI'], 'BRK Syariah' => ['PDRIIDJA', 'BANK RIAU KEPRI SYAR'], 'Bank Jambi' => ['PDJMIDJ1', 'BPD JAMBI'],
        'Bank Sumsel Babel' => ['BSSPIDSP', 'BPD SUMSEL BABEL'], 'Bank Bengkulu' => ['PDBKIDJ1', 'BANK BENGKULU'], 'Bank Lampung' => ['PDLPIDJ1', 'BANK LAMPUNG'],
        'Bank Kalbar' => ['PDKBIDJ1', 'BPD KALBAR'], 'Bank Kalteng' => ['PDKGIDJ1', 'BPD KALTENG'], 'Bank Kalsel' => ['PDKSIDJ1', 'BPD KALSEL'],
        'Bankaltimtara' => ['PDKTIDJ1', 'BPD KALTIMTARA'], 'Bank Sulselbar' => ['PDWSIDJA', 'BANK SULSELBAR'], 'Bank SulutGo' => ['PDWUIDJ1', 'BPD SULUT'],
        'Bank Sulteng' => ['PDWGIDJ1', 'BPD SULTENG'], 'Bank Sultra' => ['PDWRIDJ1', 'BPD SULTRA'], 'Bank Bali' => ['ABALIDBS', 'PT. BPD BALI'],
        'Bank NTB Syariah' => ['PDNBIDJ1', 'PT. BANK NTB SYARIAH'], 'Bank NTT' => ['PDNTIDJA', 'PT BPD NTT'], 'Bank Maluku Malut' => ['PDMLIDJ1', 'BANK MALUKU MALUT'],
        'Bank Papua' => ['PDIJIDJ1', 'BPD PAPUA'],
    ];

    /** Batas BI-FAST per transaksi (Bank Indonesia): Rp 250 juta. */
    public const MAKS_BIFAST = 250_000_000;

    /** @return array{bic: string, nama: string}|null */
    public static function untuk(?string $kode): ?array
    {
        $p = self::PETA[$kode] ?? null;

        return $p ? ['bic' => $p[0], 'nama' => $p[1]] : null;
    }

    /** Sesama BCA memakai Transfer Type "BCA"; bank lain BI-FAST ("BIF"). */
    public static function jenisTransfer(string $kode): string
    {
        return $kode === 'BCA' ? 'BCA' : 'BIF';
    }

    /** Kode bank aplikasi yang bisa dipakai (untuk pesan galat). @return string[] */
    public static function didukung(): array
    {
        return array_keys(self::PETA);
    }
}
