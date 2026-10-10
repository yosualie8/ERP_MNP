<?php

namespace App\Support;

use App\Models\User;

/**
 * Menu yang bisa diatur per akun (halaman Pengguna). Super Admin selalu boleh semua; Admin hanya menu yang dicentang
 * (belum pernah diatur = semua menu). Setiap rute dipetakan ke menunya supaya halaman juga ditolak bila dibuka lewat URL.
 */
class MenuAkses
{
    /** kunci => [label di navigasi, rute tujuan, pola nama rute yang termasuk menu ini, keterangan] */
    public const DAFTAR = [
        'dashboard-uj' => ['Dashboard UJ', 'dashboard.uj', ['dashboard.uj'], 'Jumlah UJ belum reimburse, transaksi UJ terakhir & 5 reimburse UJ terakhir (kapan data terakhir diperbarui)'],
        'dashboard-aktivitas' => ['Dashboard Aktivitas Admin', 'dashboard.aktivitas', ['dashboard.aktivitas'], 'Log kegiatan semua pengguna (khusus Super Admin)'],
        'tanya-ai' => ['Tanya AI', 'tanya-ai', ['tanya-ai', 'tanya-ai.*'], 'Chat tanya data ERP (hanya baca, sesuai menu akun) + 🎤 speech to text; pertanyaan & jawaban dicatat'],
        'dashboard-kas' => ['Dashboard Kas Harian', 'dashboard.kas', ['dashboard.kas'], 'Jumlah belum reimburse, transaksi Kas Harian terakhir & 5 history reimburse terakhir (kapan data terakhir diperbarui)'],
        'input-kas' => ['Input Kas', 'kas.input', ['kas.input', 'kas.input.*', 'kas.edit', 'kas.update', 'kas.hapus', 'kas.bon', 'kas.bon.*'], 'Input, edit & hapus transaksi Kas Harian (Bank Jago), foto bon'],
        'kas-harian' => ['Kas Harian', 'kas.index', ['kas.index', 'kas.sinkron'], 'Melihat buku Kas Harian per bulan'],
        'pengajuan-uj' => ['Pengajuan UJ', 'pengajuan-uj.daftar', ['pengajuan-uj.*'], 'Mengajukan uang jalan (divalidasi seperti Input UJ), lihat & batalkan pengajuan'],
        'input-uj' => ['Input UJ', 'uj.input', ['uj.input', 'uj.store', 'uj.periksa', 'uj.edit', 'uj.update', 'uj.hapus'], 'Input, edit & hapus transaksi uang jalan (Kas Seabank), termasuk realisasi pengajuan'],
        'kas-uj' => ['Kas UJ', 'uj.index', ['uj.index', 'uj.sinkron'], 'Melihat daftar transaksi uang jalan'],
        'reimburse-uj' => ['Reimburse UJ', 'reimburse.index', ['reimburse.*'], 'Memilih & mencatat reimburse uang jalan, unduh Excel'],
        'rapikan-uj' => ['Rapikan Kas UJ', 'uj.rapikan', ['uj.rapikan', 'uj.rapikan.*'], 'Mengisi No Mobil yang kosong di Kas UJ (ditulis ke sheet)'],
        'input-ritasi' => ['Input Ritasi', 'ritasi.input', ['ritasi.input', 'ritasi.store', 'ritasi.periksa', 'ritasi.edit', 'ritasi.update', 'ritasi.hapus'], 'Input, edit & hapus ritasi dump truck'],
        'ritasi' => ['Ritasi', 'ritasi.index', ['ritasi.index', 'ritasi.sinkron'], 'Melihat daftar ritasi dump truck'],
        'buangan-truk' => ['Buangan Truck', 'ritasi.buangan', ['ritasi.buangan', 'ritasi.buangan.*'], 'Truk aktif 30 hari terakhir & tujuan buangannya; ubah tujuan buangan'],
        'performa-ritasi' => ['Performa Ritasi', 'ritasi.performa', ['ritasi.performa'], 'Jumlah rit per truk per bulan & tanggal; bagikan ke WhatsApp sebagai gambar'],
        'bayar-tanah' => ['Bayar Tanah', 'ritasi.bayar-tanah', ['ritasi.bayar-tanah'], 'DO yang sudah ada uang jalannya di Kas UJ tetapi belum ada transaksi uang tanahnya'],
        'monitor-ritasi' => ['Monitor Ritasi', 'ritasi.monitor', ['ritasi.monitor', 'ritasi.monitor.*'], 'Isi data bongkar dari surat jalan (masuk ke Ritasi) — DO yang sudah ada uang jalannya di Kas UJ tetapi belum ada di data Ritasi (belum bongkar)'],
        'validasi-reimburse' => ['Validasi Reimburse', 'kas.validasi-reimburse', ['kas.validasi-reimburse', 'kas.validasi-reimburse.*'], 'Upload Excel daftar reimburse: cek double reimburse & rekap per Kode GL'],
        'riwayat-reimburse' => ['History Reimburse', 'kas.riwayat-reimburse', ['kas.riwayat-reimburse'], 'Rekap transaksi Kas Harian yang sudah direimburse per tanggal reimburse, klik untuk melihat rinciannya'],
        'transfer-bca' => ['Transfer Massal BCA', 'kas.transfer-bca', ['kas.transfer-bca', 'kas.transfer-bca.*'], 'Buat file Multi Auto-Transfer KlikBCA Bisnis (BI-FAST) cukup dari nama, bank, rekening & nominal'],
        'kas-belum-reimburse' => ['⬇ Excel Belum Reimburse', 'kas.belum-reimburse', ['kas.belum-reimburse'], 'Unduh daftar transaksi Kas Harian yang belum reimburse (Excel)'],
        'rekap' => ['Rekap Biaya', 'kas.rekap', ['kas.rekap'], 'Rekap biaya per akun × bulan'],
        'aset' => ['Data Aset Truk', 'aset.index', ['aset.*'], 'Daftar truk milik MNP: lihat, tambah & ubah'],
        'kode-gl' => ['Kode GL', 'kas.kode-gl', ['kas.kode-gl'], 'Daftar Kode GL'],
    ];

