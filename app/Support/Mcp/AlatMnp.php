<?php

namespace App\Support\Mcp;

use App\Models\AsetTruk;
use App\Models\KasRiwayat;
use App\Models\KasTransfer;
use App\Models\Ritasi;
use App\Models\UjDetail;
use App\Models\UjPengajuan;
use App\Support\AktivitasAdmin;
use App\Support\ReimburseKas;
use App\Support\RiwayatReimburse;
use App\Support\StatusReimburse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tool MCP untuk ChatGPT / Claude: setiap tool = [deskripsi, skema input, fungsi]. Semua nominal dalam rupiah (bilangan
 * bulat), tanggal YYYY-MM-DD. Semua tool hanya membaca, KECUALI yang bertanda 'ubah' (update_biaya_report_proyek: menulis
 * biaya ERP ke file Report Proyek di Drive — bawaan pratinjau, menulis hanya untuk Super Admin). Data aplikasi tidak diubah.
 */
class AlatMnp
{
    // owner 28 Okt 2026: dulu 200 baris per tarik
    private const MAKS = 5000;

    /** Pengguna yang sedang memakai MCP (diisi McpController); hasil search ikut disaring menunya. */
    public static ?\App\Models\User $pengguna = null;

    /** Menu aplikasi yang wajib boleh dibuka untuk memakai tool ini (search/fetch: disaring per jenis data). */
    private const MENU = [
        'ringkasan_kas_harian' => 'kas-harian', 'cari_transaksi_kas' => 'kas-harian', 'daftar_belum_reimburse_kas' => 'kas-harian',
        'riwayat_reimburse_kas' => 'riwayat-reimburse', 'rekap_biaya' => 'rekap', 'ringkasan_uj' => 'kas-uj', 'cari_transaksi_uj' => 'kas-uj',
        'pengajuan_uj' => 'pengajuan-uj', 'cari_ritasi' => 'ritasi', 'data_aset_truk' => 'aset', 'aktivitas_admin' => 'dashboard-aktivitas',
        // 21 Okt 2026: rekening PT BCA, saldo sopir, biaya DT, UJ belum bongkar, data sopir
        'ringkasan_rekening_pt' => 'mutasi-pt', 'cari_mutasi_rekening_pt' => 'mutasi-pt', 'saldo_sopir' => 'pengalihan-uj', 'biaya_dt' => 'dashboard-biaya-dt',
        'uj_belum_bongkar' => 'monitor-ritasi', 'data_sopir' => 'karyawan',
        'update_biaya_report_proyek' => 'dashboard-proyek',
        // 16 Nov 2026: Plant — Database Sparepart & Dashboard HM & KM
        'cari_sparepart' => 'sparepart', 'histori_harga_sparepart' => 'sparepart', 'hm_km' => 'dashboard-hm-km', 'data_supplier' => 'supplier',
    ];

    /** Tool tanpa menu sendiri (hasilnya disaring per menu di dalamnya). Tool baru yang lupa diberi menu tidak tampil untuk selain Super Admin. */
    private const UMUM = ['search', 'fetch'];

    /** Tool khusus Super Admin (akses baca seluruh database, owner 27 Okt 2026). */
    private const KHUSUS_SUPER = ['daftar_tabel', 'query_database'];

    /**
     * Tool yang boleh dipakai pengguna ini = mengikuti menu JABATANNYA di Hak Akses Jabatan (owner 16 Nov 2026: tiap orang hanya bisa bertanya
     * sesuai jabatannya). Tanpa menu Tanya AI = tanpa tool sama sekali (token MCP lama ikut tertutup bila menunya dicabut).
     */
    public static function untuk(\App\Models\User $u): array
    {
        if (! $u->bolehMenu('tanya-ai')) {
            return [];
        }

        return array_filter(self::daftar(), fn ($t, $nama) => match (true) {
            in_array($nama, self::KHUSUS_SUPER, true) => $u->isSuperAdmin(),
            isset(self::MENU[$nama]) => $u->bolehMenu(self::MENU[$nama]),
            default => in_array($nama, self::UMUM, true) || $u->isSuperAdmin(),
        } && (empty($t['syarat']) || ($t['syarat'])()), ARRAY_FILTER_USE_BOTH);
    }

    /** Menu data yang boleh ditanyakan akun ini (untuk instruksi AI): [kunci => label]. */
    public static function menuData(\App\Models\User $u): array
    {
        $menu = array_unique([...array_values(self::MENU), 'kas-harian', 'kas-uj', 'pengajuan-uj', 'mutasi-pt', 'pengalihan-uj', 'monitor-ritasi', 'dashboard-biaya-dt']);
        $boleh = array_values(array_filter($menu, fn ($m) => isset(\App\Support\MenuAkses::DAFTAR[$m]) && $u->bolehMenu($m)));

        return $boleh ? array_combine($boleh, array_map(fn ($m) => \App\Support\MenuAkses::DAFTAR[$m][0], $boleh)) : [];
    }

    /** Kalimat batas akses untuk instruksi AI (MCP & Tanya AI). */
    public static function batasAkses(\App\Models\User $u): string
    {
        if ($u->isSuperAdmin()) {
            return 'Akun ini Super Admin: boleh menanyakan semua data.';
        }
        $jabatan = $u->karyawanAktif()?->jabatan ?: '(belum ada jabatan)';
        $menu = self::menuData($u);

        return "Akun ini berjabatan {$jabatan} dan HANYA boleh menanyakan data dari menu: ".($menu ? implode(', ', $menu) : '(tidak ada)').'. '
            .'Pertanyaan di luar menu itu jangan dijawab (juga jangan dari ingatan / perkiraan); katakan aksesnya tidak diberikan untuk jabatan ini '
            .'dan sarankan meminta owner menambah menu di Hak Akses Jabatan.';
    }

    private static function boleh(string $menu): bool
    {
        return self::$pengguna && self::$pengguna->bolehMenu($menu);
    }

    /** @return array<string, array{judul: string, deskripsi: string, skema: array, jalankan: callable}> */
    public static function daftar(): array
    {
        $tanggal = ['type' => 'string', 'description' => 'Tanggal YYYY-MM-DD'];
        $kata = ['type' => 'string', 'description' => 'Kata kunci (boleh beberapa kata; semuanya harus ada)'];
        $batas = ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAKS, 'description' => 'Jumlah maksimal hasil (bawaan 200, maks 5.000)'];
        $status = ['type' => 'string', 'enum' => ['semua', 'sudah', 'belum'], 'description' => 'Status reimburse (bawaan semua)'];
        $obj = fn (array $prop = []) => ['type' => 'object', 'properties' => (object) $prop, 'additionalProperties' => false];

