<?php

namespace App\Support;

use App\Models\UjDetail;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pemeriksaan transaksi uang jalan sesuai "Standar Aturan Validasi Kas Uang Jalan" (Downloads, 7 Okt 2026).
 * Setiap baris detail yang diinput dibandingkan dengan histori Kas Seabank 30 hari dan baris lain di input yang sama
 * (aturan 19 "DO tanpa Uang Jalan": seluruh histori, owner 27 Okt 2026).
 * Hasilnya FLAG = perlu diverifikasi admin (bukan vonis salah); admin wajib menulis konfirmasi untuk baris ber-FLAG.
 * Urutan lapisan: kelengkapan/format → nominal → frekuensi per DO → duplikasi → konsistensi DO/DT/driver/tanggal/keterangan.
 */
class ValidasiUj
{
    public const HARI = 30;

    public const MAKS_UJ_PER_DO = 1500000;

    private const BULAN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12];

    /**
     * @param  array  $input  hasil UjController::bacaInput (tanggal, nama, detail[...])
     * @param  int|null  $kecualiTransaksi  id uj_transaksi yang sedang diedit (baris lamanya tidak dihitung)
     * @return array<int, array<int, array{kode: string, prioritas: string, pesan: string}>> indeks detail => temuan
     */
    /**
     * @param  bool  $tawarkanTautan  Input UJ: baris yang DO & jenis kategorinya sama dengan pengajuan yang masih menunggu tidak
     *                                dianggap dobel, tetapi ditawari "Tautkan ke PUJ-xxxx" (kemungkinan besar itu realisasinya).
     */
    /**
     * @param  bool  $tanyaDuluan  Pengajuan UJ: baris yang DO & jenis kategorinya sama dengan transaksi Kas UJ yang belum tertaut ke
     *                             pengajuan mana pun → tanya "sudah terjadi duluan?" (kode D). Ya = detail.duluan berisi ID UJ itu
     *                             (tertaut, bukan dobel); tanpa jawaban Ya pengajuan ditolak.
     */
    public static function periksa(array $input, ?int $kecualiTransaksi = null, array $kecualiPengajuan = [], bool $tawarkanTautan = false, bool $tanyaDuluan = false): array
    {
        $tgl = $input['tanggal'];
        $rentang = [$tgl->copy()->subDays(self::HARI)->toDateString(), $tgl->copy()->addDays(self::HARI)->toDateString()];
        $histori = UjDetail::query()
            ->where('biaya_transfer', false)
            ->whereBetween('tanggal', $rentang)
            ->when($kecualiTransaksi, fn ($q) => $q->where('uj_transaksi_id', '!=', $kecualiTransaksi))
            ->orderBy('tanggal')->orderBy('baris')
            ->get(['id_uj', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do'])
            ->map(fn (UjDetail $d) => [...self::baris($d->id_uj ?: 'tanpa ID', $d->tanggal, $d->nama, $d->keterangan, (int) $d->nominal, $d->kategori, $d->jenis_kendaraan, $d->no_mobil, $d->no_do), 'id_uj' => $d->id_uj]);
        // UJ yang sudah dialihkan / dikembalikan / dibatalkan (Pengalihan UJ) dikeluarkan dari validasi beserta transaksi DO itu s/d
        // tanggal pengalihannya, supaya DO lama bisa dipakai lagi untuk tujuan baru tanpa dianggap dobel (owner 18 Okt 2026).
        [$idTutup, $doTutup] = self::tertutup();
        $histori = $histori->reject(fn ($x) => isset($idTutup[$x['id_uj']])
            || ($x['do'] !== null && isset($doTutup[$x['do']]) && $x['tanggal'] && $x['tanggal']->lte($doTutup[$x['do']])))->values();
        // ID UJ yang sudah menjadi realisasi suatu pengajuan (tidak bisa ditautkan lagi sebagai "duluan").
        $tertaut = $tanyaDuluan ? \App\Models\UjPengajuanDetail::whereNotNull('id_uj')->pluck('id_uj')->flip()->all() : [];
        $dipakaiDuluan = [];
        // Pengajuan UJ yang masih menunggu realisasi ikut dibandingkan (kecuali detail yang sedang diedit / direalisasikan).
        // (Fitur Pengajuan UJ nonaktif → pengajuan yang masih menunggu tidak dibandingkan.)
        $histori = $histori->concat(! Fitur::pengajuanUj() ? collect() : \App\Models\UjPengajuanDetail::query()->where('status', 'menunggu')
            ->whereNotIn('id', $kecualiPengajuan ?: [0])
            ->whereHas('pengajuan', fn ($q) => $q->whereBetween('tanggal', $rentang))
            ->with(['pengajuan.transfer', 'pengajuan.tanda'])->orderBy('id')->get()
            ->map(fn ($d) => [...self::baris($d->pengajuan->kode().' (diajukan)', $d->pengajuan->tanggal, $d->nama ?: $d->pengajuan->nama, $d->keterangan, (int) $d->nominal,
                $d->kategori, $d->jenis_kendaraan, $d->no_mobil, $d->no_do), 'pengajuan' => $d->id, 'kode' => $d->pengajuan->kode(), 'siap' => $d->pengajuan->siapRealisasi()]));
        // Pengajuan yang dananya dialihkan: DO-nya tetap dihitung sudah dibiayai sesuai pengajuan aslinya — kecuali baris
        // realisasinya di Kas UJ sudah memuat DO & jenis kategori yang sama (supaya tidak terhitung dua kali).
        $histori = $histori->concat(\App\Models\UjPengajuanDetail::query()->where('status', 'dialihkan')
            ->whereHas('pengajuan', fn ($q) => $q->whereBetween('tanggal', $rentang))
            ->with('pengajuan')->orderBy('id')->get()
            ->reject(fn ($d) => self::kunciDo($d->realisasi['no_do'] ?? null) === self::kunciDo($d->no_do)
                && self::jenisKategori($d->realisasi['kategori'] ?? null) === self::jenisKategori($d->kategori))
            ->map(fn ($d) => self::baris($d->pengajuan->kode().' (dialihkan ke '.$d->id_uj.')', $d->pengajuan->tanggal, $d->nama, $d->keterangan, (int) $d->nominal,
                $d->kategori, $d->jenis_kendaraan, $d->no_mobil, $d->no_do)));
        // Pengajuan yang LUNAS DARI SALDO SOPIR (tidak ada transfer → tidak pernah masuk Kas UJ, owner 21 Okt 2026): tetap UJ yang sah
        // untuk DO itu (mis. Uang Tanah DO-nya tidak lagi kena aturan 19 "belum ada UJ utama").
        $histori = $histori->concat(\App\Models\UjPengajuanDetail::query()->where('status', 'terealisasi')->where('jenis_realisasi', 'saldo')
            ->whereNotIn('id', $kecualiPengajuan ?: [0])
            ->whereHas('pengajuan', fn ($q) => $q->whereBetween('tanggal', $rentang))
            ->with('pengajuan')->orderBy('id')->get()
            ->map(fn ($d) => self::baris($d->pengajuan->kode().' (dibayar saldo sopir)', $d->pengajuan->tanggal, $d->nama, $d->keterangan, (int) $d->nominal,
                $d->kategori, $d->jenis_kendaraan, $d->no_mobil, $d->no_do)));
        // Aturan H (owner 23 Okt 2026): pengajuan cukup sekali sehari per DT — DT yang sudah ada di pengajuan lain bertanggal sama
        // (jenis kategori sama, belum dibatalkan) diberi peringatan dan wajib beralasan; tidak ditolak. Pengajuan yang sedang diedit dikecualikan.
        $hariSama = $tanyaDuluan ? \App\Models\UjPengajuanDetail::where('status', '!=', 'batal')->whereNotIn('id', $kecualiPengajuan ?: [0])
            ->whereHas('pengajuan', fn ($q) => $q->whereDate('tanggal', $tgl->toDateString()))->with('pengajuan')->get()
            ->groupBy(fn ($d) => NomorMobil::rapikan($d->no_mobil).'|'.self::jenisKategori($d->kategori)) : collect();

        // Jenis kendaraan per DT dari seluruh histori (bukan hanya 30 hari).
        $jenisDt = UjDetail::whereNotNull('no_mobil')->whereNotNull('jenis_kendaraan')
            ->selectRaw('no_mobil, jenis_kendaraan, COUNT(*) as n')->groupBy('no_mobil', 'jenis_kendaraan')->get()
            ->groupBy('no_mobil')->map(fn ($g) => $g->sortByDesc('n')->first()->jenis_kendaraan);

        // Aturan 19: Uang Jalan = transaksi pertama sebuah DO — dicari di SELURUH histori + semua baris input ini (di atas/bawahnya).
        $barisInput = [];
        foreach ($input['detail'] as $i => $d) {
            $barisInput[$i] = self::baris('baris '.($i + 1).' input ini', $tgl, $i === 0 ? ($d['nama'] ?: $input['nama']) : $d['nama'], $d['keterangan'], (int) $d['nominal'],
                $d['kategori'], $d['jenis_kendaraan'], $d['no_mobil'], $d['no_do']);
        }
        $pembuka = self::ujPerDo($kecualiTransaksi, collect($barisInput)->filter(fn ($r) => $r['do'] !== null && self::ikutDo($r['kategori'])), $tgl);
        foreach ($barisInput as $r) {
            if ($r['jenisKat'] === 'uj' && $r['do'] !== null) {
                $pembuka[$r['do']][] = ['id' => $r['id'], 'tanggal' => $tgl, 'mobil' => $r['mobil'], 'nama' => $r['nama'], 'doAsli' => $r['doAsli']];
            }
        }

        $temuan = [];
        $sebelumnya = collect();
        // Indeks dipertahankan (bisa berlubang bila baris yang belum lengkap tidak ikut diperiksa).
        foreach ($input['detail'] as $i => $d) {
            $r = $barisInput[$i];
            $pembanding = $histori->concat($sebelumnya);
            $tautan = [];
            if ($tawarkanTautan && $r['do'] !== null) {
                // Pengajuan menunggu dengan DO & jenis kategori sama = calon pengajuan yang sedang direalisasikan baris ini:
                // jangan dihitung dobel; tawarkan untuk ditautkan.
                $calon = $histori->filter(fn ($x) => isset($x['pengajuan']) && $x['do'] === $r['do'] && $x['jenisKat'] === $r['jenisKat']);
                if ($calon->isNotEmpty()) {
                    $pembanding = $pembanding->reject(fn ($x) => isset($x['pengajuan']) && $calon->contains('pengajuan', $x['pengajuan']));
                    foreach ($calon as $c) {
                        // Owner 23 Okt 2026: pengajuan yang belum ditransfer / ditandai transfer tidak bisa direalisasikan — tanpa tombol Tautkan.
                        if (! $c['siap']) {
                            $tautan[] = ['kode' => 'P', 'prioritas' => 'tinggi',
                                'pesan' => "DO {$r['doAsli']} sudah diajukan di {$c['kode']} ({$c['tanggal']?->translatedFormat('j M')}, {$c['nama']}, {$c['kategori']} ".rp($c['nominal'])
                                    .'), tetapi pengajuan itu BELUM ditransfer dari Kas Harian dan belum ditandai transfer, jadi belum bisa direalisasikan.'
                                    .' Tunggu sampai dananya ditransfer; bila ini memang transaksi terpisah, tulis konfirmasi.'];

                            continue;
                        }
                        $tautan[] = ['kode' => 'P', 'prioritas' => 'tinggi', 'tautan' => ['id' => $c['pengajuan'], 'kode' => $c['kode']],
                            'pesan' => "DO {$r['doAsli']} sudah diajukan di {$c['kode']} ({$c['tanggal']?->translatedFormat('j M')}, {$c['nama']}, {$c['kategori']} ".rp($c['nominal'])
                                .', "'.$c['ket'].'") dan belum direalisasikan. Bila baris ini realisasinya, klik "Tautkan" supaya pengajuan itu tercatat terealisasi — bukan dobel.'
                                .' Bila memang transaksi terpisah, tulis konfirmasi.'];
                    }
                }
            }
            if ($tanyaDuluan && $r['do'] !== null) {
                // Transaksi Kas UJ dengan DO & jenis kategori sama yang belum tertaut ke pengajuan: kemungkinan dibayar duluan.
                $calon = $histori->filter(fn ($x) => ! empty($x['id_uj']) && $x['do'] === $r['do'] && $x['jenisKat'] === $r['jenisKat']
                    && ! isset($tertaut[$x['id_uj']]) && ! isset($dipakaiDuluan[$x['id_uj']]));
                $jawab = $d['duluan'] ?? null;
                if ($jawab && $calon->contains('id_uj', $jawab)) {
                    $dipakaiDuluan[$jawab] = true;
                    $pembanding = $pembanding->reject(fn ($x) => ($x['id_uj'] ?? null) === $jawab);
                } elseif ($calon->isNotEmpty()) {
                    $pembanding = $pembanding->reject(fn ($x) => $calon->contains('id_uj', $x['id_uj'] ?? '-'));
                    foreach ($calon as $c) {
                        $tautan[] = ['kode' => 'D', 'prioritas' => 'tinggi', 'duluan' => [
                            'id_uj' => $c['id_uj'], 'tanggal' => $c['tanggal']?->translatedFormat('j M Y'), 'nama' => $c['nama'], 'kategori' => $c['kategori'],
                            'nominal' => $c['nominal'], 'keterangan' => $c['ket'], 'no_mobil' => $c['mobilAsli'], 'no_do' => $c['doAsli'],
                        ], 'pesan' => "Transaksi untuk DO {$r['doAsli']} sudah diinput di Kas UJ: {$c['id_uj']} ({$c['tanggal']?->translatedFormat('j M Y')}, ".($c['nama'] ?: 'tanpa nama')
                            .", {$c['kategori']} ".rp($c['nominal']).', "'.$c['ket'].'"). Apakah transaksi ini sudah terjadi duluan sebelum pengajuan?'];
                    }
                }
            }
            if ($tanyaDuluan && $r['mobil'] && ($lain = $hariSama[$r['mobil'].'|'.$r['jenisKat']] ?? null)) {
                $kode = $lain->map(fn ($x) => $x->pengajuan->kode())->unique()->implode(', ');
                $tautan[] = ['kode' => 'H', 'prioritas' => 'sedang', 'pesan' => "{$r['mobil']} sudah ada di {$kode} bertanggal sama ({$lain->count()} baris {$r['kategori']}"
                    .(($do = $lain->pluck('no_do')->filter()->unique())->isNotEmpty() ? ', DO '.$do->implode(', ') : '').'). Pengajuan cukup sekali sehari — tambahkan lewat Edit '
                    .$kode.'. Bila memang perlu pengajuan terpisah (mis. tujuan buangan lain), tulis alasannya.'];
            }
            $temuan[$i] = [...$tautan, ...self::aturan($r, $pembanding, $jenisDt, $pembuka), ...($tanyaDuluan ? self::ujTerbuka($r) : [])];
            $sebelumnya->push($r);
        }

        return array_filter($temuan);
    }

    /** Satu baris dalam bentuk yang mudah dibandingkan. */
    private static function baris(string $id, ?CarbonInterface $tanggal, ?string $nama, ?string $ket, int $nominal, ?string $kategori, ?string $jenis, ?string $mobil, ?string $do): array
    {
        $tanggal = $tanggal ? Carbon::parse($tanggal) : null;

        return [
            'id' => $id, 'tanggal' => $tanggal, 'nama' => trim((string) $nama), 'namaK' => self::kunciNama($nama), 'ket' => trim((string) $ket),
            'ketK' => self::kunciKet($ket), 'nominal' => $nominal, 'kategori' => trim((string) $kategori), 'jenisKat' => self::jenisKategori($kategori),
            'jenis' => NomorMobil::rapikanJenis($jenis), 'mobil' => NomorMobil::rapikan($mobil), 'mobilAsli' => trim((string) $mobil),
            'do' => self::kunciDo($do), 'doAsli' => trim((string) $do), 'kejadian' => self::tanggalKejadian($ket, $tanggal),
        ];
    }

    /** @return array<int, array{kode: string, prioritas: string, pesan: string}> */
    /** @return array<string, string> no lambung → status Data Aset (kosong bila Data Aset belum diisi) */
    private static function aset(): array
    {
        static $aset;

        return $aset ??= \App\Models\AsetTruk::truk()->pluck('status', 'no_lambung')->all();
    }

    /** @param  array<string, array<int, array>>  $pembuka  kunci DO → Uang Jalan ber-DO itu (seluruh histori + input ini) */
    private static function aturan(array $r, Collection $lain, Collection $jenisDt, array $pembuka = []): array
    {
        $t = [];
        $flag = function (string $kode, string $prioritas, string $pesan) use (&$t) {
            $t[] = ['kode' => $kode, 'prioritas' => $prioritas, 'pesan' => $pesan];
        };
        $sebut = fn (array $x) => $x['id'].' ('.($x['tanggal']?->translatedFormat('j M') ?? '-').', '.($x['nama'] ?: 'tanpa nama').', '.rp($x['nominal']).')';
        $daftar = fn (Collection $c) => $c->take(3)->map($sebut)->implode('; ').($c->count() > 3 ? ' dan '.($c->count() - 3).' lainnya' : '');
        $kat = $r['jenisKat'];
        $terkaitTruk = in_array($kat, ['uj', 'ut', 'um', 'pr', 'sp'], true);
        $samaDo = $r['do'] !== null ? $lain->where('do', $r['do']) : collect();

        // 17 · Kelengkapan & format data master
        if ($terkaitTruk && $r['do'] === null && $kat !== 'um' && $kat !== 'sp') {
            $flag('17', 'sedang', 'No DO kosong untuk kategori '.$r['kategori'].' — transaksi tidak bisa ditelusuri ke DO/UJ utamanya.');
        }
        if ($terkaitTruk && $r['mobil'] === null) {
            $flag('17', 'sedang', 'No Mobil (DT) kosong untuk kategori '.$r['kategori'].'.');
        }
        if ($r['mobil'] !== null && ! preg_match('/^DT \d{3}$/', $r['mobil']) && ! preg_match('/^LV\b/', $r['mobil'])) {
            $flag('17', 'sedang', "Format No Mobil \"{$r['mobilAsli']}\" tidak normal (seharusnya DT + 3 digit, mis. DT 061).");
        } elseif ($r['mobil'] !== null && self::aset()) {
            // Data Aset = daftar truk MNP (no lambung → status).
            $status = self::aset()[$r['mobil']] ?? null;
            if ($status === null) {
                $flag('17', 'tinggi', "{$r['mobil']} tidak terdaftar di Data Aset (truk MNP) — periksa nomornya, atau daftarkan truknya dulu di menu Data Aset.");
            } elseif ($status === 'dijual') {
                $flag('17', 'tinggi', "{$r['mobil']} berstatus Dijual / keluar di Data Aset.");
            }
        }
        if ($r['doAsli'] !== '' && ! preg_match('/^\d+$/', $r['doAsli'])) {
            $flag('17', 'rendah', "Format No DO \"{$r['doAsli']}\" tidak normal (seharusnya angka saja).");
        }

        // 21 · Uang MOB = pergeseran DT tanpa muatan → tidak ada bongkaran, jadi tidak memakai No DO (owner 27 Okt 2026).
        if ($r['do'] !== null && self::mob($r['kategori'])) {
            $flag('21', 'sedang', 'Uang MOB adalah pergeseran DT tanpa muatan, jadi tidak memakai No DO. Kosongkan No DO '.$r['doAsli']
                .' — DO baru dipakai mulai Uang Jalan rit berikutnya.');
        }

        // 3 · Total Uang Jalan per DO  &  2 · Frekuensi Uang Jalan per DO
        if ($kat === 'uj' && $r['do'] !== null) {
            $uj = $samaDo->where('jenisKat', 'uj');
            $total = $uj->sum('nominal') + $r['nominal'];
            if ($total > self::MAKS_UJ_PER_DO) {
                $flag('3', 'tinggi', 'Total Uang Jalan DO '.$r['doAsli'].' menjadi '.rp($total).' (> '.rp(self::MAKS_UJ_PER_DO).').'.($uj->isNotEmpty() ? ' Sudah ada: '.$daftar($uj).'.' : ''));
            }
            if ($uj->isNotEmpty()) {
                $kurang = str_contains(strtolower($r['ket']), 'kekurangan');
                $flag('2', $kurang ? 'sedang' : 'tinggi', 'Uang Jalan ke-'.($uj->count() + 1).' untuk DO '.$r['doAsli'].' (sudah ada: '.$daftar($uj).').'
                    .($kurang ? '' : ' Untuk UJ kedua dan seterusnya, keterangan wajib memuat kata "Kekurangan".'));
            }
        }

        // 4 · Frekuensi Uang Tanah per DO. (Aturan 5 — batas nominal Uang Tanah — dilepas owner 25 Okt 2026: tarif tanah beragam.)
        if ($kat === 'ut') {
            $ut = $samaDo->where('jenisKat', 'ut');
            if ($r['do'] !== null && $ut->isNotEmpty()) {
                $flag('4', 'tinggi', 'Uang Tanah ke-'.($ut->count() + 1).' untuk DO '.$r['doAsli'].' (normalnya 1×). Sudah ada: '.$daftar($ut).'.');
            }
        }

        // 7 · Perangsang berulang untuk DO yang sama
        if ($kat === 'pr' && $r['do'] !== null && ($pr = $samaDo->where('jenisKat', 'pr'))->isNotEmpty()) {
            $flag('7', 'tinggi', 'Perangsang berulang untuk DO '.$r['doAsli'].' (normalnya 1×). Sudah ada: '.$daftar($pr).'.');
        }

        // 6 · Uang Makan: tanggal kejadian + DO/DT sama + alasan serupa
        if ($kat === 'um' && $r['kejadian']) {
            $um = $lain->where('jenisKat', 'um')->filter(fn ($x) => $x['kejadian']?->isSameDay($r['kejadian'])
                && (($r['do'] && $x['do'] === $r['do']) || ($r['mobil'] && $x['mobil'] === $r['mobil']) || ($r['namaK'] && $x['namaK'] === $r['namaK'])));
            if ($um->isNotEmpty()) {
                $flag('6', 'tinggi', 'Potensi duplikasi Uang Makan tanggal '.$r['kejadian']->translatedFormat('j M').' untuk DO/DT/driver yang sama: '.$daftar($um).'.');
            }
        }

        // 8 & 9 · Tambal ban / perbaikan / sparepart yang sama ditagihkan lagi
        if ($kat === 'sp' && $r['ketK'] !== '') {
            $sp = $lain->where('jenisKat', 'sp')->filter(fn ($x) => $x['ketK'] === $r['ketK']
                && (($r['mobil'] && $x['mobil'] === $r['mobil']) || ($r['do'] && $x['do'] === $r['do'])));
            if ($sp->isNotEmpty()) {
                $flag('8', 'sedang', 'Pekerjaan/sparepart yang sama ("'.$r['ket'].'") untuk '.($r['mobil'] ?: 'DO '.$r['doAsli']).' sudah pernah ditagihkan: '.$daftar($sp)
                    .'. Pastikan pekerjaan/ban memang berbeda.');
            }
        }

        // 18 · Duplikasi identik (DO + DT + driver + kategori + nominal + tanggal kejadian + keterangan)
        $kembar = $lain->filter(fn ($x) => $x['do'] === $r['do'] && $x['mobil'] === $r['mobil'] && $x['namaK'] === $r['namaK'] && $x['jenisKat'] === $kat
            && $x['nominal'] === $r['nominal'] && $x['ketK'] === $r['ketK'] && (($x['kejadian'] && $r['kejadian']) ? $x['kejadian']->isSameDay($r['kejadian']) : true));
        if ($kembar->isNotEmpty()) {
            $flag('18', 'tinggi', 'Identik dengan transaksi lain (DO, DT, driver, kategori, nominal, keterangan sama): '.$daftar($kembar).' — indikasi double input/double payment.');
        }

        // 10, 11, 14, 19, 20 · Konsistensi terhadap UJ utama DO yang sama
        if ($r['do'] !== null) {
            $utama = $samaDo->where('jenisKat', 'uj')->first();
            if (self::ikutDo($r['kategori']) && ! $utama && empty($pembuka[$r['do']])) {
                $t[] = self::tanpaUangJalan($r, $lain, $pembuka);
            }
            $acuan = $utama ?? $samaDo->first();
            if ($acuan && $r['namaK'] && $acuan['namaK'] && $acuan['namaK'] !== $r['namaK']) {
                $flag('10', 'tinggi', "Driver berbeda untuk DO {$r['doAsli']}: di sini \"{$r['nama']}\", sedangkan ".$sebut($acuan).' atas nama "'.$acuan['nama'].'". Jelaskan bila ada pergantian driver.');
            }
            $dtLain = $samaDo->pluck('mobil')->filter()->unique()->reject(fn ($m) => $m === $r['mobil']);
            if ($r['mobil'] && $dtLain->isNotEmpty()) {
                $flag('11', 'tinggi', "No DT berbeda untuk DO {$r['doAsli']}: di sini {$r['mobil']}, di transaksi lain ".$dtLain->implode(', ').' — indikasi salah tempel DO/kendaraan.');
            }
            if ($kat === 'ut' && $utama && ($lokasi = self::lokasiUangTanah($r['ket'])) && ! str_contains(strtolower($utama['ket']), $lokasi)) {
                $flag('14', 'rendah', "Lokasi Uang Tanah \"{$lokasi}\" tidak ada di keterangan UJ utama DO {$r['doAsli']} (\"{$utama['ket']}\").");
            }
        }

        // 12 · Driver ↔ DT: DT biasanya dipakai driver lain
        if ($r['mobil'] && $r['namaK']) {
            $pakai = $lain->where('mobil', $r['mobil'])->where('namaK', '!=', '')->countBy('namaK');
            if ($pakai->isNotEmpty() && ! $pakai->has($r['namaK'])) {
                $biasa = $lain->where('mobil', $r['mobil'])->firstWhere('namaK', $pakai->sortDesc()->keys()->first());
                $flag('12', 'rendah', "{$r['mobil']} dalam ".self::HARI.' hari terakhir biasanya dipakai '.($biasa['nama'] ?? '-')." ({$pakai->max()}×), bukan {$r['nama']} — konfirmasi pergantian driver.");
            }
        }

        // 13 · DT ↔ jenis kendaraan
        if ($r['mobil'] && $r['jenis'] && ($seharusnya = $jenisDt[$r['mobil']] ?? null) && $seharusnya !== $r['jenis']) {
            $flag('13', 'sedang', "{$r['mobil']} di histori tercatat {$seharusnya}, di sini {$r['jenis']}.");
        }

        // 15 · Tanggal kejadian di keterangan sesudah tanggal transaksi
        if ($r['kejadian'] && $r['tanggal'] && $r['kejadian']->gt($r['tanggal'])) {
            $flag('15', 'sedang', 'Tanggal kejadian di keterangan ('.$r['kejadian']->translatedFormat('j M Y').') sesudah tanggal transaksi ('.$r['tanggal']->translatedFormat('j M Y').').');
        }

        // 16 · Kategori ↔ keterangan
        if (($tebak = self::kategoriDariKeterangan($r['ket'])) && $tebak !== $kat && $kat !== 'lain') {
            $nama = ['uj' => 'Uang Jalan', 'ut' => 'Uang Tanah', 'um' => 'Uang Makan', 'pr' => 'Uang Perangsang', 'sp' => 'Sparepart'][$tebak];
            $flag('16', 'sedang', "Keterangan \"{$r['ket']}\" lebih cocok untuk kategori {$nama}, bukan {$r['kategori']}.");
        }

        return $t;
    }

    /** Kategori yang mengikuti DO uang jalannya (aturan 19). Uang MOB tidak: MOB tidak memakai DO. */
    private static function ikutDo(?string $kategori): bool
    {
        return in_array(self::jenisKategori($kategori), ['ut', 'um', 'pr', 'sp'], true) || (bool) preg_match('/material|geser/i', (string) $kategori);
    }

    private static function mob(?string $kategori): bool
    {
        return (bool) preg_match('/\bmob\b|mobilisasi/i', (string) $kategori);
    }

    /**
     * Uang Jalan per kunci DO (tanpa transaksi yang sedang diedit), hanya yang perlu: DO baris lanjutan yang diinput (seluruh histori)
     * dan Uang Jalan DT-nya ±7 hari (calon saran DO).
     */
    private static function ujPerDo(?int $kecualiTransaksi, Collection $lanjutan, CarbonInterface $tgl): array
    {
        if ($lanjutan->isEmpty()) {
            return [];
        }
        $hasil = [];
        UjDetail::query()->where('biaya_transfer', false)->where('kategori', 'like', '%jalan%')->whereNotNull('no_do')->where('no_do', '!=', '')
            ->when($kecualiTransaksi, fn ($q) => $q->where('uj_transaksi_id', '!=', $kecualiTransaksi))
            ->where(function ($q) use ($lanjutan, $tgl) {
                foreach ($lanjutan->pluck('do')->unique() as $k) {
                    $q->orWhere('no_do', 'like', '%'.$k);
                }
                if ($mobil = $lanjutan->pluck('mobil')->filter()->unique()->values()->all()) {
                    $q->orWhere(fn ($w) => $w->whereIn('no_mobil', $mobil)->whereBetween('tanggal', [$tgl->copy()->subDays(7)->toDateString(), $tgl->copy()->addDays(7)->toDateString()]));
                }
            })
            ->get(['id_uj', 'tanggal', 'nama', 'no_mobil', 'no_do'])
            ->each(function (UjDetail $d) use (&$hasil) {
                if ($k = self::kunciDo(str_replace(['`', "'"], '', (string) $d->no_do))) {
                    $hasil[$k][] = ['id' => $d->id_uj, 'tanggal' => $d->tanggal ? Carbon::parse($d->tanggal) : null, 'mobil' => NomorMobil::rapikan($d->no_mobil),
                        'nama' => trim((string) $d->nama), 'doAsli' => trim(str_replace(['`', "'"], '', (string) $d->no_do))];
                }
            });

        return $hasil;
    }

    /**
     * Aturan 19: DO transaksi lanjutan belum punya Uang Jalan. Uang Jalan DT yang sama ±7 hari dengan nomor mirip (dua angka bersebelahan
     * tertukar / beda satu angka) disarankan sebagai DO yang benar (tombol "Pakai DO"), mis. 15139 → 15193, 15742 ↔ 15472.
     */
    private static function tanpaUangJalan(array $r, Collection $lain, array $pembuka): array
    {
        $kat = strtolower($r['kategori']);
        // Uang Tanah / Perangsang / Uang Material normalnya sekali per DO: DO yang sudah punya kategori itu tidak disarankan.
        $sekali = in_array($r['jenisKat'], ['ut', 'pr'], true) || str_contains($kat, 'material');
        $calon = collect($pembuka)->flatMap(fn ($daftar, $k) => collect($daftar)->map(fn ($x) => [...$x, 'k' => (string) $k]))
            ->filter(fn ($x) => $r['mobil'] && $x['mobil'] === $r['mobil'] && $x['tanggal'] && $r['tanggal'] && abs($x['tanggal']->diffInDays($r['tanggal'], false)) <= 7)
            ->reject(fn ($x) => $sekali && $lain->contains(fn ($y) => $y['do'] === $x['k'] && strtolower($y['kategori']) === $kat))
            ->unique('k')
            ->map(fn ($x) => [...$x, 'mirip' => self::mirip($r['do'], $x['k']), 'jarak' => abs($x['tanggal']->diffInDays($r['tanggal'], false))]);
        $mirip = $calon->whereNotNull('mirip')->sortBy([['mirip', 'asc'], ['jarak', 'asc']])->take(3)->values();
        $sebut = fn ($x) => "DO {$x['doAsli']} ({$x['id']}, ".$x['tanggal']->translatedFormat('j M').($x['nama'] ? ", {$x['nama']}" : '').')';
        $saran = fn (Collection $c) => $c->map(fn ($x) => ['no_do' => $x['doAsli'], 'id' => $x['id']])->all();
        if ($mirip->isNotEmpty()) {
            return ['kode' => '19', 'prioritas' => 'tinggi', 'saran' => $saran($mirip),
                'pesan' => "DO {$r['doAsli']} belum punya Uang Jalan (dicari di seluruh histori). Kemungkinan salah ketik — Uang Jalan {$r['mobil']} dengan nomor mirip: "
                    .$mirip->map($sebut)->implode('; ').'. Bila DO yang benar itu, klik "Pakai DO". Bila justru DO di uang jalan itu yang salah ketik, perbaiki uang jalannya.'];
        }
        $dekat = $calon->sortBy('jarak')->take(3)->values();

        return ['kode' => '19', 'prioritas' => 'sedang', 'saran' => $saran($dekat),
            'pesan' => "DO {$r['doAsli']} belum punya Uang Jalan (dicari di seluruh histori) — transaksi lanjutan harus memakai DO uang jalannya."
                .($dekat->isNotEmpty() ? " Uang Jalan {$r['mobil']} ±7 hari: ".$dekat->map($sebut)->implode('; ').'.' : '')];
    }

    /** 0 = dua angka bersebelahan tertukar, 1 = beda / kurang / lebih satu angka, null = tidak mirip. Hanya DO ≥ 4 angka. */
    private static function mirip(string $a, string $b): ?int
    {
        if ($a === $b || strlen($a) < 4 || strlen($b) < 4 || ! ctype_digit($a.$b) || abs(strlen($a) - strlen($b)) > 1) {
            return null;
        }
        if (strlen($a) === strlen($b)) {
            $beda = array_keys(array_diff_assoc(str_split($a), str_split($b)));
            if (count($beda) === 2 && $beda[1] === $beda[0] + 1 && $a[$beda[0]] === $b[$beda[1]] && $a[$beda[1]] === $b[$beda[0]]) {
                return 0;
            }

            return count($beda) === 1 ? 1 : null;
        }
        [$pendek, $panjang] = strlen($a) < strlen($b) ? [$a, $b] : [$b, $a];
        for ($i = 0; $i < strlen($panjang); $i++) {
            if (substr($panjang, 0, $i).substr($panjang, $i + 1) === $pendek) {
                return 1;
            }
        }

        return null;
    }

    public static function jenisKategori(?string $kategori): string
    {
        $k = strtolower((string) $kategori);

        return match (true) {
            str_contains($k, 'transfer') => 'biaya',
            str_contains($k, 'jalan') => 'uj',
            str_contains($k, 'tanah') => 'ut',
            str_contains($k, 'makan') => 'um',
            str_contains($k, 'perangsang') => 'pr',
            str_contains($k, 'spare') || str_contains($k, 'tambal') || str_contains($k, 'ban') => 'sp',
            default => 'lain',
        };
    }

    private static function kategoriDariKeterangan(string $ket): ?string
    {
        $k = ' '.strtolower($ket).' ';

        return match (true) {
            (bool) preg_match('/\b(tambal|impack|impek|ban|sparepart|kampas|filter|oli|aki|bearing)\b/', $k) => 'sp',
            (bool) preg_match('/\b(um|uang makan)\b/', $k) => 'um',
            (bool) preg_match('/\bperangsang\b/', $k) => 'pr',
            (bool) preg_match('/\b(uang tanah|bayar tanah)\b/', $k) => 'ut',
            (bool) preg_match('/\b(uj \d+ rit|uang jalan)\b/', $k) => 'uj',
            default => null,
        };
    }

    /** "UM tanggal 04 Oktober…", "tgl 3/10" → tanggal kejadian (tahun mengikuti tanggal transaksi). */
    public static function tanggalKejadian(?string $ket, ?CarbonInterface $acuan): ?Carbon
    {
        if (! $acuan || ! preg_match('/\b(?:tanggal|tgl)\.?\s*(\d{1,2})(?:\s*[\/\-]\s*(\d{1,2})|\s+([a-z]{3,}))?/i', (string) $ket, $m)) {
            return null;
        }
        $hari = (int) $m[1];
        $bulan = ! empty($m[2]) ? (int) $m[2] : (! empty($m[3]) ? (self::BULAN[strtolower(substr($m[3], 0, 3))] ?? null) : $acuan->month);
        if (! $bulan || $hari < 1 || $hari > 31 || $bulan > 12) {
            return null;
        }
        $tahun = $acuan->year - ($bulan > $acuan->month + 1 ? 1 : 0);

        return checkdate($bulan, $hari, $tahun) ? Carbon::create($tahun, $bulan, $hari) : null;
    }

    private static function lokasiUangTanah(string $ket): ?string
    {
        return preg_match('/(?:uang|bayar)\s+tanah\s+([a-z][a-z ]{2,})/i', $ket, $m) ? strtolower(trim(preg_split('/\s+(\d|rit|tgl|tanggal)/i', $m[1])[0])) : null;
    }

    /** [id UJ yang ditutup Pengalihan UJ => true, kunci DO-nya => tanggal pengalihan terakhir]. */
    private static function tertutup(): array
    {
        static $hasil;
        if ($hasil !== null) {
            return $hasil;
        }
        $p = \App\Models\UjPengalihan::whereIn('jenis', \App\Models\UjPengalihan::MENUTUP)->whereNotNull('id_uj')->get(['id_uj', 'tanggal'])->keyBy('id_uj');
        $do = [];
        foreach (UjDetail::whereIn('id_uj', $p->keys()->all() ?: ['-'])->get(['id_uj', 'no_do']) as $u) {
            if ($k = self::kunciDo(str_replace(['`', "'"], '', (string) $u->no_do))) {
                $do[$k] = max($do[$k] ?? $p[$u->id_uj]->tanggal, $p[$u->id_uj]->tanggal);
                // kunci tanpa pembersihan (histori memakai DO apa adanya, mis. "`0015")
                $do[self::kunciDo($u->no_do)] = $do[$k];
            }
        }

        return $hasil = [$p->map(fn () => true)->all(), $do];
    }

    /**
     * Pengajuan UJ (Kontrol UJ, owner 18 Okt 2026): mobil yang masih punya Uang Jalan belum bongkar melewati batas wajar
     * proyeknya → peringatan (info, tidak wajib dikonfirmasi); lebih dari 7 hari → wajib diberi alasan. Tidak memblokir.
     *
     * @return array<int, array{kode: string, prioritas: string, pesan: string}>
     */
    private static function ujTerbuka(array $r): array
    {
        if (! $r['mobil'] || ($r['jenisKat'] !== 'uj' && ! in_array($r['kategori'], KontrolUj::KATEGORI, true))) {
            return [];
        }
        $lewat = KontrolUj::lewatMobil($r['mobil'], $r['doAsli']);
        if ($lewat->isEmpty()) {
            return [];
        }
        $merah = $lewat->where('status', 'merah');
        $sebut = fn ($c) => $c->take(4)->map(fn ($d) => 'DO '.$d['do'].' ('.$d['umur'].' hari'.($d['tahap'] ? ', '.$d['tahap'] : '').')')->implode(', ').($c->count() > 4 ? ' dan '.($c->count() - 4).' lainnya' : '');

        return [$merah->isNotEmpty()
            ? ['kode' => 'B', 'prioritas' => 'tinggi', 'pesan' => "{$r['mobil']} masih punya {$lewat->count()} UJ belum bongkar, {$merah->count()} di antaranya lebih dari ".KontrolUj::MERAH.' hari: '.$sebut($merah)
                .'. Tulis alasannya (mis. "surat jalan belum diterima", "truk breakdown") — tindak lanjuti di Monitor Ritasi: input bongkar, alihkan, kembalikan, atau batalkan.']
            : ['kode' => 'B', 'prioritas' => 'info', 'pesan' => "{$r['mobil']} masih punya {$lewat->count()} UJ belum bongkar melewati batas wajar: ".$sebut($lewat).'. Pastikan data bongkarnya segera diinput di Monitor Ritasi.']];
    }

    private static function kunciDo(?string $do): ?string
    {
        $v = preg_replace('/\s+/', '', (string) $do);

        return $v === '' ? null : (ltrim($v, '0') ?: '0');
    }

    private static function kunciNama(?string $nama): string
    {
        return preg_replace('/[^a-z]/', '', strtolower((string) $nama));
    }

    /** Keterangan tanpa tanggal/angka & tanda baca, untuk membandingkan "pekerjaan yang sama". */
    private static function kunciKet(?string $ket): string
    {
        $k = strtolower((string) $ket);
        $k = preg_replace('/\b(tanggal|tgl)\.?\s*\d{1,2}([\/\-]\d{1,2})?(\s+[a-z]+)?/', ' ', $k);
        $k = preg_replace('/[^a-z ]+/', ' ', $k);

        return trim(preg_replace('/\s+/', ' ', $k));
    }
}
