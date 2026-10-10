<?php

namespace App\Support\Mcp;

use App\Http\Controllers\DashboardKasController;
use App\Http\Controllers\DashboardUjController;
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
 * Tool MCP (HANYA BACA) untuk ChatGPT: setiap tool = [deskripsi, skema input, fungsi]. Semua nominal dalam rupiah (bilangan
 * bulat), tanggal YYYY-MM-DD. Tidak ada tool yang mengubah data aplikasi maupun sheet.
 */
class AlatMnp
{
    private const MAKS = 200;

    /** @return array<string, array{judul: string, deskripsi: string, skema: array, jalankan: callable}> */
    public static function daftar(): array
    {
        $tanggal = ['type' => 'string', 'description' => 'Tanggal YYYY-MM-DD'];
        $kata = ['type' => 'string', 'description' => 'Kata kunci (boleh beberapa kata; semuanya harus ada)'];
        $batas = ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAKS, 'description' => 'Jumlah maksimal hasil (bawaan 50)'];
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
                'deskripsi' => 'Data ritasi dump truck (tanggal, jam, No Mobil, galian, tujuan buangan, jenis tanah, No DO, pemilik) plus rekap jumlah rit per truk & per galian.',
                'skema' => $obj(['dari' => $tanggal, 'sampai' => $tanggal, 'no_mobil' => ['type' => 'string'], 'no_do' => ['type' => 'string'],
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
            'search' => [
                'judul' => 'Cari di ERP MNP',
                'deskripsi' => 'Pencarian umum di ERP MNP (Kas Harian, Uang Jalan, Pengajuan UJ). Mengembalikan daftar hasil {id, title, url}; buka isinya dengan tool fetch.',
                'skema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'], 'additionalProperties' => false],
                'jalankan' => fn (array $a) => self::search($a),
            ],
            'fetch' => [
                'judul' => 'Buka data ERP MNP',
                'deskripsi' => 'Ambil isi lengkap satu hasil dari tool search berdasarkan id-nya (mis. kas:30827, uj:UJ-13361, pengajuan:27).',
                'skema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false],
                'jalankan' => fn (array $a) => self::fetch($a),
            ],
        ];
    }

    // ===== Kas Harian =====

    private static function ringkasanKas(): array
    {
        $d = app(DashboardKasController::class)()->getData();
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
        $data = $q->orderByDesc('tanggal')->orderByDesc('baris')->limit(1000)->get();
        $status = StatusReimburse::untuk($data->flatMap(fn ($t) => $t->bon->map(fn ($b) => StatusReimburse::idBon($b, $t)))->all());
        $hasil = $data->map(fn ($t) => self::transferKas($t, $status));
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
            ...($rinci ? ['rincian' => collect($g['rinci'])->take(500)->values()] : []),
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

    // ===== Uang Jalan =====

    private static function ringkasanUj(): array
    {
        $d = app(DashboardUjController::class)()->getData();

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
            'kode' => $p->kode(), 'id' => $p->id, 'tanggal' => $p->tanggal->toDateString(), 'nominal' => (int) $p->nominal,
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
            ->when($a['galian'] ?? null, fn ($q, $v) => $q->where('galian', 'like', "%{$v}%"))
            ->when($a['tujuan'] ?? null, fn ($q, $v) => $q->where('jenis_buangan', 'like', "%{$v}%"));
        $perTruk = (clone $q)->selectRaw('no_lambung, COUNT(*) n')->groupBy('no_lambung')->orderByDesc('n')->pluck('n', 'no_lambung');
        $perGalian = (clone $q)->selectRaw('galian, COUNT(*) n')->groupBy('galian')->orderByDesc('n')->pluck('n', 'galian');
        $data = $q->orderByDesc('tanggal')->orderByDesc('baris')->limit(self::batas($a))->get();

        return ['jumlah_rit' => $perTruk->sum(), 'rit_per_truk' => $perTruk, 'rit_per_galian' => $perGalian, 'ditampilkan' => $data->count(),
            'ritasi' => $data->map(fn ($r) => ['tanggal' => $r->tanggal?->toDateString(), 'jam' => $r->jam, 'no_mobil' => $r->no_lambung, 'plat' => $r->plat,
                'jenis_kendaraan' => $r->jenis_kendaraan, 'galian' => $r->galian, 'tujuan_buangan' => $r->jenis_buangan, 'jenis_tanah' => $r->jenis_tanah,
                'no_do' => $r->no_do, 'pemilik' => $r->pemilik, 'keterangan' => $r->keterangan, 'status_bayar' => $r->status_bayar])];
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

    // ===== search / fetch (format konektor ChatGPT) =====

    private static function search(array $a): array
    {
        $q = trim((string) ($a['query'] ?? ''));
        if ($q === '') {
            return ['results' => []];
        }
        $hasil = [];
        foreach (self::cariKas(['kata' => $q, 'batas' => 10])['transaksi'] as $t) {
            $hasil[] = ['id' => 'kas:'.$t['no_id'], 'title' => "Kas Harian {$t['tanggal']} · ".($t['tujuan'] ?? '-').' · '.($t['keterangan'] ?? '').' · Rp '.number_format($t['keluar'] ?: $t['masuk'], 0, ',', '.'),
                'url' => route('kas.index', ['lembar' => $t['lembar'], 'q' => $t['no_id']])];
        }
        foreach (self::cariUj(['kata' => $q, 'batas' => 10])['transaksi'] as $d) {
            $hasil[] = ['id' => 'uj:'.$d['id_uj'], 'title' => "UJ {$d['id_uj']} {$d['tanggal']} · ".($d['driver'] ?? '').' · '.($d['keterangan'] ?? '').' · Rp '.number_format($d['nominal'], 0, ',', '.'),
                'url' => route('uj.index', ['q' => $d['id_uj']])];
        }
        foreach (self::pengajuanUj(['kata' => $q, 'batas' => 5])['pengajuan'] as $p) {
            $hasil[] = ['id' => 'pengajuan:'.$p['id'], 'title' => "Pengajuan {$p['kode']} {$p['tanggal']} · {$p['status']} · Rp ".number_format($p['nominal'], 0, ',', '.'),
                'url' => route('pengajuan-uj.daftar')];
        }

        return ['results' => $hasil];
    }

    private static function fetch(array $a): array
    {
        [$jenis, $kunci] = array_pad(explode(':', (string) ($a['id'] ?? ''), 2), 2, '');
        $isi = match ($jenis) {
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
            'kas' => route('kas.index', ['q' => $kunci]), 'uj' => route('uj.index', ['q' => $kunci]), default => route('pengajuan-uj.daftar'),
        }, 'metadata' => ['sumber' => 'ERP MNP']];
    }

    // ===== bantuan =====

    private static function batas(array $a): int
    {
        return max(1, min(self::MAKS, (int) ($a['batas'] ?? 50)));
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
