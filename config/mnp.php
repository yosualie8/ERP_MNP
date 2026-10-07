<?php

return [
    'nama_aplikasi' => 'MNP ERP',
    'nama_perusahaan' => 'PT Multi Niaga Putra',
    'email_kontak' => env('MNP_EMAIL_KONTAK', 'yosua160891@gmail.com'),
    // Tanggal berlaku halaman Kebijakan Privasi & Syarat Layanan.
    'dokumen_berlaku_sejak' => '1 Oktober 2026',
    // Spreadsheet "Kas Harian MNP - 2026": lembar bulanan 0126, 0226, … = rekening Bank Jago.
    'sheet_kas_harian' => env('MNP_SHEET_KAS_HARIAN', '1C2nAZKVAWiyOJKAaStzoMTzuAHi8HW5R1dotGyc3dYI'),
    // Foto bon: folder Google Drive "Foto Bon MNP" milik akun perusahaan (subfolder per lembar dibuat otomatis).
    'drive_foto_folder' => env('MNP_DRIVE_FOTO_FOLDER', '1Rs3zaSYnOrnZTdQofyKID9qezYy8yEVw'),
    'drive_foto_email' => env('MNP_DRIVE_FOTO_EMAIL', 'ptmultiniagaputra@gmail.com'),
    // Spreadsheet "(0) Transaksi Belum Reimburse V3": lembar "Mutasi Reimburse" = cermin baris Kas Harian (satu baris per detail).
    'sheet_reimburse' => env('MNP_SHEET_REIMBURSE', '1FZJqYd241-sz1XGsybeYH-jXfiMqGoE6wRY1lXiBTxk'),
    // Spreadsheet "KAS MMP Uang Jalan dan UM": lembar "Kas Seabank" = kas uang jalan dump truck (rekening Seabank).
    'sheet_uj' => env('MNP_SHEET_UJ', '10Gode9eGhAKWCzUzjV_shkj1GZwZDo5uN7qe237nTUY'),
    // Impor Kas Seabank dari sheet hanya sampai transaksi yang berisi ID UJ ini (permintaan user 7 Okt 2026);
    // transaksi dari aplikasi selalu ikut.
    'uj_impor_sampai_id' => env('MNP_UJ_IMPOR_SAMPAI_ID', 13034),
];
