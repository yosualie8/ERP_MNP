<?php

namespace App\Support;

use App\Models\UjDetail;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pemeriksaan transaksi uang jalan sesuai "Standar Aturan Validasi Kas Uang Jalan" (Downloads, 7 Okt 2026).
 * Setiap baris detail yang diinput dibandingkan dengan histori Kas Seabank 30 hari dan baris lain di input yang sama.
 * Hasilnya FLAG = perlu diverifikasi admin (bukan vonis salah); admin wajib menulis konfirmasi untuk baris ber-FLAG.
 * Urutan lapisan: kelengkapan/format → nominal → frekuensi per DO → duplikasi → konsistensi DO/DT/driver/tanggal/keterangan.
 */
class ValidasiUj
{
    public const HARI = 30;

    public const MAKS_UJ_PER_DO = 1500000;

    public const BENCHMARK_UANG_TANAH = 500000;

    private const BULAN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12];

    /**
     * @param  array  $input  hasil UjController::bacaInput (tanggal, nama, detail[...])
     * @param  int|null  $kecualiTransaksi  id uj_transaksi yang sedang diedit (baris lamanya tidak dihitung)
     * @return array<int, array<int, array{kode: string, prioritas: string, pesan: string}>> indeks detail => temuan
     */
    public static function periksa(array $input, ?int $kecualiTransaksi = null, array $kecualiPengajuan = []): array
    {
        $tgl = $input['tanggal'];
        $rentang = [$tgl->copy()->subDays(self::HARI)->toDateString(), $tgl->copy()->addDays(self::HARI)->toDateString()];
        $histori = UjDetail::query()
            ->where('biaya_transfer', false)
            ->whereBetween('tanggal', $rentang)
            ->when($kecualiTransaksi, fn ($q) => $q->where('uj_transaksi_id', '!=', $kecualiTransaksi))
            ->orderBy('tanggal')->orderBy('baris')
            ->get(['id_uj', 'tanggal', 'nama', 'keterangan', 'nominal', 'kategori', 'jenis_kendaraan', 'no_mobil', 'no_do'])
            ->map(fn (UjDetail $d) => self::baris($d->id_uj ?: 'tanpa ID', $d->tanggal, $d->nama, $d->keterangan, (int) $d->nominal, $d->kategori, $d->jenis_kendaraan, $d->no_mobil, $d->no_do));
        // Pengajuan UJ yang masih menunggu realisasi ikut dibandingkan (kecuali detail yang sedang diedit / direalisasikan).
        $histori = $histori->concat(\App\Models\UjPengajuanDetail::query()->where('status', 'menunggu')
            ->whereNotIn('id', $kecualiPengajuan ?: [0])
            ->whereHas('pengajuan', fn ($q) => $q->whereBetween('tanggal', $rentang))
            ->with('pengajuan')->orderBy('id')->get()
            ->map(fn ($d) => self::baris($d->pengajuan->kode().' (diajukan)', $d->pengajuan->tanggal, $d->nama ?: $d->pengajuan->nama, $d->keterangan, (int) $d->nominal,
                $d->kategori, $d->jenis_kendaraan, $d->no_mobil, $d->no_do)));

        // Jenis kendaraan per DT dari seluruh histori (bukan hanya 30 hari).
        $jenisDt = UjDetail::whereNotNull('no_mobil')->whereNotNull('jenis_kendaraan')
            ->selectRaw('no_mobil, jenis_kendaraan, COUNT(*) as n')->groupBy('no_mobil', 'jenis_kendaraan')->get()
            ->groupBy('no_mobil')->map(fn ($g) => $g->sortByDesc('n')->first()->jenis_kendaraan);

        $temuan = [];
        $sebelumnya = collect();
        // Indeks dipertahankan (bisa berlubang bila baris yang belum lengkap tidak ikut diperiksa).
        foreach ($input['detail'] as $i => $d) {
            $nama = $i === 0 ? ($d['nama'] ?: $input['nama']) : $d['nama'];
            $r = self::baris('baris '.($i + 1).' input ini', $tgl, $nama, $d['keterangan'], (int) $d['nominal'], $d['kategori'], $d['jenis_kendaraan'], $d['no_mobil'], $d['no_do']);
            $pembanding = $histori->concat($sebelumnya);
            $temuan[$i] = self::aturan($r, $pembanding, $jenisDt);
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

        return $aset ??= \App\Models\AsetTruk::pluck('status', 'no_lambung')->all();
    }

    private static function aturan(array $r, Collection $lain, Collection $jenisDt): array
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

        // 5 · Nominal Uang Tanah (benchmark)  &  4 · Frekuensi Uang Tanah per DO
        if ($kat === 'ut') {
            if ($r['nominal'] > self::BENCHMARK_UANG_TANAH) {
                $flag('5', 'rendah', 'Uang Tanah '.rp($r['nominal']).' di atas benchmark '.rp(self::BENCHMARK_UANG_TANAH).' — konfirmasi tarif lokasi/rute.');
            }
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
            if ($kat !== 'uj' && in_array($kat, ['ut', 'um', 'pr', 'sp'], true) && ! $utama) {
                $flag('19', 'sedang', 'Belum ditemukan Uang Jalan utama untuk DO '.$r['doAsli'].' dalam '.self::HARI.' hari — transaksi tambahan harus terkait ke UJ/DO dasarnya.');
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
