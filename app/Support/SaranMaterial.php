<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Saran harga & satuan untuk menu Kebutuhan Material (owner 18 Nov 2026). Sumber, urut kepercayaan:
 *  (a) harga material yang sudah dipastikan owner di menu ini (kebutuhan_material.dicek),
 *  (b) Database Sparepart (sparepart_beli: qty, satuan, harga satuan sudah rapi),
 *  (c) Kas Harian (kas_bon) & Kas UJ (uj_detail) — keterangan bebas; ±50% menulis "qty satuan nama" (mis. "20 sak Semen 50kg"),
 *      dari situ harga satuan = nominal ÷ qty. Yang tidak menyebut qty tetap ditampilkan sebagai bukti (harga total).
 * Kecocokan nama memakai kata penting: kata pertama nama wajib ada (saringan SQL), sisanya menambah skor; satuan sama menambah skor.
 */
class SaranMaterial
{
    /** variasi tulisan satuan → satuan baku */
    public const SATUAN = [
        'sak' => 'sak', 'zak' => 'sak', 'karung' => 'karung', 'batang' => 'batang', 'btg' => 'batang', 'lonjor' => 'batang', 'lembar' => 'lembar', 'lbr' => 'lembar',
        'm3' => 'm3', 'm³' => 'm3', 'kubik' => 'm3', 'kubikasi' => 'm3', 'm2' => 'm2', 'm²' => 'm2', 'meter' => 'meter', 'mtr' => 'meter', 'm' => 'meter',
        'kg' => 'kg', 'kilo' => 'kg', 'ton' => 'ton', 'rit' => 'rit', 'ritase' => 'rit', 'truk' => 'truk', 'dus' => 'dus', 'box' => 'box', 'kotak' => 'dus',
        'roll' => 'roll', 'rol' => 'roll', 'gulung' => 'roll', 'pcs' => 'pcs', 'buah' => 'pcs', 'bh' => 'pcs', 'biji' => 'pcs', 'unit' => 'unit', 'set' => 'set',
        'liter' => 'liter', 'ltr' => 'liter', 'pail' => 'pail', 'galon' => 'galon', 'kaleng' => 'kaleng', 'klg' => 'kaleng', 'ikat' => 'ikat', 'kodi' => 'kodi',
        'colt' => 'colt', 'pickup' => 'pickup', 'engkel' => 'engkel', 'titik' => 'titik', 'ls' => 'ls', 'lot' => 'ls',
    ];

    /** kata yang tidak menentukan barang (dibuang saat mencocokkan) */
    private const BUANG = ['untuk', 'utk', 'beli', 'pembelian', 'bayar', 'pembayaran', 'biaya', 'hpp', 'infra', 'tahap', 'proyek', 'project', 'dan', 'yang', 'ke', 'di',
        'dari', 'pak', 'bu', 'ongkir', 'ongkos', 'kirim', 'pengiriman', 'material', 'bahan', 'harga', 'total', 'lagi', 'tambah', 'tambahan', 'sama', 'buat', 'pakai',
        'dengan', 'dgn', 'isi', 'per', 'x', 'pm', 'pm2', 'cm', 'mm', 'inch', 'inci', 'in', 'ml', 'gr', 'gram', 'regatta', 'marina', 'pik', 't1', 't2', 't3', 't02', 'asg', 'cash', 'dp', 'lunas', 'nota', 'toko'];

    /** satuan hitungan: "pcs" di Kas sering berarti batang / lembar / buah (mis. "40 pcs Besi Beton") */
    private const HITUNGAN = ['pcs', 'batang', 'lembar', 'unit', 'set', 'roll', 'dus', 'box', 'kaleng', 'pail', 'galon', 'ikat'];

    /** 2 = sama, 1 = setara (pcs ↔ satuan hitungan lain), 0 = beda */
    public static function cocokSatuan(?string $a, ?string $b): int
    {
        if ($a === $b) {
            return 2;
        }

        return ($a === 'pcs' || $b === 'pcs') && in_array($a, self::HITUNGAN, true) && in_array($b, self::HITUNGAN, true) ? 1 : 0;
    }

    public static function satuanBaku(?string $s): ?string
    {
        $s = mb_strtolower(trim((string) $s));

        return $s === '' ? null : (self::SATUAN[$s] ?? $s);
    }

