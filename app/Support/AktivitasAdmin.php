<?php

namespace App\Support;

/** Nama aktivitas yang mudah dibaca untuk kode aksi di log (tabel kas_riwayat), dikelompokkan per area. */
class AktivitasAdmin
{
    /** kode aksi => [area, nama aktivitas] */
    public const AKSI = [
        'tambah' => ['Kas Harian', 'Input Kas'],
        'ubah' => ['Kas Harian', 'Edit Kas'],
        'hapus' => ['Kas Harian', 'Hapus Kas'],
        'kas-no-mobil' => ['Kas Harian', 'Isi No Mobil Kas'],
        'kas-reimburse' => ['Kas Harian', 'Reimburse Kas (menu lama)'],
        'reimburse-validasi' => ['Kas Harian', 'Validasi Reimburse'],
        'tandai-reimburse' => ['Kas Harian', 'Tandai Sudah Reimburse'],
        'transfer-bca' => ['Kas Harian', 'Transfer Massal BCA'],
        'uj-tambah' => ['Uang Jalan', 'Input UJ'],
        'uj-ubah' => ['Uang Jalan', 'Edit UJ'],
        'uj-hapus' => ['Uang Jalan', 'Hapus UJ'],
        'uj-reimburse' => ['Uang Jalan', 'Reimburse UJ'],
        'uj-rapikan' => ['Uang Jalan', 'Rapikan Kas UJ'],
        'uj-id-biaya' => ['Uang Jalan', 'ID Biaya Transfer UJ'],
        'uj-id-ganda' => ['Uang Jalan', 'ID UJ Ganda'],
        'uj-ajukan' => ['Uang Jalan', 'Ajukan UJ'],
        'uj-ajukan-ubah' => ['Uang Jalan', 'Edit Pengajuan UJ'],
        'uj-ajukan-batal' => ['Uang Jalan', 'Batalkan Pengajuan UJ'],
        'uj-ajukan-transfer' => ['Uang Jalan', 'Tautkan Transfer Pengajuan'],
        'rit-tambah' => ['Ritasi', 'Input Ritasi'],
        'rit-ubah' => ['Ritasi', 'Edit Ritasi'],
        'rit-hapus' => ['Ritasi', 'Hapus Ritasi'],
        'truk-buangan' => ['Ritasi', 'Ubah Tujuan Buangan'],
        'aset-tambah' => ['Aset', 'Tambah Aset'],
        'aset-ubah' => ['Aset', 'Edit Aset'],
        'mcp-chatgpt' => ['AI', 'ChatGPT membaca data (MCP)'],
        'mcp-izin' => ['AI', 'Mengizinkan ChatGPT (MCP)'],
        'ai-tanya' => ['AI', 'Tanya AI'],
    ];

    public static function nama(string $aksi): string
    {
        return self::AKSI[$aksi][1] ?? $aksi;
    }

    public static function area(string $aksi): string
    {
        return self::AKSI[$aksi][0] ?? 'Lainnya';
    }

    /** @return string[] kode aksi di area ini */
    public static function aksiArea(string $area): array
    {
        return array_keys(array_filter(self::AKSI, fn ($x) => $x[0] === $area));
    }
}
