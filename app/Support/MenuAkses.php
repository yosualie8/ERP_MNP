<?php

namespace App\Support;

use App\Models\User;

/**
 * Menu yang bisa diatur per JABATAN karyawan (halaman Hak Akses Jabatan). Super Admin selalu boleh semua; akun lain
 * mengikuti jabatan karyawan aktif yang email-nya sama (tidak terhubung = tanpa menu). Setiap rute dipetakan ke menunya
 * supaya halaman juga ditolak bila dibuka lewat URL.
 */
class MenuAkses
{
    /** kunci => [label di navigasi, rute tujuan, pola nama rute yang termasuk menu ini, keterangan] */
    public const DAFTAR = [
        'dashboard-uj' => ['Dashboard UJ', 'dashboard.uj', ['dashboard.uj'], 'Jumlah UJ belum reimburse, transaksi UJ terakhir & 5 reimburse UJ terakhir (kapan data terakhir diperbarui)'],
        'dashboard-proyek' => ['Dashboard Progres Proyek', 'dashboard.proyek', ['dashboard.proyek'], 'Tahap berstatus progress: persen progres, biaya yang sudah keluar & rinciannya per komponen, tombol ke Anggaran'],
        'dashboard-biaya-dt' => ['Monitoring Biaya DT', 'dashboard.biaya-dt', ['dashboard.biaya-dt'], 'Biaya dump truck per bulan & per kategori (Kas UJ + Kas Harian ber-No Mobil), per DT, jumlah rit & biaya per rit, rincian transaksi per truk'],
        'dashboard-aktivitas' => ['Dashboard Aktivitas Admin', 'dashboard.aktivitas', ['dashboard.aktivitas'], 'Log kegiatan semua pengguna (khusus Super Admin)'],
        'tanya-ai' => ['Tanya AI', 'tanya-ai', ['tanya-ai', 'tanya-ai.*'], 'Chat tanya data ERP (hanya baca, sesuai menu akun) + 🎤 speech to text; pertanyaan & jawaban dicatat'],
        'dashboard-kas' => ['Dashboard Kas Harian', 'dashboard.kas', ['dashboard.kas'], 'Jumlah belum reimburse, transaksi Kas Harian terakhir & 5 history reimburse terakhir (kapan data terakhir diperbarui)'],
        'input-kas' => ['Input Kas', 'kas.input', ['kas.input', 'kas.input.*', 'kas.edit', 'kas.update', 'kas.hapus', 'kas.bon', 'kas.bon.*'], 'Input, edit & hapus transaksi Kas Harian (Bank Jago), foto bon'],
        'kas-harian' => ['Kas Harian', 'kas.index', ['kas.index'], 'Melihat buku Kas Harian per bulan'],
        'pengajuan-uj' => ['Pengajuan UJ', 'pengajuan-uj.daftar', ['pengajuan-uj.*'], 'Mengajukan uang jalan (divalidasi seperti Input UJ), lihat & batalkan pengajuan'],
        'input-uj' => ['Input UJ', 'uj.input', ['uj.input', 'uj.input.bayar-tanah', 'uj.store', 'uj.periksa', 'uj.edit', 'uj.update', 'uj.hapus'], 'Input, edit & hapus transaksi uang jalan (Kas Seabank), termasuk realisasi pengajuan'],
        'kas-uj' => ['Kas UJ', 'uj.index', ['uj.index'], 'Melihat daftar transaksi uang jalan'],
        'reimburse-uj' => ['Reimburse UJ', 'reimburse.index', ['reimburse.*'], 'Memilih & mencatat reimburse uang jalan, unduh Excel'],
        'pengalihan-uj' => ['Pengalihan UJ', 'pengalihan-uj.index', ['pengalihan-uj.*'], 'UJ yang sudah diberi tetapi truk pindah proyek sebelum bongkar: dialihkan ke proyek baru (saldo sopir untuk MOB / potong UJ) atau dikembalikan'],
        'rapikan-uj' => ['Rapikan Kas UJ', 'uj.rapikan', ['uj.rapikan', 'uj.rapikan.*'], 'Mengisi No Mobil yang kosong di Kas UJ (ditulis ke sheet)'],
        'input-ritasi' => ['Input Ritasi', 'ritasi.input', ['ritasi.input', 'ritasi.store', 'ritasi.periksa', 'ritasi.edit', 'ritasi.update', 'ritasi.hapus'], 'Input, edit & hapus ritasi dump truck'],
        'ritasi' => ['Ritasi', 'ritasi.index', ['ritasi.index'], 'Melihat daftar ritasi dump truck'],
        'buangan-truk' => ['Buangan Truck', 'ritasi.buangan', ['ritasi.buangan', 'ritasi.buangan.*'], 'Truk aktif 30 hari terakhir & tujuan buangannya; ubah tujuan buangan'],
        'performa-ritasi' => ['Performa Ritasi', 'ritasi.performa', ['ritasi.performa'], 'Jumlah rit per truk per bulan & tanggal; bagikan ke WhatsApp sebagai gambar'],
        'bayar-tanah' => ['Bayar Tanah', 'ritasi.bayar-tanah', ['ritasi.bayar-tanah', 'ritasi.bayar-tanah.*'], 'DO yang sudah ada uang jalannya di Kas UJ tetapi belum ada transaksi uang tanahnya'],
        'monitor-ritasi' => ['Monitor Ritasi', 'ritasi.monitor', ['ritasi.monitor', 'ritasi.monitor.*'], 'Isi data bongkar dari surat jalan (masuk ke Ritasi) — DO yang sudah ada uang jalannya di Kas UJ tetapi belum ada di data Ritasi (belum bongkar)'],
        'validasi-reimburse' => ['Validasi Reimburse', 'kas.validasi-reimburse', ['kas.validasi-reimburse', 'kas.validasi-reimburse.*'], 'Upload Excel daftar reimburse: cek double reimburse & rekap per Kode GL'],
        'riwayat-reimburse' => ['History Reimburse', 'kas.riwayat-reimburse', ['kas.riwayat-reimburse'], 'Rekap transaksi Kas Harian yang sudah direimburse per tanggal reimburse, klik untuk melihat rinciannya'],
        'kas-belum-reimburse' => ['⬇ Excel Belum Reimburse', 'kas.belum-reimburse', ['kas.belum-reimburse'], 'Unduh daftar transaksi Kas Harian yang belum reimburse (Excel)'],
        'rekap' => ['Rekap Biaya', 'kas.rekap', ['kas.rekap'], 'Rekap biaya per akun × bulan'],
        'daftar-proyek' => ['Daftar Proyek', 'proyek.index', ['proyek.*'], 'Daftar proyek & tahap, pemetaan nama lama (Ritasi/BBM/Kas); data keuangan hanya Super Admin'],
        'anggaran-proyek' => ['Anggaran Proyek', 'anggaran.index', ['anggaran.*'], 'Rencana omset & HPP per tahap (komponen, baseline), dibanding biaya aktual & progres fisik dari kubikasi'],
        'pilah-transaksi' => ['Pilah Transaksi Proyek', 'pilah.index', ['pilah.*'], 'Transaksi Kas & BCA per proyek induk dipilah ke tahap detail (mis. PM2 Infra Regatta / Marina / PIK) & kode BOQ, dengan rekomendasi sistem; unduh Excel'],
        'boq-proyek' => ['BOQ & Progres Proyek', 'boq.index', ['boq.*'], 'Item BOQ per tahap (impor dari sheet penawaran), progres modal vs terpasang vs tertagih per item, opname volume terpasang & tagihan progres'],
        'alokasi-overhead' => ['Alokasi Biaya Umum', 'alokasi-overhead.index', ['alokasi-overhead.*'], 'Bagi biaya umum Kas (pos tanpa tahap, kantor) per bulan ke tahap yang berjalan — pilih manual dari rekomendasi, proporsional omset / rata'],
        'komponen-anggaran' => ['Master Komponen Anggaran', 'komponen-anggaran.index', ['komponen-anggaran.*'], 'Komponen HPP & omset: sumber realisasi, cara hitung, nilai bawaan, template per tipe tahap'],
        'pengaturan-proyek' => ['Pengaturan Daftar Proyek', 'pengaturan-proyek', ['pengaturan-proyek', 'pengaturan-proyek.*'], 'Filter bawaan grid Daftar Proyek (periode, status, cost center, tipe) untuk semua pengguna'],
        'kubikasi-kendaraan' => ['Kubikasi Kendaraan', 'kubikasi.index', ['kubikasi.*'], 'Kubikasi standar per jenis kendaraan (nilai awal kolom Kubikasi saat input ritasi)'],
        'input-bbm' => ['Input BBM & HM', 'bbm.input', ['bbm.input', 'bbm.store', 'bbm.edit', 'bbm.update', 'bbm.hapus', 'bbm.opname'], 'Input, edit & hapus pemakaian / solar masuk dan stock opname (ditulis ke sheet Penggunaan BBM)'],
        'dashboard-bbm' => ['Dashboard BBM & HM', 'bbm.dashboard', ['bbm.dashboard'], 'Tren konsumsi solar & jam HM proyek dan per alat, rasio liter/jam, kelengkapan data HM'],
        'bbm' => ['Data BBM & HM', 'bbm.index', ['bbm.index'], 'Penggunaan BBM per bulan: pemakaian per unit, solar masuk, stok solar, baris yang perlu dilengkapi'],
        'absen' => ['Lapor Lapangan', 'lapor.index', ['absen.index', 'lapor.index', 'lapor.simpan', 'lapor.cek'], 'Kirim foto absen, laporan kerja & kejadian lapangan (banyak foto, kamera depan/belakang atau galeri) — sistem yang memilah masuk, pulang, lembur & progres'],
        // Data Absensi & Data Lapor Lapangan disatukan (owner 19 Okt 2026) — kunci 'absensi' dipertahankan supaya hak akses jabatan tetap.
        'absensi' => ['Data Absensi & Lapor', 'lapor-data.index', ['absensi.*', 'lapor-data.*'], 'Rekap absensi per tanggal (masuk/pulang/lembur, yang tidak absen) + semua kiriman Lapor Lapangan: koreksi hasil pilah, kaitkan ke item BOQ, jam kerja standar & per proyek'],
        'lokasi-absen' => ['Lokasi Absen', 'lokasi-absen.index', ['lokasi-absen.*'], 'Titik GPS + radius per proyek tempat karyawan boleh absen'],
        'karyawan' => ['Data Karyawan', 'karyawan.index', ['karyawan.*'], 'Data induk karyawan (NIK, site, jabatan, rekening) — dasar modul absensi'],
        'jabatan' => ['Master Jabatan', 'jabatan.index', ['jabatan.*'], 'Daftar jabatan karyawan: tambah, ganti nama, hapus (jabatan Data Karyawan dipilih dari sini)'],
        'aset' => ['Data Aset', 'aset.index', ['aset.*'], 'Semua aset MNP (dump truck, alat berat, LV, engkel, …): lihat, tambah & ubah'],
        'mutasi-pt' => ['Mutasi Rekening PT (BCA)', 'mutasi-pt.index', ['mutasi-pt.*'], 'Mutasi rekening PT BCA dari file Drive — hanya dilihat, per bulan & cari keterangan'],
        'kode-gl' => ['Kode GL', 'kas.kode-gl', ['kas.kode-gl'], 'Daftar Kode GL'],
        'daftar-rekening' => ['Daftar Rekening', 'rekening.index', ['rekening.*'], 'Database rekening bersama Kas Harian & Kas UJ: satu nomor rekening = satu nama pemilik (saran di Input Kas & Input UJ); isi pemilik yang belum diketahui'],
    ];

