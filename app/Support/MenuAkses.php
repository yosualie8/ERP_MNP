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
        'input-kas' => ['Input Kas', 'kas.input', ['kas.input', 'kas.input.*', 'kas.edit', 'kas.update', 'kas.hapus', 'kas.bon', 'kas.bon.*'], 'Input, edit & hapus transaksi Kas Harian (Bank Jago), foto bon'],
        'kas-harian' => ['Kas Harian', 'kas.index', ['kas.index', 'kas.sinkron'], 'Melihat buku Kas Harian per bulan'],
        'pengajuan-uj' => ['Pengajuan UJ', 'pengajuan-uj.daftar', ['pengajuan-uj.*'], 'Mengajukan uang jalan (divalidasi seperti Input UJ), lihat & batalkan pengajuan'],
        'input-uj' => ['Input UJ', 'uj.input', ['uj.input', 'uj.store', 'uj.periksa', 'uj.edit', 'uj.update', 'uj.hapus'], 'Input, edit & hapus transaksi uang jalan (Kas Seabank), termasuk realisasi pengajuan'],
        'kas-uj' => ['Kas UJ', 'uj.index', ['uj.index', 'uj.sinkron'], 'Melihat daftar transaksi uang jalan'],
        'reimburse-uj' => ['Reimburse UJ', 'reimburse.index', ['reimburse.*'], 'Memilih & mencatat reimburse uang jalan, unduh Excel'],
        'rapikan-uj' => ['Rapikan Kas UJ', 'uj.rapikan', ['uj.rapikan', 'uj.rapikan.*'], 'Mengisi No Mobil yang kosong di Kas UJ (ditulis ke sheet)'],
        'input-ritasi' => ['Input Ritasi', 'ritasi.input', ['ritasi.input', 'ritasi.store', 'ritasi.periksa', 'ritasi.edit', 'ritasi.update', 'ritasi.hapus'], 'Input, edit & hapus ritasi dump truck'],
        'ritasi' => ['Ritasi', 'ritasi.index', ['ritasi.index', 'ritasi.sinkron'], 'Melihat daftar ritasi dump truck'],
        'monitor-ritasi' => ['Monitor Ritasi', 'ritasi.monitor', ['ritasi.monitor'], 'DO yang sudah ada uang jalannya di Kas UJ tetapi belum ada di data Ritasi (belum bongkar)'],
        'validasi-reimburse' => ['Validasi Reimburse', 'kas.validasi-reimburse', ['kas.validasi-reimburse', 'kas.validasi-reimburse.*'], 'Upload Excel daftar reimburse: cek double reimburse & rekap per Kode GL'],
        'kas-belum-reimburse' => ['⬇ Excel Belum Reimburse', 'kas.belum-reimburse', ['kas.belum-reimburse'], 'Unduh daftar transaksi Kas Harian yang belum reimburse (Excel)'],
        'rekap' => ['Rekap Biaya', 'kas.rekap', ['kas.rekap'], 'Rekap biaya per akun × bulan'],
        'aset' => ['Data Aset Truk', 'aset.index', ['aset.*'], 'Daftar truk milik MNP: lihat, tambah & ubah'],
        'kode-gl' => ['Kode GL', 'kas.kode-gl', ['kas.kode-gl'], 'Daftar Kode GL'],
    ];

    /** Kelompok menu di sidebar: kunci => [judul, ikon, menu di dalamnya]. */
    public const KATEGORI = [
        'kas' => ['Kas Harian', '🏦', ['input-kas', 'kas-harian', 'validasi-reimburse', 'kas-belum-reimburse', 'rekap', 'kode-gl']],
        'uj' => ['Uang Jalan', '🚚', ['pengajuan-uj', 'input-uj', 'kas-uj', 'reimburse-uj', 'rapikan-uj']],
        'ritasi' => ['Ritasi', '⛰️', ['input-ritasi', 'ritasi', 'monitor-ritasi']],
        'aset' => ['Aset', '🚛', ['aset']],
    ];

    /** Foto bon dipakai Input Kas dan Input UJ: boleh bila salah satunya boleh. */
    private const BERSAMA = ['kas.foto' => ['input-kas', 'kas-harian', 'input-uj', 'kas-uj'], 'kas.foto.destroy' => ['input-kas', 'input-uj']];

    public static function boleh(?User $user, string $menu): bool
    {
        if (! $user) {
            return false;
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