        return [
            'ringkasan_kas_harian' => [
                'judul' => 'Ringkasan Kas Harian',
                'deskripsi' => 'Ringkasan Kas Harian (rekening Bank Jago PT MNP): saldo akhir per bulan, jumlah & nilai transaksi yang BELUM reimburse (per bulan, tertua), '
                    .'kapan data terakhir diinput/disinkron, dan 5 pencatatan reimburse terakhir.',
                'skema' => $obj(),
                'jalankan' => fn (array $a) => self::ringkasanKas(),
            ],
            'cari_transaksi_kas' => [
                'judul' => 'Cari transaksi Kas Harian',
                'deskripsi' => 'Cari transaksi Kas Harian (transfer masuk/keluar beserta transaksi detail/bon: PIC, keterangan, Kode GL, No Mobil, nominal, status reimburse). '
                    .'Kata kunci dicari di keterangan, tujuan, PIC, Kode GL, NO ID & ID transaksi. Urut dari yang terbaru.',
                'skema' => $obj(['dari' => $tanggal, 'sampai' => $tanggal, 'kata' => $kata,
                    'arah' => ['type' => 'string', 'enum' => ['semua', 'masuk', 'keluar']], 'status_reimburse' => $status, 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::cariKas($a),
            ],
            'daftar_belum_reimburse_kas' => [
                'judul' => 'Daftar Kas Harian belum reimburse',
                'deskripsi' => 'Daftar transfer Kas Harian yang detailnya BELUM direimburse (biaya transfer ikut transfer induknya), urut dari yang terlama.',
                'skema' => $obj(['batas' => $batas]),
                'jalankan' => fn (array $a) => self::belumKas($a),
            ],
            'riwayat_reimburse_kas' => [
                'judul' => 'History reimburse Kas Harian',
                'deskripsi' => 'Pencatatan reimburse Kas Harian per tanggal reimburse (siapa, kapan, dari file apa, jumlah & total, per akun). '
                    .'Isi "bulan" (YYYY-MM) untuk satu bulan; isi "tanggal" + sertakan_rincian=true untuk daftar transaksinya. Penyesuaian data lama ditandai.',
                'skema' => $obj(['bulan' => ['type' => 'string', 'description' => 'YYYY-MM (bawaan bulan terbaru)'], 'tanggal' => $tanggal,
                    'sertakan_rincian' => ['type' => 'boolean']]),
                'jalankan' => fn (array $a) => self::riwayatReimburseKas($a),
            ],
            'rekap_biaya' => [
                'judul' => 'Rekap biaya per akun',
                'deskripsi' => 'Rekap pengeluaran Kas Harian per kelompok & akun GL per bulan (dari transaksi detail). Bisa disaring cost center (mis. ASG, JKT, Buhut, Infra PM).',
                'skema' => $obj(['dari_bulan' => ['type' => 'string', 'description' => 'YYYY-MM'], 'sampai_bulan' => ['type' => 'string', 'description' => 'YYYY-MM'],
                    'cost_center' => ['type' => 'string']]),
                'jalankan' => fn (array $a) => self::rekapBiaya($a),
            ],
            'ringkasan_uj' => [
                'judul' => 'Ringkasan Uang Jalan',
                'deskripsi' => 'Ringkasan Kas UJ (uang jalan dump truck, lembar Kas Seabank): jumlah & nilai belum reimburse, kapan terakhir diinput/disinkron, '
                    .'pengajuan UJ yang menunggu realisasi, dan 5 reimburse UJ terakhir.',
                'skema' => $obj(),
                'jalankan' => fn (array $a) => self::ringkasanUj(),
            ],
            'cari_transaksi_uj' => [
                'judul' => 'Cari transaksi Uang Jalan',
                'deskripsi' => 'Cari baris transaksi uang jalan (ID UJ, tanggal, driver, keterangan, kategori, No Mobil, No DO, nominal, status & tanggal reimburse, penerima transfer). Urut terbaru.',
                'skema' => $obj(['dari' => $tanggal, 'sampai' => $tanggal, 'kata' => $kata, 'no_mobil' => ['type' => 'string'], 'no_do' => ['type' => 'string'],
                    'kategori' => ['type' => 'string'], 'status_reimburse' => $status, 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::cariUj($a),
            ],
            'pengajuan_uj' => [
                'judul' => 'Pengajuan Uang Jalan',
                'deskripsi' => 'Daftar pengajuan uang jalan (PUJ) beserta detail, status realisasi, dan transfer Kas Harian yang membiayainya.',
                'skema' => $obj(['status' => ['type' => 'string', 'enum' => ['semua', 'diajukan', 'sebagian', 'selesai', 'batal']], 'dari' => $tanggal, 'sampai' => $tanggal,
                    'kata' => $kata, 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::pengajuanUj($a),
            ],
            'cari_ritasi' => [
                'judul' => 'Ritasi dump truck',
                'deskripsi' => 'Data ritasi dump truck — SEMUA kolom tabel ritasi: tanggal, jam, tahap (proyek tujuan), no_seri (No Surat Jalan), plat, no_lambung (No Mobil), no_polisi, '
                    .'galian, jenis_buangan, jenis_tanah, jenis_kendaraan, pemilik, harga_jual, kubikasi, keterangan, status_bayar, no_do, baris sheet — plus rekap jumlah rit per truk & per galian.',
                'skema' => $obj(['dari' => $tanggal, 'sampai' => $tanggal, 'no_mobil' => ['type' => 'string'], 'no_do' => ['type' => 'string'],
                    'no_seri' => ['type' => 'string', 'description' => 'No Seri / No Surat Jalan (sebagian boleh)'], 'tahap' => ['type' => 'string', 'description' => 'Tahap / proyek tujuan (sebagian boleh)'],
                    'galian' => ['type' => 'string'], 'tujuan' => ['type' => 'string'], 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::ritasi($a),
            ],
            'data_aset_truk' => [
                'judul' => 'Data aset truk',
                'deskripsi' => 'Daftar truk milik PT MNP: No Mobil (lambung), plat, jenis, tahun, status, driver tetap, masa berlaku STNK & KIR.',
                'skema' => $obj(['status' => ['type' => 'string', 'description' => 'mis. aktif / dijual; kosong = semua']]),
                'jalankan' => fn (array $a) => self::aset($a),
            ],
            'aktivitas_admin' => [
                'judul' => 'Log aktivitas admin',
                'deskripsi' => 'Log kegiatan pengguna di aplikasi (input/edit/hapus kas, UJ, ritasi, reimburse, dll.), bisa disaring nama, area (Kas Harian, Uang Jalan, Ritasi, Aset) & tanggal.',
                'skema' => $obj(['dari' => $tanggal, 'sampai' => $tanggal, 'nama' => ['type' => 'string'], 'area' => ['type' => 'string'], 'kata' => $kata, 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::aktivitas($a),
            ],
            'ringkasan_rekening_pt' => [
                'judul' => 'Ringkasan Rekening PT (BCA)',
                'deskripsi' => 'Ringkasan mutasi rekening PT MNP di BCA (rekening utama perusahaan; Kas Harian Jago diisi dari sini lewat reimburse): saldo terakhir, '
                    .'masuk/keluar & saldo akhir per bulan (12 bulan), jumlah transaksi per status penandaan biaya (dihitung / tidak dihitung / belum dicek, sejak 2026), '
                    .'dan biaya yang DIHITUNG per proyek (tahap). Kapan file mutasi terakhir diimpor.',
                'skema' => $obj(),
                'jalankan' => fn (array $a) => self::ringkasanBca(),
            ],
            'cari_mutasi_rekening_pt' => [
                'judul' => 'Cari mutasi Rekening PT (BCA)',
                'deskripsi' => 'Cari baris mutasi rekening PT di BCA (tanggal, keterangan bank, masuk/keluar, jumlah, saldo) beserta status biayanya (dihitung → proyek / tidak dihitung + alasan / '
                    .'belum dicek) dan catatan admin. Kata kunci dicari di keterangan bank & catatan admin. Urut terbaru.',
                'skema' => $obj(['dari' => $tanggal, 'sampai' => $tanggal, 'kata' => $kata, 'arah' => ['type' => 'string', 'enum' => ['semua', 'masuk', 'keluar']],
                    'status_biaya' => ['type' => 'string', 'enum' => ['semua', 'dihitung', 'tidak', 'belum']], 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::cariBca($a),
            ],
            'saldo_sopir' => [
                'judul' => 'Hutang sopir',
                'deskripsi' => 'Hutang sopir (menu Hutang Supir): uang perusahaan di tangan sopir = UJ belum bongkar + saldo di luar DO (UJ yang tidak bongkar dijadikan saldo, kasbon, '
                    .'penyesuaian settle 29 Okt 2026, dikurangi pemakaian). Per sopir: total utang, UJ belum bongkar, dijadikan saldo, terpakai lewat DO lama, dipotong / dipakai, '
                    .'dikembalikan, tidak kembali, saldo di luar DO & umur (hari). Isi "sopir" untuk rincian (UJ belum bongkar, riwayat sejak settle, UJ asal & pemakaian).',
                'skema' => $obj(['sopir' => ['type' => 'string'], 'hanya_ada_saldo' => ['type' => 'boolean', 'description' => 'bawaan true']]),
                'jalankan' => fn (array $a) => self::saldoSopir($a),
            ],
            'biaya_dt' => [
                'judul' => 'Biaya dump truck (DT)',
                'deskripsi' => 'Biaya per dump truck per bulan & kategori (Kas UJ per No Mobil + Kas Harian ber-No Mobil; reimburse UJ di Kas Harian tidak dihitung dobel), '
                    .'jumlah rit & biaya per rit. Isi no_mobil untuk rincian satu truk beserta transaksinya.',
                'skema' => $obj(['dari_bulan' => ['type' => 'string', 'description' => 'YYYY-MM (bawaan 3 bulan terakhir)'], 'sampai_bulan' => ['type' => 'string', 'description' => 'YYYY-MM'],
                    'no_mobil' => ['type' => 'string'], 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::biayaDt($a),
            ],
            'uj_belum_bongkar' => [
                'judul' => 'UJ belum bongkar',
                'deskripsi' => 'DO yang sudah diberi uang jalan (Kas UJ / saldo sopir) tetapi belum ada data bongkarnya di Ritasi: umur (hari), proyek, batas wajar, status wajar / lewat / merah, truk & driver.',
                'skema' => $obj(['status' => ['type' => 'string', 'enum' => ['semua', 'wajar', 'lewat', 'merah']], 'no_mobil' => ['type' => 'string'], 'batas' => $batas]),
                'jalankan' => fn (array $a) => self::ujBelumBongkar($a),
            ],
            'data_sopir' => [
                'judul' => 'Data sopir',
                'deskripsi' => 'Master sopir dump truck (Data Karyawan jabatan Supir): nama baku, nama lain / ejaan di Kas UJ, status aktif / nonaktif, truk yang dibawa, rekening.',
                'skema' => $obj(['status' => ['type' => 'string', 'enum' => ['semua', 'aktif', 'nonaktif']], 'kata' => $kata]),
                'jalankan' => fn (array $a) => self::dataSopir($a),
            ],
            'update_biaya_report_proyek' => [
                'judul' => 'Update biaya Report Proyek',
                'deskripsi' => 'Isi kolom "Nilai" bagian "Proyek Masih Dikerjakan (Biaya yang Sudah Keluar)" di file Report Proyek (laporan tagihan ke Sedayu, mis. '
                    .'"Report Proyek ASG - 20261021.xlsx"; Excel di Google Drive atau Google Sheet) dengan biaya yang sudah tercatat di ERP per tahap = ritasi (rit × harga jual) '
                    .'+ ngepok + solar (liter × tarif BBM) + rental alat (jam HM × tarif) + operasional Kas Harian, TANPA biaya umum. SubTotal & total di bawahnya dihitung ulang; '
                    .'tanggal "Update Ritasi Terakhir" diisi tanggal ritasi terakhir di ERP. Bagian proyek selesai (nilai tagihan) & kolom lain tidak diubah. '
                    .'Bawaan tulis=false = PRATINJAU (nilai lama → baru + rincian per proyek); tunjukkan pratinjaunya ke pengguna dan minta persetujuan sebelum tulis=true '
                    .'(menulis hanya untuk Super Admin; versi lama tetap ada di riwayat versi Drive). Baris yang tahapnya tidak dikenali dibiarkan — isi "pemetaan" '
                    .'{"nama baris di sheet": "nama tahap ERP"} (tersimpan untuk berikutnya); isi "-" untuk sengaja melewati satu baris. '
                    .'Sebutkan ke pengguna baris yang nilai ERP-nya jauh lebih kecil dari nilai lama (biaya mungkin belum tercatat di ERP).',
                'skema' => ['type' => 'object', 'properties' => [
                    'link' => ['type' => 'string', 'description' => 'Link atau ID file di Google Drive / Google Sheets'],
                    'tulis' => ['type' => 'boolean', 'description' => 'true = tulis ke file (bawaan false = pratinjau)'],
                    'pemetaan' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'nama baris di sheet => nama tahap ERP (atau id tahap), atau "-" untuk melewati baris itu'],
                ], 'required' => ['link'], 'additionalProperties' => false],
                'ubah' => true,
                'jalankan' => fn (array $a) => self::reportProyek($a),
            ],
            'cari_sparepart' => [
                'judul' => 'Cari sparepart & harganya',
                'deskripsi' => 'Database Sparepart (menu Plant & Sparepart): daftar harga per item sparepart dari riwayat pembelian Kas Harian + Kas UJ (nama item baku). '
                    .'Per item & satuan: harga terakhir, termurah (+ tanggal & supplier), tertinggi, rata-rata, kali beli, harga terakhir per tipe unit, merk / spesifikasi, '
                    .'supplier. Supplier hanya yang sudah diverifikasi Admin Plant (lainnya kosong = belum diketahui). Kata dicari di nama item, keterangan asli, '
                    .'spesifikasi, unit ("DT 26", "FAW", "PC200"), supplier; statistik dihitung dari pembelian yang cocok saja (mis. "filter solar faw" = harga untuk FAW). '
                    .'Paket = satu nominal untuk beberapa barang (harga bukan harga satu item). Untuk histori lengkap satu item pakai histori_harga_sparepart.',
                'skema' => $obj(['kata' => $kata, 'golongan' => ['type' => 'string', 'description' => 'DT / Alat Berat / LV / Genset / …'], 'dari' => $tanggal,
                    'batas' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'description' => 'Jumlah item maksimal (bawaan 50)']]),
                'jalankan' => fn (array $a) => self::cariSparepart($a),
            ],
            'histori_harga_sparepart' => [
                'judul' => 'Histori harga satu sparepart',
                'deskripsi' => 'Semua pembelian satu item sparepart (nama baku dari cari_sparepart, mis. "Filter Solar") urut terbaru: tanggal, unit & tipe unit, qty, satuan, '
                    .'harga satuan, total, supplier (hanya yang terverifikasi), PIC, keterangan asli, sumber & ref; ditambah paket yang memuat item itu (paket=true, harga satuan '
                    .'tidak berlaku). Bisa disaring tipe unit / no unit / periode.',
                'skema' => ['type' => 'object', 'properties' => ['nama' => ['type' => 'string', 'description' => 'Nama item baku'], 'satuan' => ['type' => 'string'],
                    'unit' => ['type' => 'string', 'description' => 'Tipe / no unit, mis. FAW, DT 026, PC200'], 'dari' => $tanggal, 'sampai' => $tanggal, 'batas' => $batas],
                    'required' => ['nama'], 'additionalProperties' => false],
                'jalankan' => fn (array $a) => self::historiSparepart($a),
            ],
            'data_supplier' => [
                'judul' => 'Master Supplier',
                'deskripsi' => 'Master Supplier sparepart (menu Plant & Sparepart): nama, kontak, telepon, alamat, barang, catatan, status aktif, rekening (dari Daftar Rekening), '
                    .'jumlah & total pembelian, pembelian terakhir dan item yang sering dibeli (dari Database Sparepart, hanya supplier terverifikasi).',
                'skema' => $obj(['kata' => $kata, 'termasuk_nonaktif' => ['type' => 'boolean']]),
                'jalankan' => fn (array $a) => self::dataSupplier($a),
            ],
            'hm_km' => [
                'judul' => 'HM & KM aset',
                'deskripsi' => 'Dashboard HM & KM: angka HM (jam, alat berat & mesin — otomatis dari Data BBM + catatan manual) dan KM (dump truck & kendaraan) per aset: '
                    .'angka terakhir, tanggal & umur catatan (hari), pemakaian bulan dipilih & bulan sebelumnya + rata-rata per hari, jumlah bacaan janggal (turun / lompat). '
                    .'Isi "unit" (no lambung, mis. DT 026) untuk riwayat bacaannya.',
                'skema' => $obj(['unit' => ['type' => 'string'], 'kelompok' => ['type' => 'string', 'enum' => ['semua', 'alat', 'truk', 'lain'], 'description' => 'alat = alat berat (HM), truk = dump truck (KM), lain = kendaraan & aset lain'],
                    'bulan' => ['type' => 'string', 'description' => 'YYYY-MM (bawaan bulan ini)'], 'termasuk_nonaktif' => ['type' => 'boolean']]),
                // Dashboard HM & KM dibangun terpisah — tool tampil otomatis begitu kelasnya terpasang
                'syarat' => fn () => class_exists(\App\Support\MeterAset::class),
                'jalankan' => fn (array $a) => self::hmKm($a),
            ],
            'daftar_tabel' => [
                'judul' => 'Daftar tabel database ERP',
                'deskripsi' => 'Semua tabel database ERP MNP beserta kolom & perkiraan jumlah baris (khusus Super Admin). Isi "tabel" untuk melihat kolom lengkap (tipe, kunci) '
                    .'+ contoh isi terbaru. Pakai sebelum query_database supaya nama tabel/kolomnya tepat. Tabel & kolom rahasia (token, password) tidak ditampilkan.',
                'skema' => $obj(['tabel' => ['type' => 'string', 'description' => 'Nama tabel (opsional)'], 'contoh' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'description' => 'Jumlah contoh baris (bawaan 5)']]),
                'jalankan' => fn (array $a) => DatabaseBaca::tabel($a['tabel'] ?? null, (int) ($a['contoh'] ?? 5)),
            ],
            'query_database' => [
                'judul' => 'Query database ERP (baca saja)',
                'deskripsi' => 'Jalankan SATU query SQL MySQL BACA SAJA (SELECT / WITH / SHOW / DESCRIBE / EXPLAIN) ke seluruh database ERP MNP (khusus Super Admin), untuk data yang '
                    .'tidak tercakup tool lain. Lihat nama tabel & kolom dengan daftar_tabel dulu. Hasil maks 10.000 baris (bawaan 1.000), batas waktu 15 detik; pakai GROUP BY / '
                    .'SUM / COUNT untuk rekap. Semua tabel & kolom terbuka (SELECT * boleh); hanya tabel token login & kolom password/token yang disembunyikan. Nominal rupiah bilangan bulat; tanggal YYYY-MM-DD.',
                'skema' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string', 'description' => 'Query SQL (satu perintah baca)'], 'batas' => ['type' => 'integer', 'minimum' => 1, 'maximum' => DatabaseBaca::MAKS_BARIS]],
                    'required' => ['sql'], 'additionalProperties' => false],
                'jalankan' => fn (array $a) => DatabaseBaca::query((string) $a['sql'], (int) ($a['batas'] ?? 1000)),
            ],
            'search' => [
                'judul' => 'Cari di ERP MNP',
                'deskripsi' => 'Pencarian umum di ERP MNP: Kas Harian (Bank Jago), Uang Jalan, Pengajuan UJ, MUTASI REKENING PT BCA (kata kunci di keterangan bank / catatan; tambahkan kata "BCA" untuk ringkasan rekening), saldo sopir (nama sopir / "saldo sopir"), biaya truk ("DT 041" / "biaya DT"), "belum bongkar", sparepart (nama barang / unit, mis. "filter solar FAW") dan HM / KM aset ("HM DT 026"). Hasil mengikuti menu jabatan akun. Mengembalikan {id, title, url}; buka isinya dengan tool fetch.',
                'skema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'], 'additionalProperties' => false],
                'jalankan' => fn (array $a) => self::search($a),
            ],
            'fetch' => [
                'judul' => 'Buka data ERP MNP',
                'deskripsi' => 'Ambil isi lengkap satu hasil dari tool search berdasarkan id-nya (mis. kas:30827, uj:UJ-13361, pengajuan:27, bca:1234, sopir:Tobing, dt:DT 041, sparepart:Filter Solar|pcs, hm:DT 026, ringkasan:rekening_pt / saldo_sopir / uj_belum_bongkar / biaya_dt / hm_km).',
                'skema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false],
                'jalankan' => fn (array $a) => self::fetch($a),
            ],
        ];
    }

    // ===== Kas Harian =====

    private static function ringkasanKas(): array
    {
        $d = \App\Support\RingkasKas::data();
        $saldo = \App\Models\KasBulan::orderByDesc('bulan')->limit(3)->get()
            ->map(fn ($b) => ['lembar' => $b->lembar, 'bulan' => $b->bulan->format('Y-m'), 'saldo_awal' => (int) $b->saldo_awal, 'masuk' => (int) $b->total_debet,
                'keluar' => (int) $b->total_kredit, 'saldo_akhir' => (int) $b->saldo_akhir]);

        return [
            'saldo_per_bulan' => $saldo,
            'belum_reimburse' => [...collect($d['belum'])->except('per_bulan')->all(), 'per_bulan' => $d['belum']['per_bulan']],
            'menunggu_ditulis_ke_sheet' => $d['antreanSheet'],
            'input_terakhir_lewat_aplikasi' => $d['inputTerakhir'] ? [...$d['inputTerakhir'], 'waktu' => (string) $d['inputTerakhir']['waktu'], 'aksi' => AktivitasAdmin::nama($d['inputTerakhir']['aksi'])] : null,
            'sinkron_terakhir_dari_sheet' => (string) $d['sinkronTerakhir'],
            'tanggal_transaksi_terbaru' => $d['transaksiTerbaru'],
            'reimburse_terakhir' => collect($d['riwayat'])->map(fn ($g) => self::grupReimburse($g, false)),
        ];
    }

    private static function cariKas(array $a): array
    {
        $batas = self::batas($a);
        $kata = self::kata($a['kata'] ?? null);
        $q = KasTransfer::with('bon.kodeGl', 'kasBulan')
            ->when($a['dari'] ?? null, fn ($q, $v) => $q->where('tanggal', '>=', self::tgl($v)))
            ->when($a['sampai'] ?? null, fn ($q, $v) => $q->where('tanggal', '<=', self::tgl($v)))
            ->when(($a['arah'] ?? '') === 'masuk', fn ($q) => $q->where('debet', '>', 0))
            ->when(($a['arah'] ?? '') === 'keluar', fn ($q) => $q->where('kredit', '>', 0));
        foreach ($kata as $k) {
            $q->where(fn ($w) => $w->where('keterangan', 'like', "%{$k}%")->orWhere('nama_tujuan', 'like', "%{$k}%")->orWhere('no_id', $k)
                ->orWhereHas('bon', fn ($b) => $b->where('keterangan', 'like', "%{$k}%")->orWhere('pic', 'like', "%{$k}%")->orWhere('id_transaksi', 'like', "%{$k}%")
                    ->orWhere('no_mobil', 'like', "%{$k}%")->orWhereHas('kodeGl', fn ($g) => $g->where('kode_asli', 'like', "%{$k}%"))));
        }
        // dibaca per 500 transfer (model + detail dibuang tiap putaran) supaya 5.000 baris muat di memori PHP-FPM 128 MB
        $hasil = collect();
        $q->orderByDesc('tanggal')->orderByDesc('baris')->orderByDesc('id');
        for ($dari = 0, $maks = max(1000, $batas); $dari < $maks; $dari += 500) {
            $data = (clone $q)->offset($dari)->limit(min(500, $maks - $dari))->get();
            $status = StatusReimburse::untuk($data->flatMap(fn ($t) => $t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t)))->all());
            $hasil = $hasil->concat($data->map(fn ($t) => self::transferKas($t, $status)));
            if ($data->count() < 500) {
                break;
            }
        }
        unset($data);
        $pilih = $a['status_reimburse'] ?? 'semua';
        if ($pilih !== 'semua') {
            $hasil = $hasil->filter(fn ($t) => $t['keluar'] > 0 && ($pilih === 'sudah' ? $t['status_reimburse'] === 'sudah' : $t['status_reimburse'] !== 'sudah'));
        }

        return ['jumlah_cocok' => $hasil->count(), 'ditampilkan' => min($batas, $hasil->count()), 'total_keluar' => (int) $hasil->sum('keluar'),
            'total_masuk' => (int) $hasil->sum('masuk'), 'transaksi' => $hasil->take($batas)->values()];
    }

    private static function transferKas(KasTransfer $t, array $status): array
    {
        $detail = $t->bon->map(fn ($b) => [
            'id_transaksi' => $id = StatusReimburse::idBon($b, $t), 'pic' => $b->pic, 'keterangan' => $b->keterangan, 'kode_gl' => $b->kodeGl?->kode_asli,
            'no_mobil' => $b->no_mobil, 'nominal' => (int) $b->nominal, 'tanggal_reimburse' => isset($status[$id]) ? ($status[$id]?->toDateString() ?? 'sudah') : null,
        ])->values();
        $sudah = $detail->whereNotNull('tanggal_reimburse')->count();

        return [
            'no_id' => $t->no_id, 'tanggal' => $t->tanggal->toDateString(), 'lembar' => $t->kasBulan?->lembar, 'tujuan' => $t->nama_tujuan, 'bank' => $t->bank_tujuan,
            'keterangan' => $t->keterangan, 'masuk' => (int) $t->debet, 'keluar' => (int) $t->kredit, 'saldo' => (int) $t->saldo,
            'status_reimburse' => $t->debet ? 'uang masuk' : ($detail->isEmpty() ? '-' : ($sudah === $detail->count() ? 'sudah' : ($sudah ? 'sebagian' : 'belum'))),
            'detail' => $detail,
        ];
    }

    private static function belumKas(array $a): array
    {
        $antrean = ReimburseKas::antrean();

        return ['jumlah_transfer' => $antrean->count(), 'jumlah_detail' => $antrean->sum(fn ($t) => count($t['detail'])), 'total' => (int) $antrean->sum('total'),
            'transfer' => $antrean->take(self::batas($a))->map(fn ($t) => collect($t)->only(['no_id', 'tanggal', 'nama', 'bank', 'ket', 'total', 'sebagian', 'detail']))->values()];
    }

    private static function riwayatReimburseKas(array $a): array
    {
        $bulan = $a['bulan'] ?? (isset($a['tanggal']) ? substr(self::tgl($a['tanggal']), 0, 7) : RiwayatReimburse::bulan()->keys()->first());
        $grup = RiwayatReimburse::grup($bulan)->when(isset($a['tanggal']), fn ($g) => $g->filter(fn ($x) => $x['tanggal']?->toDateString() === self::tgl($a['tanggal'])));

        return ['bulan' => $bulan, 'bulan_tersedia' => RiwayatReimburse::bulan()->keys()->take(18)->values(),
            'pencatatan' => $grup->map(fn ($g) => self::grupReimburse($g, (bool) ($a['sertakan_rincian'] ?? false)))->values()];
    }

    private static function grupReimburse(array $g, bool $rinci): array
    {
        return [
            'tanggal_reimburse' => $g['tanggal']?->toDateString(), 'jumlah_transaksi' => $g['jumlah'], 'total' => $g['total'],
            'penyesuaian_data_lama' => $g['penyesuaian'], 'dicatat_oleh' => $g['oleh'], 'dicatat_pada' => $g['dicatat'] ? (string) $g['dicatat'] : null,
            'sumber' => $g['oleh'] ? $g['catatan'] : 'Data awal lembar Sudah Reimburse', 'per_akun' => $g['per_akun'],
            ...($rinci ? ['rincian' => collect($g['rinci'])->take(self::MAKS)->values()] : []),
        ];
    }

    private static function rekapBiaya(array $a): array
    {
        $dari = isset($a['dari_bulan']) ? Carbon::parse($a['dari_bulan'].'-01') : now()->subMonths(2)->startOfMonth();
        $sampai = isset($a['sampai_bulan']) ? Carbon::parse($a['sampai_bulan'].'-01')->endOfMonth() : now()->endOfMonth();
        $baris = DB::table('kas_bon as b')
            ->leftJoin('kode_gl as k', 'k.id', '=', 'b.kode_gl_id')->leftJoin('akun_gl as g', 'g.id', '=', 'k.akun_gl_id')->leftJoin('cost_center as c', 'c.id', '=', 'k.cost_center_id')
            ->whereBetween('b.tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->when($a['cost_center'] ?? null, fn ($q, $v) => $q->where('c.kode', $v))
            ->groupBy('g.kelompok', 'g.nama', DB::raw("DATE_FORMAT(b.tanggal, '%Y-%m')"))
            ->select('g.kelompok', 'g.nama', DB::raw("DATE_FORMAT(b.tanggal, '%Y-%m') as bln"), DB::raw('SUM(b.nominal) as total'), DB::raw('COUNT(*) as n'))->get();
        $hasil = [];
        foreach ($baris as $r) {
            $hasil[$r->kelompok ?? 'Tanpa Kode GL'][$r->nama ?? '(Kode GL kosong)'][$r->bln] = (int) $r->total;
        }

        return ['periode' => [$dari->format('Y-m'), $sampai->format('Y-m')], 'cost_center' => $a['cost_center'] ?? 'semua',
            'total_per_kelompok' => collect($hasil)->map(fn ($akun) => array_sum(array_map('array_sum', $akun))),
            'kelompok_akun_bulan' => $hasil, 'cost_center_tersedia' => DB::table('cost_center')->orderBy('kode')->pluck('kode')];
    }

    // ===== Rekening PT (BCA) =====

    private static function ringkasanBca(): array
    {
        $akhir = \App\Models\MutasiPt::orderByDesc('tanggal')->orderByDesc('baris')->first();
        $bulan = \App\Models\MutasiPt::selectRaw("DATE_FORMAT(tanggal, '%Y-%m') ym, SUM(CASE WHEN dbcr = 'CR' THEN jumlah ELSE 0 END) masuk, "
            ."SUM(CASE WHEN dbcr = 'DB' THEN jumlah ELSE 0 END) keluar, COUNT(*) n, MAX(tanggal) akhir")->groupBy('ym')->orderByDesc('ym')->limit(12)->get();
        $baris = \App\Support\TandaMutasiPt::baris();
        $tahap = \App\Models\ProyekTahap::pluck('nama', 'id');
        $impor = \Illuminate\Support\Facades\Cache::get(\App\Support\ImporMutasiPt::KUNCI);

        return [
            'saldo_terakhir' => $akhir ? ['tanggal' => $akhir->tanggal?->toDateString(), 'saldo' => (int) $akhir->saldo] : null,
            'file_mutasi_diimpor' => $impor ? ['waktu' => $impor['waktu'] ?? null, 'file' => $impor['nama'] ?? null, 'file_diubah' => $impor['diubah'] ?? null] : null,
            'per_bulan' => $bulan->map(fn ($b) => ['bulan' => $b->ym, 'masuk' => (int) $b->masuk, 'keluar' => (int) $b->keluar, 'transaksi' => (int) $b->n,
                'saldo_akhir' => (int) \App\Models\MutasiPt::where('tanggal', $b->akhir)->orderByDesc('baris')->value('saldo')]),
            'penandaan_biaya_sejak_2026' => collect(\App\Support\TandaMutasiPt::STATUS)->map(fn ($label, $s) => ['status' => $label,
                'transaksi' => $baris->where('status', $s)->count(), 'keluar' => (int) $baris->where('status', $s)->filter(fn ($b) => $b->m->dbcr === 'DB')->sum(fn ($b) => $b->m->jumlah)]),
            'biaya_dihitung_per_proyek' => \App\Support\TandaMutasiPt::dihitung()->groupBy(fn ($d) => $tahap[$d->tahap_id ?? $d->pos_id] ?? 'tanpa proyek')
                ->map(fn ($g) => (int) $g->sum('rp'))->sortDesc(),
        ];
    }

    private static function cariBca(array $a): array
    {
        $kata = self::kata($a['kata'] ?? null);
        $tanda = \App\Support\TandaMutasiPt::baris()->keyBy('kunci');
        $tahap = \App\Models\ProyekTahap::pluck('nama', 'id');
        // kata kunci juga dicari di catatan admin (tersimpan terpisah)
        $kunciCatatan = $kata ? $tanda->filter(fn ($b) => $b->tanda?->catatan && collect($kata)->every(fn ($k) => mb_stripos($b->tanda->catatan, $k) !== false))->keys()->flip() : collect();
        $data = \App\Models\MutasiPt::query()
            ->when($a['dari'] ?? null, fn ($q, $v) => $q->where('tanggal', '>=', self::tgl($v)))
            ->when($a['sampai'] ?? null, fn ($q, $v) => $q->where('tanggal', '<=', self::tgl($v)))
            ->when(($a['arah'] ?? '') === 'masuk', fn ($q) => $q->where('dbcr', 'CR'))
            ->when(($a['arah'] ?? '') === 'keluar', fn ($q) => $q->where('dbcr', 'DB'))
            ->orderByDesc('tanggal')->orderByDesc('baris')->limit(10000)->get()
            ->filter(fn ($m) => ! $kata || collect($kata)->every(fn ($k) => mb_stripos((string) $m->keterangan, $k) !== false) || isset($kunciCatatan[\App\Support\TandaMutasiPt::kunci($m)]))
            ->map(function ($m) use ($tanda, $tahap) {
                $t = $tanda[\App\Support\TandaMutasiPt::kunci($m)] ?? null;

                return ['id' => $m->id, 'tanggal' => $m->tanggal?->toDateString(), 'keterangan' => $m->keterangan, 'cabang' => $m->cabang, 'arah' => $m->dbcr === 'CR' ? 'masuk' : 'keluar',
                    'jumlah' => (int) $m->jumlah, 'saldo' => (int) $m->saldo, 'status_biaya' => $t?->status ?? 'sebelum 2026', 'alasan' => $t?->alasan,
                    'proyek' => $t?->tanda?->proyek_tahap_id ? ($tahap[$t->tanda->proyek_tahap_id] ?? null) : null, 'catatan_admin' => $t?->tanda?->catatan];
            });
        $pilih = $a['status_biaya'] ?? 'semua';
        if ($pilih !== 'semua') {
            $data = $data->where('status_biaya', $pilih);
        }

        return ['jumlah_cocok' => $data->count(), 'total_masuk' => (int) $data->where('arah', 'masuk')->sum('jumlah'), 'total_keluar' => (int) $data->where('arah', 'keluar')->sum('jumlah'),
            'ditampilkan' => min(self::batas($a), $data->count()), 'mutasi' => $data->take(self::batas($a))->values()];
    }

    // ===== Saldo sopir, biaya DT, UJ belum bongkar, data sopir =====

    private static function saldoSopir(array $a): array
    {
        // Utang sopir (owner 30 Okt 2026) = UJ belum bongkar + saldo di luar DO; buku di-settle per SaldoSopir::SETTLE.
        $hutang = \App\Support\SaldoSopir::hutang();
        $semua = $hutang->map(fn ($h) => $h['saldo']);
        $tahap = \App\Models\ProyekTahap::pluck('nama', 'id');
        $ringkas = fn ($s) => ['sopir' => $s['sopir'], 'truk' => $s['mobil'], 'total_utang' => (int) $hutang[$s['sopir']]['total'], 'uj_belum_bongkar' => (int) $hutang[$s['sopir']]['belum_rp'],
            'dijadikan_saldo' => (int) $s['alih'], 'jumlah_uj' => $s['n_alih'], 'kasbon' => (int) $s['kasbon'], 'penyesuaian_settle' => (int) $s['settle'], 'terpakai_do_lama' => (int) $s['terpakai_do'],
            'dipotong_atau_dipakai' => (int) $s['dipakai'], 'dikembalikan' => (int) $s['kembali'], 'tidak_kembali' => (int) $s['hangus'], 'saldo_di_luar_do' => (int) $s['saldo'],
            'umur_hari' => $s['umur'], 'status' => $s['status']];
        if ($n = \App\Support\SaldoSopir::kunci($a['sopir'] ?? null)) {
            $s = $semua[$n] ?? $semua->first(fn ($x) => mb_stripos($x['sopir'], (string) $a['sopir']) !== false);
            if (! $s) {
                return ['galat' => "Sopir \"{$a['sopir']}\" tidak punya catatan saldo.", 'sopir_tersedia' => $semua->keys()->values()];
            }

            $riwayat = \App\Support\SaldoSopir::riwayat($s['sopir']);

            return [...$ringkas($s),
                'uj_belum_bongkar_rinci' => $hutang[$s['sopir']]['belum']->map(fn ($u) => ['id_uj' => $u->id_uj, 'tanggal' => $u->tgl?->toDateString(), 'no_do' => $u->do, 'dt' => $u->mobil,
                    'nominal' => (int) $u->rp, 'umur_hari' => $u->umur, 'keterangan' => $u->ket])->values(),
                'riwayat_sejak_settle' => ['tanggal_settle' => \App\Support\SaldoSopir::SETTLE, 'saldo_awal' => (int) $riwayat['awal'],
                    'mutasi' => $riwayat['baris']->map(fn ($b) => ['tanggal' => $b['tgl']->toDateString(), 'jenis' => $b['jenis'], 'id' => $b['id'], 'no_do' => $b['do'], 'galian_ke_buangan' => $b['rute'] ?? null,
                        'nambah' => (int) ($b['masuk'] ?? 0), 'potong' => (int) ($b['keluar'] ?? 0), 'sisa' => (int) $b['sisa']])->values()],
                'uj_dijadikan_saldo' => $s['uj']->map(fn ($x) => ['id_uj' => $x->p->id_uj, 'tanggal' => $x->p->tanggal?->toDateString(), 'no_do' => $x->no_do, 'nominal' => (int) $x->p->nominal,
                    'proyek_asal' => $x->p->dari?->nama, 'do_lama_bongkar' => $x->bongkar ? $x->bongkar->tanggal->toDateString().' di '.($tahap[$x->tahap_bongkar] ?? $x->bongkar->tahap) : null])->values(),
                'kasbon_rinci' => $s['kasbon_rows']->map(fn ($p) => ['tanggal' => $p->tanggal?->toDateString(), 'nominal' => (int) $p->nominal, 'no_id_kas_harian' => $p->kas_no_id, 'keterangan' => $p->keterangan])->values(),
                'pemakaian' => $s['pakai_rows']->map(fn ($p) => ['tanggal' => $p->tanggal?->toDateString(), 'cara' => \App\Models\UjPengalihan::PERUNTUKAN[$p->peruntukan] ?? $p->peruntukan,
                    'nominal' => (int) $p->nominal, 'no_do' => $p->no_do, 'keterangan' => $p->keterangan,
                    'proyek' => ($t = \App\Support\SaldoSopir::tahapPakai($p)) ? ($tahap[$t] ?? null) : null])->values()];
        }
        $daftar = $semua->filter(fn ($s) => ($a['hanya_ada_saldo'] ?? true) === false || $hutang[$s['sopir']]['total'] > 0.5)->sortByDesc(fn ($s) => $hutang[$s['sopir']]['total']);

        return ['total_utang_sopir' => (int) $hutang->sum(fn ($h) => max(0, $h['total'])), 'total_uj_belum_bongkar' => (int) $hutang->sum('belum_rp'),
            'total_saldo_di_luar_do' => (int) $semua->sum(fn ($s) => max(0, $s['saldo'])), 'sopir_berutang' => $hutang->filter(fn ($h) => $h['total'] > 0.5)->count(),
            'merah_lebih_14_hari' => $semua->where('status', 'merah')->count(), 'sopir' => $daftar->map($ringkas)->values()];
    }

    private static function biayaDt(array $a): array
    {
        $dari = isset($a['dari_bulan']) ? Carbon::parse($a['dari_bulan'].'-01') : now()->subMonths(2)->startOfMonth();
        $sampai = isset($a['sampai_bulan']) ? Carbon::parse($a['sampai_bulan'].'-01')->endOfMonth() : now()->endOfMonth();
        $baris = \App\Support\BiayaDt::baris($dari, $sampai);
        $rit = \App\Support\BiayaDt::rit($dari, $sampai);
        $mobil = \App\Support\NomorMobil::rapikan($a['no_mobil'] ?? null);
        if ($mobil) {
            $milik = $baris->where('mobil', $mobil);
            $nRit = array_sum($rit[$mobil] ?? []);

            return ['no_mobil' => $mobil, 'periode' => [$dari->format('Y-m'), $sampai->format('Y-m')], 'total' => (int) $milik->sum('rp'), 'rit' => $nRit,
                'biaya_per_rit' => $nRit ? (int) round($milik->sum('rp') / $nRit) : null,
                'per_kategori_bulan' => $milik->groupBy('kategori')->map(fn ($g) => $g->groupBy('bulan')->map(fn ($x) => (int) $x->sum('rp'))),
                'rit_per_bulan' => $rit[$mobil] ?? [],
                'transaksi' => \App\Support\BiayaDt::transaksi($mobil, $dari, $sampai)->take(self::batas($a))->values()];
        }
        $truk = $baris->groupBy('mobil')->map(fn ($g, $m) => ['no_mobil' => $m, 'total' => (int) $g->sum('rp'), 'rit' => array_sum($rit[$m] ?? []),
            'per_kategori' => $g->groupBy('kategori')->map(fn ($x) => (int) $x->sum('rp'))])->sortByDesc('total')->values();
        $totalRit = array_sum(array_map('array_sum', $rit));

        return ['periode' => [$dari->format('Y-m'), $sampai->format('Y-m')], 'total' => (int) $baris->sum('rp'), 'rit' => $totalRit,
            'biaya_per_rit' => $totalRit ? (int) round($baris->sum('rp') / $totalRit) : null,
            'per_kategori' => $baris->groupBy('kategori')->map(fn ($g) => ['total' => (int) $g->sum('rp'), 'kelompok' => \App\Support\BiayaDt::kelompok($g->first()['kategori'])])->sortByDesc('total'),
            'per_bulan' => $baris->groupBy('bulan')->map(fn ($g) => (int) $g->sum('rp'))->sortKeys(),
            'per_truk' => $truk->take(self::batas($a))];
    }

    private static function ujBelumBongkar(array $a): array
    {
        $semua = \App\Support\KontrolUj::terbuka();
        $mobil = \App\Support\NomorMobil::rapikan($a['no_mobil'] ?? null);
        $pilih = $semua->when(($a['status'] ?? 'semua') !== 'semua', fn ($c) => $c->where('status', $a['status']))
            ->when($mobil, fn ($c) => $c->filter(fn ($d) => in_array($mobil, $d['mobil'], true)))->sortByDesc('umur');

        return ['ringkas' => collect(['merah', 'lewat', 'wajar'])->mapWithKeys(fn ($s) => [$s => ['do' => $semua->where('status', $s)->count(), 'uang_jalan' => (int) $semua->where('status', $s)->sum('uj')]]),
            'jumlah_cocok' => $pilih->count(), 'do' => $pilih->take(self::batas($a))->map(fn ($d) => ['no_do' => $d['do'], 'tanggal_uj' => $d['pertama']?->toDateString(), 'umur_hari' => $d['umur'],
                'proyek' => $d['tahap'], 'batas_hari' => $d['batas'], 'status' => $d['status'], 'truk' => $d['mobil'], 'driver' => $d['driver'], 'uang_jalan' => $d['uj'],
                'id_uj' => collect($d['detail'])->pluck('id_uj')->filter()->values()])->values()];
    }

    private static function dataSopir(array $a): array
    {
        $kata = self::kata($a['kata'] ?? null);
        $sopir = \App\Models\Karyawan::with('alias')->where('jabatan', \App\Support\DaftarSopir::JABATAN)
            ->when(in_array($a['status'] ?? 'semua', ['aktif', 'nonaktif'], true), fn ($q) => $q->where('status', $a['status']))->orderBy('nama')->get()
            ->filter(fn ($k) => ! $kata || collect($kata)->every(fn ($w) => mb_stripos($k->nama.' '.$k->alias->pluck('nama')->implode(' ').' '.$k->no_mobil, $w) !== false));

        return ['jumlah' => $sopir->count(), 'aktif' => $sopir->where('status', 'aktif')->count(), 'sopir' => $sopir->map(fn ($k) => ['nama' => $k->nama, 'status' => $k->status,
            'truk' => $k->no_mobil, 'nama_lain' => $k->alias->pluck('nama')->reject(fn ($n) => mb_strtoupper($n) === mb_strtoupper($k->nama))->values(),
            'bank' => $k->bank, 'no_rekening' => $k->no_rekening, 'nama_rekening' => $k->nama_rekening])->values()];
    }

    // ===== Uang Jalan =====

    // ===== Report Proyek =====

    private static function reportProyek(array $a): array
    {
        $tulis = (bool) ($a['tulis'] ?? false);
        if ($tulis && self::$pengguna && ! self::$pengguna->isSuperAdmin()) {
            throw new InvalidArgumentException('Menulis ke file Report Proyek hanya untuk Super Admin. Pratinjau (tulis=false) tetap bisa.');
        }

        return \App\Support\ReportProyek::jalankan((string) ($a['link'] ?? ''), $tulis, (array) ($a['pemetaan'] ?? []), self::$pengguna?->id);
    }

    private static function ringkasanUj(): array
    {
        $d = \App\Support\RingkasUj::data();

        return [
            'belum_reimburse' => [...collect($d['belum'])->except('per_bulan')->all(), 'per_bulan' => $d['belum']['per_bulan']],
            'input_terakhir_lewat_aplikasi' => $d['inputTerakhir'] ? [...$d['inputTerakhir'], 'waktu' => (string) $d['inputTerakhir']['waktu'], 'aksi' => AktivitasAdmin::nama($d['inputTerakhir']['aksi'])] : null,
            'sinkron_terakhir_dari_sheet' => (string) $d['sinkronTerakhir'],
            'tanggal_transaksi_terbaru' => $d['transaksiTerbaru'],
            'pengajuan_menunggu_realisasi' => ['jumlah' => (int) $d['pengajuanMenunggu']->n, 'total' => (int) $d['pengajuanMenunggu']->s],
            'reimburse_terakhir' => collect($d['riwayat'])->map(fn ($g) => [...$g, 'tanggal' => $g['tanggal']->toDateString(),
                'batch' => collect($g['batch'])->map(fn ($b) => [...$b, 'waktu' => (string) $b['waktu']])]),
        ];
    }

    private static function cariUj(array $a): array
    {
        $q = UjDetail::query()->join('uj_transaksi as t', 't.id', '=', 'uj_detail.uj_transaksi_id')
            ->select('uj_detail.*', 't.nama as penerima', 't.bank as bank_penerima', 't.no_uj', 't.tanggal as tanggal_transfer')
            ->when($a['dari'] ?? null, fn ($q, $v) => $q->where('uj_detail.tanggal', '>=', self::tgl($v)))
            ->when($a['sampai'] ?? null, fn ($q, $v) => $q->where('uj_detail.tanggal', '<=', self::tgl($v)))
            ->when($a['no_mobil'] ?? null, fn ($q, $v) => $q->where('uj_detail.no_mobil', 'like', '%'.\App\Support\NomorMobil::rapikan($v).'%'))
            ->when($a['no_do'] ?? null, fn ($q, $v) => $q->where('uj_detail.no_do', 'like', "%{$v}%"))
            ->when($a['kategori'] ?? null, fn ($q, $v) => $q->where('uj_detail.kategori', 'like', "%{$v}%"))
            ->when(($a['status_reimburse'] ?? '') === 'sudah', fn ($q) => $q->whereNotNull('uj_detail.tanggal_reimburse'))
            ->when(($a['status_reimburse'] ?? '') === 'belum', fn ($q) => $q->whereNull('uj_detail.tanggal_reimburse'));
        foreach (self::kata($a['kata'] ?? null) as $k) {
            $q->where(fn ($w) => $w->where('uj_detail.keterangan', 'like', "%{$k}%")->orWhere('uj_detail.nama', 'like', "%{$k}%")->orWhere('uj_detail.id_uj', 'like', "%{$k}%")
                ->orWhere('t.nama', 'like', "%{$k}%")->orWhere('uj_detail.no_do', 'like', "%{$k}%")->orWhere('uj_detail.no_mobil', 'like', "%{$k}%"));
        }
        $jumlah = (clone $q)->count();
        $total = (int) (clone $q)->sum('uj_detail.nominal');
        $data = $q->orderByDesc('uj_detail.tanggal')->orderByDesc('uj_detail.baris')->limit(self::batas($a))->get();

        return ['jumlah_cocok' => $jumlah, 'total_nominal' => $total, 'ditampilkan' => $data->count(), 'transaksi' => $data->map(fn ($d) => [
            'id_uj' => $d->id_uj, 'tanggal' => $d->tanggal?->toDateString(), 'driver' => $d->nama, 'keterangan' => $d->keterangan, 'kategori' => $d->kategori,
            'jenis_kendaraan' => $d->jenis_kendaraan, 'no_mobil' => $d->no_mobil, 'no_do' => $d->no_do, 'nominal' => (int) $d->nominal, 'biaya_transfer' => (bool) $d->biaya_transfer,
            'status' => $d->status, 'tanggal_reimburse' => $d->tanggal_reimburse?->toDateString(), 'no_uj_transfer' => $d->no_uj, 'penerima_transfer' => $d->penerima,
            'bank_penerima' => $d->bank_penerima,
        ])];
    }

    private static function pengajuanUj(array $a): array
    {
        $q = UjPengajuan::with(['detail', 'transfer', 'user'])
            ->when(($a['status'] ?? 'semua') !== 'semua', fn ($q) => $q->where('status', $a['status']))
            ->when($a['dari'] ?? null, fn ($q, $v) => $q->where('tanggal', '>=', self::tgl($v)))
            ->when($a['sampai'] ?? null, fn ($q, $v) => $q->where('tanggal', '<=', self::tgl($v)));
        foreach (self::kata($a['kata'] ?? null) as $k) {
            $q->whereHas('detail', fn ($w) => $w->where('nama', 'like', "%{$k}%")->orWhere('keterangan', 'like', "%{$k}%")->orWhere('no_mobil', 'like', "%{$k}%")
                ->orWhere('no_do', 'like', "%{$k}%")->orWhere('id_uj', 'like', "%{$k}%"));
        }
        $data = $q->orderByDesc('tanggal')->orderByDesc('id')->limit(self::batas($a))->get();

        return ['ditampilkan' => $data->count(), 'pengajuan' => $data->map(fn ($p) => self::satuPengajuan($p))];
    }

    private static function satuPengajuan(UjPengajuan $p): array
    {
        return [
            'kode' => $p->kode(), 'id' => $p->id, 'tanggal' => $p->tanggal->toDateString(), 'keperluan' => $p->keterangan, 'nominal' => (int) $p->nominal,
            'status' => UjPengajuan::STATUS[$p->status] ?? $p->status, 'diajukan_oleh' => $p->user?->name, 'diajukan_pada' => (string) $p->created_at,
            'detail' => $p->detail->map(fn ($d) => ['driver' => $d->nama, 'keterangan' => $d->keterangan, 'kategori' => $d->kategori, 'no_mobil' => $d->no_mobil,
                'no_do' => $d->no_do, 'nominal' => (int) $d->nominal, 'status' => $d->status, 'id_uj_realisasi' => $d->id_uj, 'jenis_realisasi' => $d->jenis_realisasi, 'alasan' => $d->alasan]),
            'transfer_kas_harian' => $p->transfer->map(fn ($t) => ['id_kas' => $t->idKas(), 'tanggal' => $t->kas_tanggal->toDateString(), 'nominal' => (int) $t->nominal,
                'tujuan' => $t->nama_tujuan, 'keterangan' => $t->keterangan]),
        ];
    }

    // ===== Ritasi, aset, aktivitas =====

    private static function ritasi(array $a): array
    {
        $q = Ritasi::query()
            ->when($a['dari'] ?? null, fn ($q, $v) => $q->where('tanggal', '>=', self::tgl($v)))
            ->when($a['sampai'] ?? null, fn ($q, $v) => $q->where('tanggal', '<=', self::tgl($v)))
            ->when($a['no_mobil'] ?? null, fn ($q, $v) => $q->where('no_lambung', 'like', '%'.\App\Support\NomorMobil::rapikan($v).'%'))
            ->when($a['no_do'] ?? null, fn ($q, $v) => $q->where('no_do', 'like', "%{$v}%"))
            ->when($a['no_seri'] ?? null, fn ($q, $v) => $q->where('no_seri', 'like', "%{$v}%"))
            ->when($a['tahap'] ?? null, fn ($q, $v) => $q->where('tahap', 'like', "%{$v}%"))
            ->when($a['galian'] ?? null, fn ($q, $v) => $q->where('galian', 'like', "%{$v}%"))
            ->when($a['tujuan'] ?? null, fn ($q, $v) => $q->where('jenis_buangan', 'like', "%{$v}%"));
        $perTruk = (clone $q)->selectRaw('no_lambung, COUNT(*) n')->groupBy('no_lambung')->orderByDesc('n')->pluck('n', 'no_lambung');
        $perGalian = (clone $q)->selectRaw('galian, COUNT(*) n')->groupBy('galian')->orderByDesc('n')->pluck('n', 'galian');
        $data = $q->orderByDesc('tanggal')->orderByDesc('baris')->limit(self::batas($a))->get();

        return ['jumlah_rit' => $perTruk->sum(), 'rit_per_truk' => $perTruk, 'rit_per_galian' => $perGalian, 'ditampilkan' => $data->count(),
            // Semua kolom tabel ritasi (owner 27 Okt 2026), + nama lama no_mobil / tujuan_buangan supaya tetap dikenali.
            'ritasi' => $data->map(fn ($r) => [...collect($r->getAttributes())->except(['created_at', 'updated_at'])->all(),
                'tanggal' => $r->tanggal?->toDateString(), 'no_mobil' => $r->no_lambung, 'tujuan_buangan' => $r->jenis_buangan])];
    }

    private static function aset(array $a): array
    {
        $data = AsetTruk::when($a['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderBy('no_lambung')->get();

        return ['jumlah' => $data->count(), 'per_status' => $data->countBy('status'), 'truk' => $data->map(fn ($t) => [
            'no_mobil' => $t->no_lambung, 'plat' => $t->plat, 'jenis' => $t->jenis, 'tahun' => $t->tahun, 'status' => $t->status, 'driver_tetap' => $t->driver_tetap,
            'stnk_berlaku' => $t->stnk_berlaku?->toDateString(), 'kir_berlaku' => $t->kir_berlaku?->toDateString(), 'catatan' => $t->catatan])];
    }

    private static function aktivitas(array $a): array
    {
        $q = KasRiwayat::with('user')->where('aksi', '!=', 'mcp-chatgpt')
            ->when($a['dari'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', self::tgl($v)))
            ->when($a['sampai'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse(self::tgl($v))->addDay()))
            ->when($a['nama'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($a['area'] ?? null, fn ($q, $v) => $q->whereIn('aksi', AktivitasAdmin::aksiArea($v)));
        foreach (self::kata($a['kata'] ?? null) as $k) {
            $q->where('ringkasan', 'like', "%{$k}%");
        }
        $jumlah = (clone $q)->count();
        $data = $q->latest('id')->limit(self::batas($a))->get();

        return ['jumlah_cocok' => $jumlah, 'ditampilkan' => $data->count(), 'log' => $data->map(fn ($r) => [
            'waktu' => (string) $r->created_at, 'pengguna' => $r->user?->name, 'area' => AktivitasAdmin::area($r->aksi), 'aktivitas' => AktivitasAdmin::nama($r->aksi),
            'keterangan' => $r->ringkasan])];
    }

    // ===== Plant: sparepart & HM / KM =====

    private static function cariSparepart(array $a): array
    {
        $q = \App\Support\DaftarSparepart::saringKata(\App\Models\SparepartBeli::query(), $a['kata'] ?? null)
            ->when($a['golongan'] ?? null, fn ($q, $g) => $q->where('golongan', 'like', '%'.$g.'%'))
            ->when($a['dari'] ?? null, fn ($q, $d) => $q->where('tanggal', '>=', self::tgl($d)));
        $baris = $q->orderBy('tanggal')->orderBy('id')->toBase()->get();
        $item = \App\Support\DaftarSparepart::item($baris);
        $batas = max(1, min(300, (int) ($a['batas'] ?? 50)));
        $paket = $baris->where('kategori', \App\Models\SparepartBeli::PAKET)->sortByDesc('tanggal')->take(10);

        return [
            'data_sampai' => \App\Models\SparepartBeli::max('tanggal'), 'jumlah_item' => $item->count(), 'ditampilkan' => min($batas, $item->count()),
            'item' => $item->sortByDesc('n')->take($batas)->values()->map(fn ($x) => [
                'kategori' => $x->kategori, 'nama' => $x->nama, 'satuan' => $x->satuan, 'harga_terakhir' => (int) round($x->akhir->harga_satuan), 'tgl_terakhir' => $x->akhir->tanggal,
                'unit_terakhir' => trim(($x->akhir->tipe_unit ?? '').' '.($x->akhir->no_unit ?? '')) ?: null,
                'supplier_terakhir_terverifikasi' => $x->akhir_sup ? ['supplier' => $x->akhir_sup->supplier, 'tanggal' => $x->akhir_sup->tanggal, 'harga' => (int) round($x->akhir_sup->harga_satuan)] : null,
                'termurah' => ['harga' => (int) round($x->murah->harga_satuan), 'tanggal' => $x->murah->tanggal, 'supplier' => $x->murah->supplier, 'unit' => $x->murah->tipe_unit],
                'tertinggi' => (int) round($x->tinggi), 'rata_rata' => (int) round($x->rata), 'kali_beli' => $x->n, 'total_belanja' => (int) round($x->total),
                'harga_terakhir_per_tipe_unit' => $x->per_unit->map(fn ($u) => ['tipe_unit' => $u['tu'], 'harga' => (int) round($u['harga']), 'kali' => $u['n']])->all(),
                'spesifikasi' => $x->spek->all(), 'supplier_terverifikasi' => $x->supplier->all(),
                // Merk & Ori/KW: isian Admin Plant; *_usulan = true bila hanya tebakan dari keterangan pembelian
                'merk' => $x->merk, 'merk_usulan' => (bool) $x->merk_usul, 'ori_kw' => \App\Models\SparepartItem::KUALITAS[$x->kualitas] ?? null,
                'ori_kw_usulan' => (bool) $x->kualitas_usul, 'catatan_admin_plant' => $x->catatan,
            ])->all(),
            'paket_cocok' => $paket->map(fn ($b) => ['tanggal' => $b->tanggal, 'isi' => $b->nama_item, 'total' => (int) round($b->total), 'unit' => $b->tipe_unit, 'supplier' => $b->supplier, 'keterangan' => $b->keterangan])->values()->all(),
        ];
    }

    private static function dataSupplier(array $a): array
    {
        $kunci = fn ($v) => mb_strtolower(trim((string) $v));
        $q = \App\Models\Supplier::orderBy('nama')->when(empty($a['termasuk_nonaktif']), fn ($q) => $q->where('aktif', true));
        foreach (self::kata($a['kata'] ?? null) as $k) {
            $q->where(fn ($w) => $w->where('nama', 'like', "%{$k}%")->orWhere('kontak', 'like', "%{$k}%")->orWhere('barang', 'like', "%{$k}%")->orWhere('alamat', 'like', "%{$k}%"));
        }
        $sup = $q->get();
        $beli = \App\Models\SparepartBeli::whereIn('supplier', $sup->pluck('nama'))->toBase()->get(['supplier', 'tanggal', 'total', 'nama_item', 'kategori'])->groupBy(fn ($b) => $kunci($b->supplier));
        $rek = \App\Models\Rekening::whereIn('nama', $sup->pluck('nama'))->get()->groupBy(fn ($r) => $kunci($r->nama));

        return ['jumlah' => $sup->count(), 'supplier' => $sup->map(function ($s) use ($beli, $rek, $kunci) {
            $b = $beli[$kunci($s->nama)] ?? collect();

            return ['nama' => $s->nama, 'kontak' => $s->kontak, 'telepon' => $s->telepon, 'alamat' => $s->alamat, 'barang' => $s->barang, 'catatan' => $s->catatan,
                'aktif' => $s->aktif, 'rekening' => ($rek[$kunci($s->nama)] ?? collect())->map(fn ($r) => trim($r->bank.' '.$r->no_rekening))->values()->all(),
                'kali_beli' => $b->count(), 'total_belanja' => (int) round($b->sum('total')), 'pembelian_terakhir' => $b->max('tanggal'),
                'item_sering' => $b->reject(fn ($x) => in_array($x->kategori, \App\Models\SparepartBeli::BUKAN_ITEM, true))->countBy('nama_item')->sortDesc()->take(8)->all()];
        })->all()];
    }

    private static function historiSparepart(array $a): array
    {
        $paket = \App\Models\SparepartBeli::PAKET;
        $nama = trim((string) ($a['nama'] ?? ''));
        $suka = '%'.addcslashes($nama, '%_\\').'%';
        // nama baku persis → item itu + paket yang memuatnya; selain itu cari sebagian nama (tanpa paket)
        $persis = \App\Models\SparepartBeli::where('nama_item', $nama)->exists();
        $rows = \App\Models\SparepartBeli::query()
            ->where(fn ($q) => $persis
                ? $q->where('nama_item', $nama)->orWhere(fn ($q) => $q->where('kategori', $paket)->where('nama_item', 'like', $suka))
                : $q->where('nama_item', 'like', $suka)->where('kategori', '!=', $paket))
            ->when($a['satuan'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('satuan', $s)->orWhere('kategori', $paket)))
            ->when($a['unit'] ?? null, fn ($q, $u) => \App\Support\DaftarSparepart::saringKata($q, $u))
            ->when($a['dari'] ?? null, fn ($q, $d) => $q->where('tanggal', '>=', self::tgl($d)))
            ->when($a['sampai'] ?? null, fn ($q, $d) => $q->where('tanggal', '<=', self::tgl($d)))
            ->orderByDesc('tanggal')->orderByDesc('id')->get()
            ->filter(fn ($b) => $b->kategori !== $paket || in_array($nama, explode(' + ', $b->nama_item), true));
        $item = $rows->where('kategori', '!=', $paket);
        if ($rows->isEmpty()) {
            throw new InvalidArgumentException("Sparepart \"{$nama}\" tidak ditemukan; cari nama bakunya dulu dengan cari_sparepart.");
        }

        return [
            'nama' => $nama, 'jumlah_pembelian' => $item->count(), 'jumlah_paket' => $rows->count() - $item->count(),
            'harga_terakhir' => $item->first() ? (int) round($item->first()->harga_satuan) : null, 'termurah' => $item->count() ? (int) round($item->min('harga_satuan')) : null,
            'tertinggi' => $item->count() ? (int) round($item->max('harga_satuan')) : null, 'rata_rata' => $item->count() ? (int) round($item->avg('harga_satuan')) : null,
            'pembelian' => $rows->take(self::batas($a))->values()->map(fn ($b) => [
                'tanggal' => $b->tanggal->toDateString(), 'paket' => $b->kategori === $paket, 'nama' => $b->nama_item, 'spesifikasi' => $b->spesifikasi,
                'golongan' => $b->golongan, 'tipe_unit' => $b->tipe_unit, 'no_unit' => $b->no_unit, 'qty' => $b->qty, 'satuan' => $b->satuan,
                'harga_satuan' => $b->kategori === $paket ? null : (int) round($b->harga_satuan), 'total' => (int) round($b->total),
                'supplier' => $b->supplier, 'pic' => $b->pic, 'keterangan' => $b->keterangan, 'sumber' => $b->sumber, 'ref' => $b->ref,
            ])->all(),
        ];
    }

    private static function hmKm(array $a): array
    {
        $M = \App\Support\MeterAset::class;
        $bulan = preg_match('/^\d{4}-\d{2}$/', (string) ($a['bulan'] ?? '')) ? $a['bulan'] : now()->format('Y-m');
        $awal = Carbon::parse($bulan.'-01');
        $lalu = $awal->copy()->subMonth();
        $periode = fn ($langkah) => [
            $bulan => $M::pakai($langkah, $awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()),
            $lalu->format('Y-m') => $M::pakai($langkah, $lalu->toDateString(), $lalu->copy()->endOfMonth()->toDateString()),
        ];
        $semua = AsetTruk::orderBy('no_lambung')->get();
        if ($unit = trim((string) ($a['unit'] ?? ''))) {
            $baku = fn ($v) => strtoupper(preg_replace('/[\s\-]+/', '', preg_replace('/(\D)0+(\d)/', '$1$2', (string) $v)));
            $aset = $semua->first(fn ($x) => $baku($x->no_lambung) === $baku($unit) || ($x->plat && $baku($x->plat) === $baku($unit)));
            if (! $aset) {
                throw new InvalidArgumentException("Aset \"{$unit}\" tidak ditemukan di Data Aset.");
            }
            $satuan = \App\Models\AsetMeter::satuan($aset->kategori);
            $langkah = $M::langkah($M::bacaan(collect([$aset]))[$aset->id], $satuan);

            return ['aset' => $aset->no_lambung, 'kategori' => $aset->labelKategori(), 'jenis' => $aset->jenis, 'satuan' => $satuan, 'pemakaian_per_bulan' => $periode($langkah),
                'bacaan_terbaru' => $langkah->reverse()->take(100)->values()->map(fn ($b) => ['tanggal' => $b->tanggal, 'nilai' => $b->nilai, 'selisih' => $b->selisih,
                    'hari' => $b->hari, 'janggal' => $b->janggal, 'reset' => (bool) $b->reset, 'sumber' => $b->sumber === 'bbm' ? 'Data BBM' : 'catatan manual', 'catatan' => $b->catatan])->all()];
        }
        $kel = $M::KELOMPOK;
        $kategori = isset($kel[$a['kelompok'] ?? '']) ? $kel[$a['kelompok']][1] : array_merge(...array_column($kel, 1));
        $aset = $semua->whereIn('kategori', $kategori)->when(empty($a['termasuk_nonaktif']), fn ($c) => $c->whereIn('status', ['aktif', 'perbaikan']))->values();
        $bacaan = $M::bacaan($aset);

        return ['bulan' => $bulan, 'aset' => $aset->map(function ($x) use ($M, $bacaan, $periode) {
            $satuan = \App\Models\AsetMeter::satuan($x->kategori);
            $langkah = $M::langkah($bacaan[$x->id], $satuan);
            $akhir = $langkah->reject(fn ($b) => $b->janggal)->last();

            return ['aset' => $x->no_lambung, 'kategori' => $x->labelKategori(), 'jenis' => $x->jenis, 'status' => $x->status, 'satuan' => $satuan,
                'angka_terakhir' => $akhir?->nilai, 'tanggal_terakhir' => $akhir?->tanggal,
                'umur_hari' => $akhir ? Carbon::parse($akhir->tanggal)->diffInDays(now()->startOfDay()) : null,
                'pemakaian_per_bulan' => $periode($langkah), 'bacaan_janggal' => $langkah->filter(fn ($b) => $b->janggal)->count()];
        })->all()];
    }

    // ===== search / fetch (format konektor ChatGPT) =====

    private static function search(array $a): array
    {
        $q = trim((string) ($a['query'] ?? ''));
        if ($q === '') {
            return ['results' => []];
        }
        $hasil = [];
        foreach (self::boleh('kas-harian') ? self::cariKas(['kata' => $q, 'batas' => 10])['transaksi'] : [] as $t) {
            $hasil[] = ['id' => 'kas:'.$t['no_id'], 'title' => "Kas Harian {$t['tanggal']} · ".($t['tujuan'] ?? '-').' · '.($t['keterangan'] ?? '').' · Rp '.number_format($t['keluar'] ?: $t['masuk'], 0, ',', '.'),
                'url' => route('kas.index', ['lembar' => $t['lembar'], 'q' => $t['no_id']])];
        }
        foreach (self::boleh('kas-uj') ? self::cariUj(['kata' => $q, 'batas' => 10])['transaksi'] : [] as $d) {
            $hasil[] = ['id' => 'uj:'.$d['id_uj'], 'title' => "UJ {$d['id_uj']} {$d['tanggal']} · ".($d['driver'] ?? '').' · '.($d['keterangan'] ?? '').' · Rp '.number_format($d['nominal'], 0, ',', '.'),
                'url' => route('uj.index', ['q' => $d['id_uj']])];
        }
        foreach (self::boleh('pengajuan-uj') ? self::pengajuanUj(['kata' => $q, 'batas' => 5])['pengajuan'] : [] as $p) {
            $hasil[] = ['id' => 'pengajuan:'.$p['id'], 'title' => "Pengajuan {$p['kode']} {$p['tanggal']} · {$p['status']} · Rp ".number_format($p['nominal'], 0, ',', '.'),
                'url' => route('pengajuan-uj.daftar')];
        }
        // 21 Okt 2026: ChatGPT di luar Developer Mode hanya memakai search/fetch → data baru ikut dicari di sini.
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        if (self::boleh('mutasi-pt')) {
            $sebutBca = (bool) preg_match('/\b(bca|rekening ?pt|rekening perusahaan|mutasi|saldo rekening)\b/i', $q);
            $sebelumBca = count($hasil);
            if ($sebutBca) {
                $hasil[] = ['id' => 'ringkasan:rekening_pt', 'title' => 'Ringkasan Rekening PT BCA: saldo terakhir, masuk/keluar per bulan, penandaan biaya per proyek', 'url' => route('mutasi-pt.index')];
            }
            // kalimat bebas ("pembayaran Lancarjaya dari BCA") → buang kata umum; bila tetap kosong, coba kata terpanjang saja
            $kataBca = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(bca|rekening|pt|mutasi|pembayaran|bayar|dibayar|sudah|belum|dari|ke|untuk|yang|apakah|transaksi|transfer|faktur|invoice|tagihan|ada|di|dan|atau)\b/i', ' ', $q)));
            $mutasi = $kataBca !== '' ? self::cariBca(['kata' => $kataBca, 'batas' => 15])['mutasi'] : collect();
            if ($mutasi->isEmpty() && $kataBca !== '' && ($terpanjang = collect(explode(' ', $kataBca))->sortByDesc(fn ($w) => mb_strlen($w))->first()) && mb_strlen($terpanjang) >= 4) {
                $mutasi = self::cariBca(['kata' => $terpanjang, 'batas' => 15])['mutasi'];
            }
            foreach ($mutasi as $m) {
                $hasil[] = ['id' => 'bca:'.$m['id'], 'title' => "Mutasi BCA {$m['tanggal']} · {$m['arah']} ".$rp($m['jumlah']).' · '.mb_strimwidth((string) $m['keterangan'], 0, 90, '…').' · biaya: '.$m['status_biaya'],
                    'url' => route('mutasi-pt.index', ['q' => $kataBca])];
            }
            // kata "BCA" disebut → hasil rekening PT di urutan teratas
            if ($sebutBca) {
                $hasil = [...array_slice($hasil, $sebelumBca), ...array_slice($hasil, 0, $sebelumBca)];
            }
        }
        if (self::boleh('pengalihan-uj')) {
            if (preg_match('/saldo ?sopir|saldo ?supir|hutang sopir|hutang supir/i', $q)) {
                $hasil[] = ['id' => 'ringkasan:saldo_sopir', 'title' => 'Saldo sopir: daftar sopir yang masih memegang saldo UJ', 'url' => route('pengalihan-uj.index', ['tab' => 'saldo'])];
            }
            foreach (\App\Support\SaldoSopir::perSopir() as $s) {
                if (mb_stripos($q, $s['sopir']) !== false || mb_stripos($s['sopir'], $q) !== false) {
                    $hasil[] = ['id' => 'sopir:'.$s['sopir'], 'title' => "Saldo sopir {$s['sopir']} · sisa ".$rp($s['saldo']).($s['umur'] !== null ? " · {$s['umur']} hari" : ''), 'url' => route('pengalihan-uj.index', ['tab' => 'saldo', 'sopir' => $s['sopir']])];
                }
            }
        }
        if (self::boleh('monitor-ritasi') && preg_match('/belum bongkar|tidak bongkar|kontrol uj/i', $q)) {
            $hasil[] = ['id' => 'ringkasan:uj_belum_bongkar', 'title' => 'UJ belum bongkar: DO tanpa data bongkar, umur & status merah/lewat/wajar', 'url' => route('ritasi.monitor')];
        }
        if (self::boleh('dashboard-biaya-dt') && preg_match('/\bDT\s*0?(\d{2,3})\b/i', $q, $mm)) {
            $dt = \App\Support\NomorMobil::rapikan('DT '.$mm[1]);
            $hasil[] = ['id' => 'dt:'.$dt, 'title' => "Biaya {$dt} 3 bulan terakhir per kategori & bulan, rit, biaya per rit", 'url' => route('dashboard.biaya-dt', ['mobil' => $dt])];
        } elseif (self::boleh('dashboard-biaya-dt') && preg_match('/biaya (dt|truk|dump)/i', $q)) {
            $hasil[] = ['id' => 'ringkasan:biaya_dt', 'title' => 'Biaya dump truck 3 bulan terakhir per kategori, bulan & truk', 'url' => route('dashboard.biaya-dt')];
        }

        if (self::boleh('sparepart')) {
            $kataSp = trim(preg_replace('/\b(harga|sparepart|spare part|berapa|beli|terakhir|termurah|supplier|untuk|yang|di|dan)\b/i', ' ', $q));
            foreach ($kataSp !== '' ? self::cariSparepart(['kata' => $kataSp, 'batas' => 8])['item'] : [] as $it) {
                $hasil[] = ['id' => 'sparepart:'.$it['nama'].'|'.$it['satuan'], 'title' => "Sparepart {$it['nama']} · terakhir ".$rp($it['harga_terakhir'])."/{$it['satuan']} ({$it['tgl_terakhir']}) · termurah ".$rp($it['termurah']['harga']),
                    'url' => route('sparepart.index')];
            }
        }
        if (self::boleh('supplier')) {
            $sebutSupplier = (bool) preg_match('/\b(supplier|pemasok|toko sparepart)\b/i', $q);
            foreach (\App\Models\Supplier::orderBy('nama')->get(['nama', 'telepon']) as $s) {
                if ($sebutSupplier || mb_stripos($q, $s->nama) !== false || (mb_strlen($q) >= 4 && mb_stripos($s->nama, $q) !== false)) {
                    $hasil[] = ['id' => 'supplier:'.$s->nama, 'title' => "Supplier {$s->nama}".($s->telepon ? " · {$s->telepon}" : '').' · kontak, rekening, pembelian', 'url' => route('supplier.index')];
                }
            }
        }
        if (self::boleh('dashboard-hm-km') && class_exists(\App\Support\MeterAset::class)) {
            $teks = preg_replace('/\s+/', ' ', $q);
            $unit = AsetTruk::all()->first(fn ($x) => $x->no_lambung && preg_match('/\b'.preg_quote(preg_replace('/\s+/', ' ', $x->no_lambung), '/').'\b/i', $teks));
            if ($unit) {
                $hasil[] = ['id' => 'hm:'.$unit->no_lambung, 'title' => "HM / KM {$unit->no_lambung}: angka terakhir & riwayat bacaan", 'url' => route('dashboard.hm-km')];
            } elseif (preg_match('/\b(hm|km|hour ?meter|kilometer|speedometer)\b/i', $q)) {
                $hasil[] = ['id' => 'ringkasan:hm_km', 'title' => 'HM & KM semua aset: angka terakhir, pemakaian bulan ini & bulan lalu', 'url' => route('dashboard.hm-km')];
            }
        }

        return ['results' => $hasil];
    }

    private static function fetch(array $a): array
    {
        [$jenis, $kunci] = array_pad(explode(':', (string) ($a['id'] ?? ''), 2), 2, '');
        $ringkasan = ['rekening_pt' => 'mutasi-pt', 'saldo_sopir' => 'pengalihan-uj', 'uj_belum_bongkar' => 'monitor-ritasi', 'biaya_dt' => 'dashboard-biaya-dt', 'hm_km' => 'dashboard-hm-km'];
        $menu = ['kas' => 'kas-harian', 'uj' => 'kas-uj', 'pengajuan' => 'pengajuan-uj', 'bca' => 'mutasi-pt', 'sopir' => 'pengalihan-uj', 'dt' => 'dashboard-biaya-dt',
            'sparepart' => 'sparepart', 'hm' => 'dashboard-hm-km', 'supplier' => 'supplier',
            'ringkasan' => $ringkasan[$kunci] ?? 'kas-harian'][$jenis] ?? 'kas-harian';
        if (! self::boleh($menu)) {
            throw new InvalidArgumentException('Akun Anda tidak punya akses ke data ini.');
        }
        $isi = match ($jenis) {
            'bca' => ($m = \App\Models\MutasiPt::find((int) $kunci)) ? (self::cariBca(['dari' => $m->tanggal->toDateString(), 'sampai' => $m->tanggal->toDateString(), 'batas' => 200])['mutasi']
                ->firstWhere('id', $m->id) ?? null) : null,
            'sopir' => self::saldoSopir(['sopir' => $kunci]),
            'dt' => self::biayaDt(['no_mobil' => $kunci, 'batas' => 100]),
            'sparepart' => self::historiSparepart(['nama' => explode('|', $kunci)[0], 'satuan' => explode('|', $kunci)[1] ?? null, 'batas' => 300]),
            'hm' => class_exists(\App\Support\MeterAset::class) ? self::hmKm(['unit' => $kunci]) : null,
            'supplier' => collect(self::dataSupplier(['termasuk_nonaktif' => true])['supplier'])->firstWhere('nama', $kunci),
            'ringkasan' => match ($kunci) {
                'hm_km' => class_exists(\App\Support\MeterAset::class) ? self::hmKm([]) : null,
                'rekening_pt' => self::ringkasanBca(), 'saldo_sopir' => self::saldoSopir([]), 'uj_belum_bongkar' => self::ujBelumBongkar(['batas' => 100]),
                'biaya_dt' => self::biayaDt(['batas' => 50]), default => null,
            },
            'kas' => ($t = KasTransfer::with('bon.kodeGl', 'kasBulan')->where('no_id', (int) $kunci)->first())
                ? self::transferKas($t, StatusReimburse::untuk($t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t))->all())) : null,
            'uj' => self::cariUj(['kata' => $kunci, 'batas' => 5])['transaksi']->firstWhere('id_uj', $kunci),
            'pengajuan' => ($p = UjPengajuan::with(['detail', 'transfer', 'user'])->find((int) $kunci)) ? self::satuPengajuan($p) : null,
            default => null,
        };
        if (! $isi) {
            throw new InvalidArgumentException("Data dengan id \"{$a['id']}\" tidak ditemukan.");
        }

        return ['id' => $a['id'], 'title' => $a['id'], 'text' => json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'url' => match ($jenis) {
            'kas' => route('kas.index', ['q' => $kunci]), 'uj' => route('uj.index', ['q' => $kunci]), 'bca' => route('mutasi-pt.index'), 'sopir' => route('pengalihan-uj.index', ['tab' => 'saldo', 'sopir' => $kunci]),
            'dt' => route('dashboard.biaya-dt', ['mobil' => $kunci]), 'sparepart' => route('sparepart.index'), 'hm' => url('/dashboard/hm-km'), 'supplier' => route('supplier.index'), 'ringkasan' => url('/'), default => route('pengajuan-uj.daftar'),
        }, 'metadata' => ['sumber' => 'ERP MNP']];
    }

    // ===== bantuan =====

    private static function batas(array $a): int
    {
        return max(1, min(self::MAKS, (int) ($a['batas'] ?? 200)));
    }

    private static function tgl(string $v): string
    {
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException("Tanggal \"{$v}\" tidak dikenali; pakai format YYYY-MM-DD.");
        }
    }

    /** @return string[] */
    private static function kata(?string $v): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim((string) $v)) ?: []));
    }
}