    /** Kelompok menu di sidebar: kunci => [judul, ikon, menu di dalamnya]. */
    public const KATEGORI = [
        'asisten' => ['Asisten AI', '🤖', ['tanya-ai']],
        'dashboard' => ['Dashboard', '📊', ['dashboard-kas', 'dashboard-uj', 'dashboard-aktivitas']],
        'kas' => ['Kas Harian', '🏦', ['input-kas', 'kas-harian', 'validasi-reimburse', 'riwayat-reimburse', 'transfer-bca', 'kas-belum-reimburse', 'rekap', 'kode-gl']],
        'uj' => ['Uang Jalan', '🚚', ['pengajuan-uj', 'input-uj', 'kas-uj', 'reimburse-uj', 'rapikan-uj']],
        'ritasi' => ['Ritasi', '⛰️', ['input-ritasi', 'ritasi', 'buangan-truk', 'monitor-ritasi', 'bayar-tanah', 'performa-ritasi']],
        'aset' => ['Aset', '🚛', ['aset']],
    ];

    /** Foto bon dipakai Input Kas dan Input UJ: boleh bila salah satunya boleh. */
    private const BERSAMA = ['kas.foto' => ['input-kas', 'kas-harian', 'input-uj', 'kas-uj'], 'kas.foto.destroy' => ['input-kas', 'input-uj']];

    /** Menu yang hanya untuk Super Admin (tidak bisa diberikan ke Admin). */
    public const KHUSUS_SUPER = ['dashboard-aktivitas'];

    public static function boleh(?User $user, string $menu): bool
    {
        if (! $user) {
            return false;
        }
        if (in_array($menu, self::KHUSUS_SUPER, true)) {
            return $user->isSuperAdmin();
        }
        if ($user->isSuperAdmin() || $user->menu === null) {
            return true;
        }

        return in_array($menu, $user->menu, true);
    }

    /** Menu-menu yang mencakup rute ini (kosong = rute tidak dibatasi menu, mis. Beranda). */
    public static function menuUntukRute(?string $rute): array
    {
        if (! $rute) {
            return [];
        }
        if (isset(self::BERSAMA[$rute])) {
            return self::BERSAMA[$rute];
        }
        foreach (self::DAFTAR as $kunci => [, , $pola]) {
            foreach ($pola as $p) {
                if (\Illuminate\Support\Str::is($p, $rute)) {
                    return [$kunci];
                }
            }
        }

        return [];
    }

    public static function bolehRute(?User $user, ?string $rute): bool
    {
        $menu = self::menuUntukRute($rute);

        return ! $menu || collect($menu)->contains(fn ($m) => self::boleh($user, $m));
    }

    /** Menu navigasi untuk akun ini: [kunci => [label, rute]]. */
    public static function navigasi(?User $user): array
    {
        return array_filter(self::DAFTAR, fn ($m, $kunci) => self::boleh($user, $kunci), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Menu navigasi per kategori untuk akun ini (kategori tanpa menu yang boleh tidak ditampilkan).
     *
     * @return array<string, array{judul: string, ikon: string, menu: array<string, array>}>
     */
    public static function kelompok(?User $user): array
    {
        $boleh = self::navigasi($user);
        $hasil = [];
        foreach (self::KATEGORI as $kunci => [$judul, $ikon, $isi]) {
            $menu = array_filter(array_map(fn ($k) => $boleh[$k] ?? null, array_combine($isi, $isi)));
            if ($menu) {
                $hasil[$kunci] = ['judul' => $judul, 'ikon' => $ikon, 'menu' => $menu];
            }
        }

        return $hasil;
    }
}
