<?php

return [
    'nama_aplikasi' => 'MNP ERP',
    'nama_perusahaan' => 'PT Multi Niaga Putra',
    'email_kontak' => env('MNP_EMAIL_KONTAK', 'yosua160891@gmail.com'),
    // Tanggal berlaku halaman Kebijakan Privasi & Syarat Layanan.
    'dokumen_berlaku_sejak' => '1 Oktober 2026',
    // Spreadsheet "Kas Harian MNP - 2026": lembar bulanan 0126, 0226, … = rekening Bank Jago.
    'sheet_kas_harian' => env('MNP_SHEET_KAS_HARIAN', '1C2nAZKVAWiyOJKAaStzoMTzuAHi8HW5R1dotGyc3dYI'),
];