    /** Kelompok menu di sidebar: kunci => [judul, ikon, menu di dalamnya]. */
    public const KATEGORI = [
        'asisten' => ['Asisten AI', '🤖', ['tanya-ai']],
        'dashboard' => ['Dashboard', '📊', ['dashboard-proyek', 'dashboard-kas', 'dashboard-uj', 'dashboard-biaya-dt', 'dashboard-bbm', 'dashboard-aktivitas']],
        'proyek' => ['Proyek', '🏗️', ['daftar-proyek', 'anggaran-proyek', 'pilah-transaksi', 'boq-proyek', 'alokasi-overhead', 'komponen-anggaran', 'pengaturan-proyek']],
        'kas' => ['Kas Harian', '🏦', ['input-kas', 'kas-harian', 'validasi-reimburse', 'riwayat-reimburse', 'kas-belum-reimburse', 'rekap', 'mutasi-pt', 'kode-gl', 'daftar-rekening']],
        'uj' => ['Uang Jalan', '🚚', ['pengajuan-uj', 'input-uj', 'kas-uj', 'reimburse-uj', 'pengalihan-uj', 'rapikan-uj']],
        'ritasi' => ['Ritasi', '⛰️', ['input-ritasi', 'ritasi', 'buangan-truk', 'monitor-ritasi', 'bayar-tanah', 'performa-ritasi', 'kubikasi-kendaraan']],
        'bbm' => ['BBM & HM', '⛽', ['input-bbm', 'bbm']],
        'karyawan' => ['Karyawan', '👷', ['absen', 'absensi', 'karyawan', 'jabatan', 'lokasi-absen']],
        'aset' => ['Aset', '🚛', ['aset']],
    ];

    /** Foto bon dipakai Input Kas dan Input UJ: boleh bila salah satunya boleh. */
    // oauth.* = izin ChatGPT (MCP) membaca data ERP → hanya akun dengan menu Tanya AI.
    private const BERSAMA = ['oauth.izin' => ['tanya-ai'], 'oauth.putuskan' => ['tanya-ai'], 'absen.foto' => ['absen', 'absensi'], 'lapor.foto' => ['absen', 'absensi'], 'kas.foto' => ['input-kas', 'kas-harian', 'input-uj', 'kas-uj'], 'kas.foto.destroy' => ['input-kas', 'input-uj']];

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
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Selain Super Admin: menu mengikuti JABATAN karyawan aktif yang email-nya sama dengan akun (Hak Akses Jabatan).
        return in_array($menu, $user->menuJabatan(), true);
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