    /** Kata penting nama barang (huruf kecil, tanpa angka murni / satuan / kata umum). @return string[] */
    public static function token(string $nama): array
    {
        $kata = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($nama), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($kata, fn ($k) => mb_strlen($k) >= 2 && ! ctype_digit($k)
            && ! isset(self::SATUAN[$k]) && ! in_array($k, self::BUANG, true))));
    }

    /**
     * Uraikan keterangan transaksi "20 sak Semen 50kg" / "Bayar Pasir 1 Rit" → [qty, satuan baku, sisa nama]. Null bila tidak ada "angka satuan".
     *
     * @return array{0: float, 1: string, 2: string}|null
     */
    public static function urai(string $ket): ?array
    {
        $pola = implode('|', array_map(fn ($s) => preg_quote($s, '/'), array_keys(self::SATUAN)));
        $ket = str_replace('½', '1/2', $ket);
        if (! preg_match('/(?<![\p{L}\p{N}\/])(\d+\/\d+|\d+(?:[.,]\d+)?)\s*('.$pola.')(?![\p{L}\p{N}])/iu', $ket, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $angka = $m[1][0];
        if (str_contains($angka, '/')) {
            [$a, $b] = array_map('floatval', explode('/', $angka));
            $qty = $b > 0 ? $a / $b : 0;
        } else {
            $qty = preg_match('/^\d{1,3}\.\d{3}$/', $angka) ? (float) str_replace('.', '', $angka) : (float) str_replace(',', '.', $angka);
        }
        if ($qty <= 0) {
            return null;
        }
        $sisa = trim(substr($ket, 0, $m[0][1]).' '.substr($ket, $m[0][1] + strlen($m[0][0])));

        return [$qty, self::satuanBaku($m[2][0]), $sisa];
    }

    /**
     * @return array{harga: ?float, satuan: ?string, dasar: ?string, mirip: bool, bukti: array<int, array>}
     */
    public static function untuk(string $nama, ?string $satuan = null, ?int $kecualiId = null): array
    {
        $tok = self::token($nama);
        $kosong = ['harga' => null, 'satuan' => self::satuanBaku($satuan), 'dasar' => null, 'mirip' => false, 'bukti' => []];
        if (! $tok) {
            return $kosong;
        }
        $kunci = $tok[0];
        $satuan = self::satuanBaku($satuan);
        $calon = [];

        // (a) harga yang sudah dipastikan di menu ini
        $q = DB::table('kebutuhan_material as k')->leftJoin('proyek_tahap as t', 't.id', '=', 'k.proyek_tahap_id')
            ->where('k.dicek', true)->whereNotNull('k.harga')->where('k.nama_item', 'like', "%{$kunci}%");
        if ($kecualiId) {
            $q->where('k.id', '!=', $kecualiId);
        }
        foreach (self::aman(fn () => $q->orderByDesc('k.tanggal')->limit(60)->get(['k.id', 'k.tanggal', 'k.nama_item', 'k.qty', 'k.satuan', 'k.harga', 't.nama as tahap'])) as $r) {
            $calon[] = ['sumber' => 'Kebutuhan Material', 'tanggal' => $r->tanggal, 'nama' => $r->nama_item, 'ket' => $r->nama_item.($r->tahap ? ' · '.$r->tahap : ''),
                'qty' => $r->qty !== null ? (float) $r->qty : null, 'satuan' => self::satuanBaku($r->satuan), 'harga' => (float) $r->harga,
                'total' => $r->qty ? (float) $r->qty * (float) $r->harga : null, 'pihak' => null, 'ref' => 'KM-'.$r->id, 'bonus' => 0.15];
        }

        // (b) Database Sparepart
        foreach (self::aman(fn () => DB::table('sparepart_beli')->where(fn ($w) => $w->where('nama_item', 'like', "%{$kunci}%")->orWhere('spesifikasi', 'like', "%{$kunci}%"))
            ->whereNotNull('harga_satuan')->orderByDesc('tanggal')->limit(60)
            ->get(['tanggal', 'nama_item', 'spesifikasi', 'qty', 'satuan', 'harga_satuan', 'total', 'supplier', 'ref', 'sumber'])) as $r) {
            $calon[] = ['sumber' => 'Database Sparepart', 'tanggal' => $r->tanggal, 'nama' => trim($r->nama_item.' '.$r->spesifikasi),
                'ket' => trim($r->nama_item.' '.$r->spesifikasi), 'qty' => $r->qty !== null ? (float) $r->qty : null, 'satuan' => self::satuanBaku($r->satuan),
                'harga' => (float) $r->harga_satuan, 'total' => $r->total !== null ? (float) $r->total : null, 'pihak' => $r->supplier, 'ref' => $r->ref, 'bonus' => 0.05];
        }

        // (c) Kas Harian & Kas UJ — keterangan bebas
        $kas = self::aman(fn () => DB::table('kas_bon as b')->join('kas_transfer as t', 't.id', '=', 'b.kas_transfer_id')->where('b.keterangan', 'like', "%{$kunci}%")
            ->where('b.nominal', '>', 0)->orderByDesc('b.tanggal')->limit(250)->get(['b.tanggal', 'b.keterangan', 'b.nominal', 'b.no_id', 't.nama_tujuan']));
        $uj = self::aman(fn () => DB::table('uj_detail')->where('keterangan', 'like', "%{$kunci}%")->where('nominal', '>', 0)->where('biaya_transfer', false)
            ->orderByDesc('tanggal')->limit(80)->get(['tanggal', 'keterangan', 'nominal', 'id_uj as no_id', 'nama as nama_tujuan']));
        foreach ([['Kas Harian', $kas], ['Kas UJ', $uj]] as [$sumber, $baris]) {
            foreach ($baris as $r) {
                $u = self::urai((string) $r->keterangan);
                $calon[] = ['sumber' => $sumber, 'tanggal' => $r->tanggal, 'nama' => $u ? $u[2] : (string) $r->keterangan, 'ket' => (string) $r->keterangan,
                    'qty' => $u[0] ?? null, 'satuan' => $u[1] ?? null, 'harga' => $u ? round((float) $r->nominal / $u[0]) : null,
                    'total' => (float) $r->nominal, 'pihak' => $r->nama_tujuan, 'ref' => $r->no_id, 'bonus' => 0.0];
            }
        }

        // skor kecocokan nama (+ satuan)
        $bukti = [];
        foreach ($calon as $c) {
            $tc = self::token($c['nama']);
            if (! $tc || ! in_array($kunci, $tc, true)) {
                continue; // LIKE juga menangkap kata lain yang memuat kunci (mis. "pasir" di "pasirian") — buang
            }
            $sama = count(array_intersect($tok, $tc));
            $skor = 0.6 * $sama / count($tok) + 0.4 * $sama / count($tc) + $c['bonus'];
            if ($satuan && $c['satuan']) {
                $skor += [-0.1, 0.1, 0.2][self::cocokSatuan($c['satuan'], $satuan)];
            }
            if ($skor < 0.5) {
                continue;
            }
            $c['skor'] = round($skor, 3);
            $c['cakup'] = round($sama / count($tok), 3); // 1 = semua kata nama yang diminta ada di transaksi ini
            unset($c['bonus']);
            $bukti[] = $c;
        }
        usort($bukti, fn ($a, $b) => [$b['skor'], (string) $b['tanggal']] <=> [$a['skor'], (string) $a['tanggal']]);

        // saran: bukti berharga satuan (satuan cocok; yang bersatuan diutamakan) → cakupan kata tertinggi → skor tertinggi (toleransi 0,1) → paling baru.
        // Satuan mengikuti bukti bila belum diucapkan.
        $berharga = array_values(array_filter($bukti, fn ($b) => $b['harga'] !== null && (! $satuan || ! $b['satuan'] || self::cocokSatuan($b['satuan'], $satuan))));
        if (array_filter($berharga, fn ($b) => $b['satuan'])) {
            $berharga = array_values(array_filter($berharga, fn ($b) => $b['satuan']));
        }
        $pilih = null;
        if ($berharga) {
            $cakup = max(array_column($berharga, 'cakup'));
            $berharga = array_filter($berharga, fn ($b) => $b['cakup'] >= $cakup);
            $top = max(array_column($berharga, 'skor'));
            $dekat = array_filter($berharga, fn ($b) => $b['skor'] >= $top - 0.1);
            usort($dekat, fn ($a, $b) => strcmp((string) $b['tanggal'], (string) $a['tanggal']));
            $pilih = $dekat[0];
        }
        $bukti = array_slice($bukti, 0, 15);
        if (! $pilih) {
            return [...$kosong, 'bukti' => $bukti];
        }

        return [
            'harga' => (float) $pilih['harga'],
            'satuan' => $satuan ?? $pilih['satuan'],
            'dasar' => mb_strimwidth(($pilih['cakup'] < 1 ? '≈ mirip — ' : '').$pilih['sumber'].' '.self::tgl($pilih['tanggal']).': '.$pilih['ket'], 0, 300, '…'),
            'mirip' => $pilih['cakup'] < 1, // hanya barang serupa (tidak semua kata nama cocok) → owner perlu cek
            'bukti' => $bukti,
        ];
    }

    /** Satu sumber riwayat galat (mis. tabel rusak) tidak boleh menggagalkan pencatatan item — sumber itu dilewati & dilaporkan. */
    private static function aman(\Closure $ambil): iterable
    {
        return rescue($ambil, collect(), true);
    }

    public static function tgl($t): string
    {
        return $t ? \Illuminate\Support\Carbon::parse($t)->translatedFormat('j M Y') : '';
    }
}
