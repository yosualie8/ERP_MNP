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
        'dashboard-proyek' => ['Dashboard Progres Proyek', 'dashboard.proyek', ['dashboard.proyek'], 'Semua tahap (urut aktivitas awal terbaru): status, persen progres, biaya yang sudah keluar & rinciannya per komponen, tombol ke Anggaran'],
        'dashboard-biaya-dt' => ['Dashboard Biaya DT', 'dashboard.biaya-dt', ['dashboard.biaya-dt'], 'Biaya dump truck per bulan & per kategori (Kas UJ + Kas Harian ber-No Mobil), per DT, jumlah rit & biaya per rit, rincian transaksi per truk'],
        'dashboard-performa-dt' => ['Dashboard Laba Rugi DT', 'dashboard.performa-dt', ['dashboard.performa-dt', 'dashboard.performa-dt.*'], 'Laba rugi per dump truck: harga jual rit (lembar Ritasi) dikurangi biaya langsung truk (Kas UJ, Kas Harian, BCA ber-DT); buka DT → pendapatan per proyek, biaya per kategori & semua transaksinya'],
        'dashboard-hm-km' => ['Dashboard HM & KM', 'dashboard.hm-km', ['dashboard.hm-km', 'dashboard.hm-km.*'], 'HM alat berat (otomatis dari Data BBM), KM dump truck, dan HM / KM kendaraan & aset lain: angka terakhir, kapan dicatat, pemakaian per bulan & rata-rata per hari, riwayat per unit (hanya melihat)'],
        // Owner 19 Nov 2026: tinjau foto absen (mis. foto HM / indikator solar operator alat berat).
        'dashboard-foto-absen' => ['Dashboard Foto Absensi', 'dashboard.foto-absen', ['dashboard.foto-absen'], 'Foto kiriman absen (Lapor Lapangan & absen langsung) per orang per hari: saring nama / jabatan / tanggal / jenis, klik foto untuk memperbesar (hanya melihat)'],
        'dashboard-aktivitas' => ['Dashboard Aktivitas Admin', 'dashboard.aktivitas', ['dashboard.aktivitas'], 'Log kegiatan semua pengguna (khusus Super Admin)'],
        'tanya-ai' => ['Tanya AI', 'tanya-ai', ['tanya-ai', 'tanya-ai.*'], 'Chat tanya data ERP (hanya baca, sesuai menu akun) + 🎤 speech to text; pertanyaan & jawaban dicatat'],
        'input-kas' => ['Input Kas', 'kas.input', ['kas.input', 'kas.input.*', 'kas.edit', 'kas.update', 'kas.hapus', 'kas.bon', 'kas.bon.*'], 'Input, edit & hapus transaksi Kas Harian (Bank Jago), foto bon'],
        'kas-harian' => ['Kas Harian', 'kas.index', ['kas.index', 'kas.unduh', 'kas.unduh.*'], 'Melihat buku Kas Harian per bulan'],
        // Membuat / mengubah / membatalkan pengajuan dipisah dari melihat Pengajuan UJ (owner 23 Okt 2026: Admin Mobil hanya melihat & merealisasikan).
        // Harus sebelum 'pengajuan-uj' supaya rute-rute ini tidak tertangkap pola pengajuan-uj.*.
        'ajukan-uj' => ['Buat Pengajuan UJ', 'pengajuan-uj.buat', ['pengajuan-uj.buat', 'pengajuan-uj.simpan', 'pengajuan-uj.periksa', 'pengajuan-uj.ubah', 'pengajuan-uj.simpan-ubah', 'pengajuan-uj.batal'], 'Membuat, mengubah & membatalkan pengajuan uang jalan (divalidasi seperti Input UJ)'],
        'pengajuan-uj' => ['Pengajuan UJ', 'pengajuan-uj.daftar', ['pengajuan-uj.*'], 'Melihat pengajuan uang jalan & memilih detail yang sudah ditransfer untuk direalisasikan di Input UJ'],
        'input-uj' => ['Input UJ', 'uj.input', ['uj.input', 'uj.input.bayar-tanah', 'uj.store', 'uj.periksa', 'uj.edit', 'uj.update', 'uj.hapus'], 'Input, edit & hapus transaksi uang jalan (Kas Seabank), termasuk realisasi pengajuan'],
        'kas-uj' => ['Kas UJ', 'uj.index', ['uj.index'], 'Melihat daftar transaksi uang jalan'],
        'reimburse-uj' => ['Reimburse UJ', 'reimburse.index', ['reimburse.*'], 'Memilih & mencatat reimburse uang jalan, unduh Excel'],
        'pengalihan-uj' => ['Hutang Supir', 'pengalihan-uj.index', ['pengalihan-uj.*'], 'Buku hutang per sopir (uang PT di tangan sopir): UJ belum bongkar, kasbon, dan potongannya (uang jalan, MOB, gaji, dikembalikan)'],
        'rapikan-uj' => ['Rapikan Kas UJ', 'uj.rapikan', ['uj.rapikan', 'uj.rapikan.*'], 'Mengisi No Mobil yang kosong di Kas UJ (ditulis ke sheet)'],
        'input-ritasi' => ['Input Ritasi', 'ritasi.input', ['ritasi.input', 'ritasi.store', 'ritasi.periksa', 'ritasi.edit', 'ritasi.update', 'ritasi.hapus'], 'Input, edit & hapus ritasi dump truck'],
        'muatan-kurang' => ['Muatan Kurang (MK)', 'mk.index', ['mk.*'], 'Tandai surat jalan muatan kurang (MK, bawaan Rp25.000) → hutang sopir bertambah, dipotong dari uang jalan berikutnya (diusulkan otomatis di Input UJ); juga lewat centang MK di Monitor Ritasi'],
        'mk-ubah-nominal' => ['Ubah nominal MK', 'mk.index', ['mk-nominal.*'], 'Mengubah nominal MK (bawaan Rp25.000) saat menandai / sesudahnya selama belum dipotong'],
        'potongan-muatan' => ['Potongan Muatan', 'potongan-muatan.index', ['potongan-muatan.*'], 'Rit Kendal/Jogja ber-LS di bawah standar volume jenis truk (Faw/Mercy): potongan per sopir per hari, dicatat ke Hutang Supir (dipotong dari uang jalan besoknya)'],
        'ritasi' => ['Ritasi', 'ritasi.index', ['ritasi.index', 'ritasi.unduh', 'ritasi.unduh.*'], 'Melihat daftar ritasi dump truck'],
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
        'kebutuhan-material' => ['Kebutuhan Material', 'kebutuhan-material.index', ['kebutuhan-material.*'], 'Catat material yang dibutuhkan proyek lewat 🎤 (satu rekaman pendek per material, bisa tanpa sinyal — dikirim otomatis saat ada sinyal): suara → item, qty, satuan + saran harga & satuan dari riwayat transaksi (Kas Harian, Kas UJ, Database Sparepart, harga yang sudah dipastikan)'],
        'penawaran-spk' => ['Penawaran & SPK', 'penawaran-spk.index', ['penawaran-spk.*'], 'Penawaran terkirim & SPK masuk dari Gmail MNP (dibaca otomatis, SPK di-OCR): cek angka di samping PDF-nya, koreksi, tautkan SPK ke tahap → omset tahap terisi'],
        'alokasi-overhead' => ['Alokasi Biaya Umum', 'alokasi-overhead.index', ['alokasi-overhead.*'], 'Bagi biaya umum Kas (pos tanpa tahap, kantor) per bulan ke tahap yang berjalan — pilih manual dari rekomendasi, proporsional omset / rata'],
        'komponen-anggaran' => ['Master Komponen Anggaran', 'komponen-anggaran.index', ['komponen-anggaran.*'], 'Komponen HPP & omset: sumber realisasi, cara hitung, nilai bawaan, template per tipe tahap'],
        'pengaturan-proyek' => ['Pengaturan Daftar Proyek', 'pengaturan-proyek', ['pengaturan-proyek', 'pengaturan-proyek.*'], 'Filter bawaan grid Daftar Proyek (periode, status, cost center, tipe) untuk semua pengguna'],
        'kubikasi-kendaraan' => ['Kubikasi Kendaraan', 'kubikasi.index', ['kubikasi.*'], 'Kubikasi standar per jenis kendaraan (nilai awal kolom Kubikasi saat input ritasi)'],
        'input-bbm' => ['Input BBM & HM', 'bbm.input', ['bbm.input', 'bbm.store', 'bbm.edit', 'bbm.update', 'bbm.hapus', 'bbm.opname'], 'Input, edit & hapus pemakaian / solar masuk dan stock opname (ditulis ke sheet Penggunaan BBM)'],
        'dashboard-bbm' => ['Dashboard BBM & HM', 'bbm.dashboard', ['bbm.dashboard'], 'Tren konsumsi solar & jam HM proyek dan per alat, rasio liter/jam, kelengkapan data HM'],
        // Owner 19 Nov 2026: isi N liter → N liter solar keluar terakhir habis ke mana (unit, proyek, jenis).
        'dashboard-penggunaan-bbm' => ['Dashboard Penggunaan BBM', 'bbm.penggunaan', ['bbm.penggunaan'], 'Isi jumlah liter (mis. 1.000) → riwayat N liter solar keluar terakhir habis ke mana: per unit, per proyek, per jenis, dan rincian tiap isi'],
        'bbm' => ['Data BBM & HM', 'bbm.index', ['bbm.index'], 'Penggunaan BBM per bulan: pemakaian per unit, solar masuk, stok solar, baris yang perlu dilengkapi'],
        'absen' => ['Lapor Lapangan', 'lapor.index', ['absen.index', 'lapor.index', 'lapor.simpan', 'lapor.cek'], 'Kirim foto absen, laporan kerja & kejadian lapangan (banyak foto, kamera depan/belakang atau galeri) — sistem yang memilah masuk, pulang, lembur & progres'],
        // Data Absensi & Data Lapor Lapangan disatukan (owner 19 Okt 2026) — kunci 'absensi' dipertahankan supaya hak akses jabatan tetap.
        'absensi' => ['Data Absensi & Lapor', 'lapor-data.index', ['absensi.*', 'lapor-data.*'], 'Rekap absensi per tanggal (masuk/pulang/lembur, yang tidak absen) + semua kiriman Lapor Lapangan: koreksi hasil pilah, kaitkan ke item BOQ, jam kerja standar & per proyek'],
        'review-absen' => ['Review Absen Harian', 'review-absen.index', ['review-absen.*'], 'Per karyawan wajib absen: absen masuk & pulang lengkap atau belum; kirim review lewat teks grup WhatsApp atau WA pribadi (No HP di Data Karyawan)'],
        'gaji-mingguan' => ['Rekap Absen & Gaji Mingguan', 'gaji-mingguan.index', ['gaji-mingguan.*'], 'Per minggu Senin–Minggu: hari hadir dari absensi & mandor × UM per orang (bawaan jabatan), lembur ½ UM, setujui absen belum lengkap, tahap kerja per hari, rekap per tahap, unduh Excel'],
        // Koreksi jam absen (owner 9 Nov 2026, untuk Valen): tanpa menu sendiri — kolom Jam absen di dialog hari Rekap Absen & Gaji Mingguan.
        'absen-ubah-jam' => ['Ubah jam absen karyawan', 'gaji-mingguan.index', ['absen-jam.*'], 'Isi / ubah jam masuk, pulang & lembur karyawan yang bolong di Rekap Absen & Gaji Mingguan (absen asli tetap tersimpan; jam yang diedit ditandai ✎ beserta jam aslinya & siapa yang mengedit)'],
        'absen-mandor' => ['Absen Tukang (Mandor)', 'absen-mandor.index', ['absen-mandor.*'], 'Mandor mencentang tukang / helper (tanpa akun absen HP) yang hadir & lembur per tanggal beserta tahap kerjanya; tambah tukang baru'],
        'lokasi-absen' => ['Lokasi Absen', 'lokasi-absen.index', ['lokasi-absen.*'], 'Titik GPS + radius per proyek tempat karyawan boleh absen'],
        'karyawan' => ['Data Karyawan', 'karyawan.index', ['karyawan.*'], 'Data induk karyawan (NIK, site, jabatan, rekening) — dasar modul absensi'],
        'jabatan' => ['Master Jabatan', 'jabatan.index', ['jabatan.*'], 'Daftar jabatan karyawan: tambah, ganti nama, hapus (jabatan Data Karyawan dipilih dari sini)'],
        'sparepart' => ['Database Sparepart', 'sparepart.index', ['sparepart.*'], 'Cari sparepart berdasarkan nama baku / istilah lapangan / unit: harga terakhir, termurah & per tipe unit, supplier, dan histori harga tiap item (dari pembelian Kas Harian & Kas UJ)'],
        'sparepart-ubah' => ['Isi Merk & Ori/KW sparepart', 'sparepart.index', ['sparepart-ubah.*'], 'Mengisi / mengubah Merk, Ori / KW & catatan per item di dialog Database Sparepart'],
        'pengajuan-sparepart' => ['Pengajuan Sparepart', 'pengajuan-sparepart.index', ['pengajuan-sparepart.*'], 'Ajukan daftar sparepart ke manajemen: ketik nama & jenis → usulan item + harga dari Database Sparepart, subtotal & total otomatis; ubah / batalkan selama belum diputus'],
        'pengajuan-sparepart-putus' => ['Setujui / tolak Pengajuan Sparepart', 'pengajuan-sparepart.index', ['pengajuan-sparepart-putus.*'], 'Manajemen menyetujui atau menolak Pengajuan Sparepart (tombol di halaman detail pengajuan)'],
        'pengajuan-sparepart-tandai' => ['Tandai status & foto progres Pengajuan Sparepart', 'pengajuan-sparepart.index', ['pengajuan-sparepart-tandai.*'], 'Menandai tiap sparepart Disetujui / Dibeli / Terpasang / Ditolak dan mengunggah / menghapus foto progres pekerjaan di halaman detail pengajuan (owner 17 Nov 2026: sementara hanya Admin Plant)'],
        'supplier' => ['Master Supplier', 'supplier.index', ['supplier.*'], 'Data induk supplier sparepart: kontak, telepon, alamat, barang; rekening dari Daftar Rekening & riwayat pembelian dari Database Sparepart; tambah & ubah'],
        'aset' => ['Data Aset', 'aset.index', ['aset.*'], 'Semua aset MNP (dump truck, alat berat, LV, engkel, …): lihat, tambah & ubah'],
        'mutasi-pt' => ['Mutasi Rekening PT (BCA)', 'mutasi-pt.index', ['mutasi-pt.*'], 'Mutasi rekening PT BCA dari file Drive — hanya dilihat, per bulan & cari keterangan'],
        'mutasi-jago' => ['Mutasi Jago', 'mutasi-jago.index', ['mutasi-jago.*'], 'Unggah PDF riwayat transaksi kantong Jago & cocokkan dengan Kas UJ (kantong Kas MNP hanya untuk akun ber-menu Kas Harian)'],
        'jago-catatan' => ['Edit catatan Mutasi Jago', 'mutasi-jago.index', ['jago-catatan.*'], 'Ubah catatan transaksi di Mutasi Jago yang sudah diunggah (catatan asli bank tetap tersimpan; tombol ✎ di halaman Mutasi Jago)'],
        'kode-gl' => ['Kode GL', 'kas.kode-gl', ['kas.kode-gl'], 'Daftar Kode GL'],
        'daftar-rekening' => ['Daftar Rekening', 'rekening.index', ['rekening.*'], 'Database rekening bersama Kas Harian & Kas UJ: satu nomor rekening = satu nama pemilik (saran di Input Kas & Input UJ); isi pemilik yang belum diketahui'],
    ];

    /** Kelompok menu di sidebar: kunci => [judul, ikon, menu di dalamnya]. */
    public const KATEGORI = [
        'asisten' => ['Asisten AI', '🤖', ['tanya-ai']],
        'dashboard' => ['Dashboard', '📊', ['dashboard-proyek', 'dashboard-biaya-dt', 'dashboard-performa-dt', 'dashboard-bbm', 'dashboard-penggunaan-bbm', 'dashboard-hm-km', 'dashboard-foto-absen', 'dashboard-aktivitas']],
        'proyek' => ['Proyek', '🏗️', ['daftar-proyek', 'kebutuhan-material', 'penawaran-spk', 'anggaran-proyek', 'pilah-transaksi', 'boq-proyek', 'alokasi-overhead', 'komponen-anggaran', 'pengaturan-proyek']],
        'kas' => ['Kas Harian', '🏦', ['input-kas', 'kas-harian', 'validasi-reimburse', 'riwayat-reimburse', 'kas-belum-reimburse', 'rekap', 'mutasi-jago', 'jago-catatan', 'mutasi-pt', 'kode-gl', 'daftar-rekening']],
        'uj' => ['Uang Jalan', '🚚', ['pengajuan-uj', 'ajukan-uj', 'input-uj', 'kas-uj', 'reimburse-uj', 'pengalihan-uj', 'rapikan-uj']],
        'ritasi' => ['Ritasi', '⛰️', ['input-ritasi', 'ritasi', 'buangan-truk', 'monitor-ritasi', 'muatan-kurang', 'mk-ubah-nominal', 'bayar-tanah', 'performa-ritasi', 'potongan-muatan', 'kubikasi-kendaraan']],
        'bbm' => ['BBM & HM', '⛽', ['input-bbm', 'bbm']],
        'karyawan' => ['Karyawan', '👷', ['absen', 'absen-mandor', 'absensi', 'review-absen', 'gaji-mingguan', 'absen-ubah-jam', 'karyawan', 'jabatan', 'lokasi-absen']],
        'plant' => ['Plant & Sparepart', '🔧', ['sparepart', 'sparepart-ubah', 'pengajuan-sparepart', 'pengajuan-sparepart-putus', 'pengajuan-sparepart-tandai', 'supplier']],
        'aset' => ['Aset', '🚛', ['aset']],
    ];

    /** Foto bon dipakai Input Kas dan Input UJ: boleh bila salah satunya boleh. */
    // oauth.* = izin ChatGPT (MCP) membaca data ERP → hanya akun dengan menu Tanya AI.
    private const BERSAMA = ['oauth.izin' => ['tanya-ai'], 'oauth.putuskan' => ['tanya-ai'], 'absen.foto' => ['absen', 'absensi', 'dashboard-foto-absen'], 'lapor.foto' => ['absen', 'absensi', 'dashboard-foto-absen'], 'kas.foto' => ['input-kas', 'kas-harian', 'input-uj', 'kas-uj'], 'kas.foto.destroy' => ['input-kas', 'input-uj']];

    /** Menu yang hanya untuk Super Admin (tidak bisa diberikan ke Admin). */
    public const KHUSUS_SUPER = ['dashboard-aktivitas'];

    /** Hak akses yang diatur per jabatan tetapi tidak tampil di sidebar (dibuka lewat tombol di halaman lain). */
    public const TANPA_SIDEBAR = ['jago-catatan', 'absen-ubah-jam', 'mk-ubah-nominal', 'sparepart-ubah', 'pengajuan-sparepart-putus', 'pengajuan-sparepart-tandai']; // 'ajukan-uj' tampil lagi di sidebar (owner 25 Okt 2026)

    public static function boleh(?User $user, string $menu): bool
    {
        if (! $user) {
            return false;
        }
        // Fitur yang dinonaktifkan owner (mis. Pengajuan UJ, 25 Okt 2026): tertutup untuk semua, termasuk Super Admin.
        if (in_array($menu, Fitur::MENU_PENGAJUAN, true) && ! Fitur::pengajuanUj()) {
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
            $isi = array_values(array_diff($isi, self::TANPA_SIDEBAR));
            $menu = array_filter(array_map(fn ($k) => $boleh[$k] ?? null, array_combine($isi, $isi)));
            if ($menu) {
                $hasil[$kunci] = ['judul' => $judul, 'ikon' => $ikon, 'menu' => $menu];
            }
        }

        return $hasil;
    }
}
