<?php

namespace App\Support;

use App\Models\JagoMutasi;
use App\Models\JagoUnggah;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mutasi Jago tahap 1 = PENCOCOK (owner 28 Okt 2026): PDF riwayat transaksi kantong Jago diunggah (Eddy 2× sehari; owner / Valen boleh),
 * disimpan sekali per ID# bank, lalu dicocokkan dengan buku aplikasi — input admin TIDAK diubah:
 *  - kantong Uang Jalan DT ↔ Kas UJ (master transfer = satu transfer bank; biaya Rp2.500 = baris bank sesudahnya; transfer masuk = Reimburse UJ);
 *  - kantong Kas MNP ↔ Kas Harian (satu baris bank = satu baris transfer, termasuk "Biaya Transfer Keluar"; saldo per baris sama).
 * Dicocokkan mulai 27 Okt 2026. Hasil per baris: cocok / ada catatan (tanggal, rekening, biaya, DO beda) / ada di bank belum diinput / diinput tidak ada di bank.
 * VALIDITAS DULU (owner 28 Okt 2026): saldo seluruh mutasi tersimpan harus bersambung baris demi baris; bila ada transaksi hilang (saldo tidak balance)
 * pengecekan hanya berjalan sampai transaksi terakhir sebelum putus — sesudahnya "menunggu" sampai rentang yang hilang diunggah.
 * KETAT (owner 29 Okt 2026): sejak tanggal KETAT master Kas UJ / Kas Harian hanya berpasangan lewat tautan mutasi (Input UJ / Input Kas memilih
 * transfer bank) — tanpa menebak dari nominal / rekening / tanggal. Transfer bank tanpa master = "belum diinput"; master tanpa mutasi
 * (mis. diketik langsung di sheet) = "bukan dari mutasi". Reimburse UJ (transfer masuk) & baris Biaya Transfer Kas Harian tetap dicocokkan biasa.
 */
class MutasiJago
{
    public const KANTONG = [
        'uj' => ['nama' => 'Uang Jalan DT', 'nomor' => '103180595342', 'buku' => 'Kas UJ', 'menu' => ['mutasi-jago']],
        'kas' => ['nama' => 'Kas MNP', 'nomor' => '102466547411', 'buku' => 'Kas Harian', 'menu' => ['input-kas', 'kas-harian']],
    ];

    public const MULAI = '2026-10-27';

    public const KETAT = '2026-10-29';

    public const FOLDER = 'mutasi-jago';

    /** Kantong Kas MNP (Kas Harian) hanya untuk akun yang punya menu Kas Harian; kantong UJ untuk semua yang punya menu Mutasi Jago. */
    public static function bolehLihat(?User $u, string $kantong): bool
    {
        return $u && isset(self::KANTONG[$kantong]) && ($u->isSuperAdmin() || collect(self::KANTONG[$kantong]['menu'])->contains(fn ($m) => MenuAkses::boleh($u, $m)));
    }

    public static function pathPdf(string $sha): string
    {
        return storage_path('app/'.self::FOLDER.'/'.$sha.'.pdf');
    }

    /**
     * @return array{unggah: JagoUnggah, kantong: string, baru: int, sudah: int, peringatan: string[]}
     */
    public static function impor(string $path, ?string $namaFile, int $userId): array
    {
        $sha = hash_file('sha256', $path);
        $h = BacaMutasiJago::baca($path);
        $kantong = collect(self::KANTONG)->search(fn ($k) => $k['nomor'] === $h['nomor']);
        if ($kantong === false) {
            throw new RuntimeException("Kantong \"{$h['kantong']}\" ({$h['nomor']}) bukan kantong Kas MNP / Uang Jalan DT — file tidak diimpor.");
        }
        if ($masalah = BacaMutasiJago::periksaSaldo($h['baris'])) {
            throw new RuntimeException('File ditolak — saldo per baris tidak bersambung, kemungkinan file difilter / dicari atau terpotong: '.$masalah[0]
                .' Unduh ulang riwayat transaksi tanpa filter.');
        }
        $peringatan = [];
        if (JagoUnggah::where('sha256', $sha)->exists()) {
            $peringatan[] = 'File yang sama persis sudah pernah diunggah.';
        }
        $baris = $h['baris'];

        $hasil = DB::transaction(function () use ($h, $kantong, $baris, $sha, $namaFile, $userId) {
            $u = JagoUnggah::create(['kantong' => $kantong, 'nomor_kantong' => $h['nomor'], 'pemilik' => mb_substr($h['pemilik'], 0, 100), 'dari' => $h['dari'], 'sampai' => $h['sampai'],
                'nama_file' => $namaFile ? mb_substr($namaFile, 0, 255) : null, 'sha256' => $sha, 'jumlah_baris' => count($baris), 'user_id' => $userId]);
            $baru = 0;
            foreach ($baris as $b) {
                $id = $b['id_bank'] ?: 'TANPA-ID-'.$b['waktu']->format('YmdHi').'-'.(int) round($b['jumlah']).'-'.(int) round($b['saldo']);
                $m = JagoMutasi::firstOrCreate(['kantong' => $kantong, 'id_bank' => $id], [
                    'waktu' => $b['waktu'], 'nama' => mb_substr($b['nama'], 0, 150), 'bank' => $b['bank'] ? mb_substr($b['bank'], 0, 40) : null, 'rekening' => $b['rekening'],
                    'jenis' => mb_substr($b['jenis'], 0, 60), 'arah' => $b['arah'], 'biaya' => (bool) preg_match('/biaya|fee/i', $b['jenis']),
                    'catatan' => $b['catatan'] !== '' && $b['catatan'] !== '-' ? mb_substr($b['catatan'], 0, 500) : null, 'jumlah' => $b['jumlah'], 'saldo' => $b['saldo'], 'jago_unggah_id' => $u->id,
                ]);
                $baru += $m->wasRecentlyCreated ? 1 : 0;
            }
            $u->update(['baris_baru' => $baru]);

            return [$u, $baru];
        });
        [$u, $baru] = $hasil;

        // Rentang PDF sampai hari unduh: saldo baris terakhir harus = "Saldo terbaru" di kepala PDF (transaksi akhir tidak tercantum / masih diproses).
        $akhir = $baris[count($baris) - 1];
        if ($h['tgl_terbaru'] && $h['saldo_terbaru'] !== null && $h['sampai'] && $h['sampai'] >= $h['tgl_terbaru'] && abs($akhir['saldo'] - $h['saldo_terbaru']) >= 1) {
            $peringatan[] = 'Saldo transaksi terakhir di PDF ('.rp((int) $akhir['saldo']).') tidak sama dengan "Saldo terbaru" kantong ('.rp((int) $h['saldo_terbaru'])
                .') — ada transaksi di akhir rentang yang belum tercantum; unggah lagi nanti.';
        }
        // Validitas seluruh mutasi tersimpan (bukan hanya file ini): saldo baris demi baris harus bersambung sejak MULAI.
        $v = self::validitas($kantong);
        foreach ($v['putus'] as $p) {
            $peringatan[] = self::pesanPutus($p);
        }
        $u->update(['peringatan' => $peringatan ? implode("\n", $peringatan) : null]);

        $file = self::pathPdf($sha);
        if (! is_file($file)) {
            @mkdir(dirname($file), 0775, true);
            copy($path, $file);
        }

        return ['unggah' => $u, 'kantong' => $kantong, 'baru' => $baru, 'sudah' => count($baris) - $baru, 'peringatan' => $peringatan, 'validitas' => $v];
    }

    /**
     * Validitas mutasi tersimpan satu kantong sejak MULAI: urutan baris (waktu; dalam menit yang sama disusun menurut sambungan saldo karena
     * baris bisa datang dari unggahan berbeda) dan titik putus (saldo sebelumnya + jumlah ≠ saldo = ada transaksi hilang di antaranya).
     *
     * @return array{urut: array<int, JagoMutasi>, putus: array<int, array>, awal: ?Carbon, akhir: ?Carbon, valid_sampai: ?Carbon, valid: bool}
     */
    public static function validitas(string $kantong): array
    {
        $urut = [];
        $saldo = null;
        foreach (JagoMutasi::where('kantong', $kantong)->where('waktu', '>=', self::MULAI)->orderBy('waktu')->orderBy('id')->get()->groupBy(fn ($r) => $r->waktu->format('YmdHi')) as $grup) {
            $sisa = $grup->values()->all();
            while ($sisa) {
                $pilih = null;
                foreach ($sisa as $k => $r) {
                    if ($saldo !== null && abs($saldo + $r->jumlah - $r->saldo) < 1) {
                        $pilih = $k;
                        break;
                    }
                }
                // Tidak ada yang menyambung: ambil baris yang bukan lanjutan baris lain di menit itu (awal rantai menit ini).
                $pilih ??= collect($sisa)->keys()->first(fn ($k) => ! collect($sisa)->contains(fn ($x) => $x !== $sisa[$k] && abs($x->saldo - ($sisa[$k]->saldo - $sisa[$k]->jumlah)) < 1))
                    ?? array_key_first($sisa);
                $urut[] = $sisa[$pilih];
                $saldo = $sisa[$pilih]->saldo;
                unset($sisa[$pilih]);
            }
        }
        $putus = [];
        for ($i = 1; $i < count($urut); $i++) {
            [$a, $b] = [$urut[$i - 1], $urut[$i]];
            if (abs($a->saldo + $b->jumlah - $b->saldo) >= 1) {
                $putus[] = ['setelah' => $a, 'sebelum' => $b, 'kurang' => round($b->saldo - $b->jumlah - $a->saldo, 2)];
            }
        }
        $akhir = $urut ? end($urut)->waktu : null;

        return ['urut' => $urut, 'putus' => $putus, 'awal' => $urut ? $urut[0]->waktu : null, 'akhir' => $akhir,
            'valid_sampai' => $putus ? $putus[0]['setelah']->waktu : $akhir, 'valid' => ! $putus];
    }

    /**
     * Calon master Input UJ / Input Kas: transfer bank yang VALID (sebelum titik putus) dan belum punya pasangan di buku, 14 hari terakhir.
     * Kas UJ hanya transfer keluar; Kas Harian keluar & masuk. Baris biaya ikut sebagai "biaya" transfernya (menit sama, sesudahnya).
     *
     * @return array<int, array{id: int, waktu: string, jam: string, nama: string, bank: ?string, rekening: ?string, arah: string, nominal: int, biaya: int, catatan: ?string, saran: ?string}>
     */
    public static function calonMaster(string $kantong): array
    {
        $c = self::cocokkan($kantong, today()->subDays(14), today());
        $urut = collect($c['validitas']['urut'])->values();
        $posisi = $urut->pluck('id')->flip();

        return $c['baris']->filter(fn ($r) => $r['status'] === 'belum' && ! $r['m']->dipakai && ($kantong === 'kas' || $r['m']->arah === 'keluar'))
            ->map(fn ($r) => [
                'id' => $r['m']->id, 'waktu' => $r['m']->waktu->toDateString(), 'jam' => $r['m']->waktu->format('H:i'), 'nama' => (string) $r['m']->nama,
                'bank' => $r['m']->bank, 'rekening' => $r['m']->rekening, 'arah' => $r['m']->arah, 'nominal' => (int) abs($r['m']->jumlah),
                'biaya' => self::biayaDari($r['m'], $urut, $posisi), 'catatan' => $r['m']->catatan, 'saran' => $r['saran'], 'nama_buku' => self::namaBunga($r['m']),
            ])->values()->all();
    }

    /** Biaya transfer bank untuk satu transfer keluar = baris biaya tepat sesudahnya di menit yang sama (Rp0 bila tidak ada). */
    public static function biayaDari(JagoMutasi $m, ?Collection $urut = null, ?Collection $posisi = null): int
    {
        if ($m->arah !== 'keluar') {
            return 0;
        }
        $urut ??= collect(self::validitas($m->kantong)['urut'])->values();
        $posisi ??= $urut->pluck('id')->flip();
        $n = $urut[($posisi[$m->id] ?? -2) + 1] ?? null;

        return $n && $n->biaya && $n->waktu->format('YmdHi') === $m->waktu->format('YmdHi') ? (int) round(abs($n->jumlah)) : 0;
    }

    /**
     * Master dari mutasi (owner 28 Okt 2026): isian master (tanggal, bank, rekening, nominal, biaya transfer; Kas Harian juga arah) DIGANTI data bank.
     * - Edit transaksi yang tertaut ke mutasi → tetap terkunci ke mutasinya;
     * - Input baru → wajib memilih mutasi yang masih calon (valid, belum diinput, belum dipakai);
     * - Super Admin boleh tanpa mutasi untuk transaksi di luar bank (tunai), wajib alasan ≥ 10 karakter.
     *
     * @return array{input: array, mutasi: ?JagoMutasi, galat: ?string, luar_bank: ?string}
     */
    public static function terapkanMaster(\Illuminate\Http\Request $request, string $kantong, array $input, ?string $tautan = null): array
    {
        $hasil = ['input' => $input, 'mutasi' => null, 'galat' => null, 'luar_bank' => null];
        $m = $tautan ? JagoMutasi::where('kantong', $kantong)->where('dipakai', $tautan)->first() : null;
        if ($tautan && ! $m) {
            return $hasil; // transaksi lama (sebelum master dari mutasi) — diedit seperti biasa
        }
        if (! $m) {
            $id = $request->integer('jago_mutasi_id');
            if (! $id) {
                $alasan = trim((string) $request->input('luar_bank_alasan'));
                if (! $request->user()->isSuperAdmin()) {
                    return [...$hasil, 'galat' => 'Pilih transfernya dari daftar mutasi Jago di atas form (master transaksi diambil dari mutasi bank). Bila belum ada, unggah mutasi terbaru di menu Mutasi Jago.'];
                }
                if (! $request->boolean('luar_bank') || mb_strlen($alasan) < 10) {
                    return [...$hasil, 'galat' => 'Pilih transfer dari daftar mutasi Jago — atau centang "Transaksi di luar bank" dan tulis alasannya (min. 10 karakter).'];
                }

                return [...$hasil, 'luar_bank' => $alasan];
            }
            $m = JagoMutasi::where('kantong', $kantong)->find($id);
            $calon = collect(self::calonMaster($kantong))->pluck('id')->flip();
            if (! $m || $m->dipakai || ! isset($calon[$m->id])) {
                return [...$hasil, 'galat' => 'Transfer mutasi yang dipilih sudah dipakai / sudah tercatat (mungkin oleh admin lain) atau belum valid. Muat ulang, lalu pilih lagi.'];
            }
        }
        $biaya = self::biayaDari($m);
        $tanggal = $m->waktu->copy()->startOfDay();
        $bank = DaftarBank::kode($m->bank) ?? $m->bank;
        if ($kantong === 'uj') {
            $input = [...$input, 'tanggal' => $tanggal, 'bank' => $bank, 'rekening' => $m->rekening, 'nominal' => (int) abs($m->jumlah),
                'biaya_transfer' => $biaya > 0, 'nominal_biaya' => $biaya ?: (int) $input['nominal_biaya']];
        } else {
            $keluar = $m->arah === 'keluar';
            $input = [...$input, 'tanggal' => $tanggal, 'arah' => $m->arah, 'no_rek' => $m->rekening, 'bank' => $bank,
                'nominal_transfer' => $keluar ? (int) abs($m->jumlah) : null, 'nominal_masuk' => $keluar ? 0 : (int) $m->jumlah,
                'biaya_transfer' => $keluar && $biaya > 0, 'nominal_biaya' => $biaya ?: (int) $input['nominal_biaya']];
        }

        return [...$hasil, 'input' => $input, 'mutasi' => $m];
    }

    /** Bunga / pajak bunga dari bank (tanpa rekening): "Interest", "Tax on Interest" (Inggris) / "Bunga", "Pajak Bunga" (Indonesia). */
    public static function bunga(JagoMutasi $m): bool
    {
        return (bool) preg_match('/^(interest|tax on interest|bunga|pajak( atas)? bunga)\b/i', trim((string) $m->jenis));
    }

    /** Nama & keterangan baku di Kas Harian untuk baris bunga (sama seperti pencatatan selama ini): "Bunga" / "Pajak Bunga". */
    public static function namaBunga(JagoMutasi $m): ?string
    {
        return self::bunga($m) ? ($m->arah === 'masuk' ? 'Bunga' : 'Pajak Bunga') : null;
    }

    /**
     * Bunga & pajak bunga kantong Kas MNP dicatat OTOMATIS di Kas Harian sesudah unggah mutasi yang valid (owner 28 Okt 2026), sama seperti
     * pencatatan selama ini: "Bunga" = uang masuk tanpa rekening; "Pajak Bunga" = keluar, satu detail Kode GL "Pajak Bunga", tanpa biaya transfer.
     * Hanya baris yang masih calon (valid & belum ada di Kas Harian) — yang sudah diinput admin tidak dicatat lagi.
     *
     * @return string[] ringkasan yang tercatat
     */
    public static function catatBungaOtomatis(int $userId): array
    {
        return \Illuminate\Support\Facades\Cache::lock('bunga-otomatis', 120)->block(60, function () use ($userId) {
            $hasil = [];
            $urut = collect(self::validitas('kas')['urut'])->values();
            $posisi = $urut->pluck('id')->flip();
            foreach (collect(self::calonMaster('kas'))->filter(fn ($c) => $c['nama_buku']) as $c) {
                $m = JagoMutasi::find($c['id']);
                // Kas Harian tanpa sen: nominal = selisih saldo bank yang dibulatkan ke bawah (sesudah − sebelum), supaya saldo Kas Harian
                // selalu = saldo bank tanpa sen (sen bunga menumpuk di bank; bila tiap bunga dipotong sennya, selisihnya lama-lama ≥ Rp1).
                $prev = $m ? ($urut[($posisi[$m->id] ?? 0) - 1] ?? null) : null;
                $nominal = $m ? ($prev ? (int) abs(floor($m->saldo + 0.000001) - floor($prev->saldo + 0.000001)) : (int) abs($m->jumlah)) : 0;
                if (! $m || $m->dipakai || $nominal < 1) {
                    continue;
                }
                $nama = self::namaBunga($m);
                $masuk = $m->arah === 'masuk';
                $input = ['tanggal' => $m->waktu->copy()->startOfDay(), 'arah' => $m->arah, 'nama_tujuan' => $nama, 'no_rek' => null, 'bank' => null, 'keterangan' => $nama,
                    'nominal_masuk' => $masuk ? $nominal : 0, 'kode_gl_masuk' => null, 'nominal_transfer' => $masuk ? null : $nominal,
                    'bon' => $masuk ? [] : [['nominal' => $nominal, 'pic' => null, 'keterangan' => 'Pajak Bunga', 'kode_gl' => 'Pajak Bunga', 'uj' => null, 'no_mobil' => null]],
                    'biaya_transfer' => false, 'nominal_biaya' => TulisKasSheet::BIAYA_TRANSFER, 'reimburse_uj' => null, 'tanggal_reimburse' => null];
                $tulis = (new TulisKasSheet(GoogleSheets::wajib()))->tulis($input);
                self::tandaiDipakai($m, 'kas:'.$tulis['no_id'][0], $userId);
                CerminReimburse::tambahSegera($tulis['no_id']);
                \App\Models\KasRiwayat::create([
                    'aksi' => 'tambah', 'lembar' => $tulis['lembar'], 'baris_awal' => $tulis['baris_awal'], 'baris_akhir' => $tulis['baris_akhir'],
                    'ringkasan' => ($masuk ? 'Uang masuk ' : 'Transfer ').rp($nominal).' '.$m->waktu->translatedFormat('j M Y')." {$nama} (otomatis dari mutasi Jago ".$m->waktu->format('d-m H:i').')',
                    'isi' => ['input' => [...$input, 'tanggal' => $input['tanggal']->toDateString()], 'no_id' => $tulis['no_id'], 'jago_mutasi_id' => $m->id, 'otomatis' => true],
                    'user_id' => $userId,
                ]);
                $hasil[] = ['lembar' => $tulis['lembar'], 'teks' => "{$nama} ".rp($nominal).' ('.$m->waktu->translatedFormat('j M').", NO ID {$tulis['no_id'][0]})"];
            }
            foreach (collect($hasil)->pluck('lembar')->unique() as $lembar) {
                ImporKas::imporUlang([$lembar]);
            }

            return array_column($hasil, 'teks');
        });
    }

    public static function tandaiDipakai(JagoMutasi $m, string $tautan, int $userId): void
    {
        JagoMutasi::whereKey($m->id)->whereNull('dipakai')->update(['dipakai' => $tautan, 'dipakai_pada' => now(), 'dipakai_oleh' => $userId]);
    }

    /** Ringkasan satu mutasi untuk form (banner "Master dari mutasi"). */
    public static function ringkas(JagoMutasi $m): array
    {
        return ['id' => $m->id, 'waktu' => $m->waktu->toDateString(), 'jam' => $m->waktu->format('H:i'), 'nama' => (string) $m->nama, 'bank' => $m->bank, 'rekening' => $m->rekening,
            'arah' => $m->arah, 'nominal' => (int) abs($m->jumlah), 'biaya' => self::biayaDari($m), 'catatan' => $m->catatan, 'saran' => null, 'nama_buku' => self::namaBunga($m)];
    }

    public static function pesanPutus(array $p): string
    {
        return 'Mutasi tidak valid: ada transaksi yang hilang antara '.$p['setelah']->waktu->format('d-m-Y H:i').' ('.$p['setelah']->nama.', saldo '.rp((int) $p['setelah']->saldo).') dan '
            .$p['sebelum']->waktu->format('d-m-Y H:i').' ('.$p['sebelum']->nama.') — saldo tidak balance, selisih '.($p['kurang'] < 0 ? '−' : '+').rp((int) abs($p['kurang']))
            .'. Unduh & unggah riwayat untuk rentang itu.';
    }

    /**
     * Pencocokan satu kantong untuk rentang tanggal.
     *
     * Hanya mutasi yang VALID (sebelum titik putus pertama) yang dicek; transaksi buku sesudah hari terakhir yang tercakup penuh = "menunggu".
     *
     * @return array{baris: Collection, hari: Collection, cakupan: array{0: ?Carbon, 1: ?Carbon}, buku: string, validitas: array, belum_dicek: int}
     */
    public static function cocokkan(string $kantong, Carbon $dari, Carbon $sampai): array
    {
        $dari = $dari->copy()->startOfDay()->max(Carbon::parse(self::MULAI));
        $sampai = $sampai->copy()->endOfDay();
        $v = self::validitas($kantong);
        $cakupan = [$v['awal'], $v['valid_sampai']];
        $semuaBank = collect($v['urut'])->filter(fn ($m) => $m->waktu->between($dari, $sampai))->values();
        $bank = $cakupan[1] ? $semuaBank->filter(fn ($m) => $m->waktu->lte($cakupan[1]))->values() : collect();
        $belumDicek = $semuaBank->count() - $bank->count();

        // Unit bank: Kas UJ — transfer + baris biaya sesudahnya (menit sama) = satu unit; Kas Harian — tiap baris berdiri sendiri.
        $unit = [];
        foreach ($bank as $m) {
            $n = count($unit);
            if ($kantong === 'uj' && $m->biaya && $n && ! $unit[$n - 1]['m']->biaya && ! $unit[$n - 1]['fee'] && $unit[$n - 1]['m']->waktu->format('YmdHi') === $m->waktu->format('YmdHi')) {
                $unit[$n - 1]['fee'] = $m;
                $unit[$n - 1]['saldo'] = $m->saldo;

                continue;
            }
            $unit[] = ['m' => $m, 'fee' => null, 'saldo' => $m->saldo];
        }

        $buku = $kantong === 'uj' ? self::bukuUj($dari, $sampai) : self::bukuKas($dari, $sampai);
        $perNominal = collect($buku)->groupBy(fn ($b) => (string) (int) $b['nominal']);
        // Nominal "unik" (tidak ada transfer / buku lain bernominal sama ±3 hari): boleh berpasangan walau rekening di buku salah total
        // (mis. bayar tanah Jogja Rp21.660.000 tercatat ke rekening lain).
        $unikBank = collect($unit)->groupBy(fn ($u) => (string) (int) $u['m']->jumlah)->map->count();
        foreach ($unit as $i => $u) {
            $unit[$i]['unik'] = ($unikBank[(string) (int) $u['m']->jumlah] ?? 0) === 1;
        }
        $buku = array_map(fn ($b) => [...$b, 'unik' => count($perNominal[(string) (int) $b['nominal']] ?? []) === 1], $buku);
        $perNominal = collect($buku)->groupBy(fn ($b) => (string) (int) $b['nominal']);

        // Pasangan terbaik lebih dulu (greedy): nominal wajib sama, tanggal ±3 hari; rekening / saldo / nama / tanggal sama menambah skor.
        $calon = [];
        foreach ($unit as $i => $u) {
            // Bunga & pajak bunga kantong Uang Jalan DT tidak dicatat di Kas UJ (owner 28 Okt 2026) — tidak dicari pasangannya.
            if ($kantong === 'uj' && self::bunga($u['m'])) {
                continue;
            }
            foreach ($perNominal[(string) (int) $u['m']->jumlah] ?? [] as $b) {
                if (($s = self::skor($u, $b, $kantong)) !== null) {
                    $calon[] = [$s, $i, $b['kunci']];
                }
            }
        }
        usort($calon, fn ($a, $b) => $b[0] <=> $a[0]);
        $pasang = $dipakai = [];
        foreach ($calon as [$s, $i, $k]) {
            if (! isset($pasang[$i]) && ! isset($dipakai[$k])) {
                $pasang[$i] = $k;
                $dipakai[$k] = true;
            }
        }
        $bukuKunci = collect($buku)->keyBy('kunci');
        $bebas = fn () => array_keys(array_filter($unit, fn ($u, $i) => ! isset($pasang[$i]) && ! $u['m']->biaya, ARRAY_FILTER_USE_BOTH));

        // Reimburse UJ yang dibayar dalam beberapa transfer masuk (mis. RUJ-0004 = 3 transfer di hari yang sama): cari gabungan yang jumlahnya pas.
        $gabung = [];
        foreach (collect($buku)->filter(fn ($b) => ! isset($dipakai[$b['kunci']]) && $b['nominal'] > 0) as $b) {
            $masuk = array_values(array_filter($bebas(), fn ($i) => $unit[$i]['m']->jumlah > 0 && abs($unit[$i]['m']->waktu->copy()->startOfDay()->diffInDays($b['tanggal'], false)) <= 2));
            if (($sub = self::gabunganPas($masuk, fn ($i) => $unit[$i]['m']->jumlah, $b['nominal'])) !== null) {
                foreach ($sub as $i) {
                    $pasang[$i] = $b['kunci'];
                    $gabung[$i] = count($sub);
                }
                $dipakai[$b['kunci']] = true;
            }
        }
        // Transfer keluar lalu kembali masuk dengan jumlah & penerima sama (≤ 2 hari) = saling meniadakan.
        $kembali = [];
        foreach ($bebas() as $i) {
            $m = $unit[$i]['m'];
            if ($m->jumlah >= 0 || isset($kembali[$i])) {
                continue;
            }
            foreach ($bebas() as $j) {
                $n = $unit[$j]['m'];
                if (! isset($kembali[$j]) && abs($n->jumlah + $m->jumlah) < 1 && $n->waktu->gte($m->waktu) && $n->waktu->diffInDays($m->waktu) <= 2
                    && (self::rekMirip($n->rekening, $m->rekening) || mb_strtoupper(trim($n->nama)) === mb_strtoupper(trim($m->nama)))) {
                    $kembali[$i] = $kembali[$j] = true;
                    break;
                }
            }
        }

        $baris = collect();
        foreach ($unit as $i => $u) {
            $m = $u['m'];
            $b = isset($pasang[$i]) ? $bukuKunci[$pasang[$i]] : null;
            $catatan = $b ? self::catatan($u, $b, $kantong) : [];
            if ($b && isset($gabung[$i])) {
                $catatan = array_values(array_filter($catatan, fn ($c) => ! str_starts_with($c, 'Tanggal')));
            }
            $saran = null;
            $status = $b ? ($catatan ? 'catatan' : 'cocok') : ($m->biaya ? 'biaya' : (isset($kembali[$i]) ? 'kembali' : ($kantong === 'uj' && self::bunga($m) ? 'bunga' : 'belum')));
            if (! $b && ! $m->biaya && ! isset($kembali[$i])) {
                // Nominal beda tetapi rekening / nama sama dekat tanggalnya → kemungkinan salah ketik nominal.
                $mirip = collect($buku)->filter(fn ($x) => ! isset($dipakai[$x['kunci']]) && ($x['nominal'] < 0) === ($m->jumlah < 0)
                    && abs($x['tanggal']->diffInDays($m->waktu->copy()->startOfDay(), false)) <= 1 && (self::rekMirip($x['rek'], $m->rekening) || self::namaSama($x['nama'], $m->nama)))
                    ->sortBy(fn ($x) => abs(abs($x['nominal']) - abs($m->jumlah)))->first();
                $saran = $mirip ? 'Mungkin '.$mirip['id'].' ('.rp((int) abs($mirip['nominal'])).', '.$mirip['nama'].') — selisih '.rp((int) abs(abs($mirip['nominal']) - abs($m->jumlah))).'.' : null;
                // Kas UJ: transfer masuk dari kantong Kas MNP = reimburse — tanpa catatan Reimburse UJ yang pas cukup diberi catatan, bukan "belum diinput".
                if ($kantong === 'uj' && $m->jumlah > 0 && self::digit($m->rekening) === self::KANTONG['kas']['nomor']) {
                    $status = 'catatan';
                    $catatan = ['Reimburse masuk dari Kas MNP tanpa catatan Reimburse UJ (RUJ) yang jumlahnya pas.'];
                    $ruj = collect($buku)->filter(fn ($x) => ! isset($dipakai[$x['kunci']]) && str_starts_with($x['id'], 'RUJ-')
                        && abs($x['tanggal']->diffInDays($m->waktu->copy()->startOfDay(), false)) <= 2)->sortBy(fn ($x) => abs($x['nominal'] - $m->jumlah))->first();
                    $saran = $ruj ? 'Mungkin '.$ruj['id'].' ('.rp((int) $ruj['nominal']).') — selisih '.rp((int) abs($ruj['nominal'] - $m->jumlah)).'.' : null;
                }
            }
            if ($b && isset($gabung[$i])) {
                $catatan[] = "Satu dari {$gabung[$i]} transfer untuk {$b['id']} (total ".rp((int) $b['nominal']).').';
                $status = 'cocok';
                $catatan = array_values(array_filter($catatan, fn ($c) => str_starts_with($c, 'Satu dari')));
            }
            $baris->push(['jenis' => 'bank', 'waktu' => $m->waktu, 'tanggal' => $m->waktu->copy()->startOfDay(), 'm' => $m, 'fee' => $u['fee'], 'saldo' => $u['saldo'],
                'b' => $b, 'catatan' => $catatan, 'saran' => $saran, 'status' => $status]);
        }
        // Buku tanpa pasangan: "tidak ada di bank" hanya bila harinya TERCAKUP PENUH mutasi valid (ada mutasi valid sesudah hari itu); hari terakhir
        // yang tercakup / sesudah titik putus = "menunggu" (transaksi sore / malam mungkin belum ada di PDF yang diunduh).
        $penuhSampai = $cakupan[1]?->copy()->startOfDay();
        $tertaut = JagoMutasi::where('kantong', $kantong)->whereNotNull('dipakai')->pluck('dipakai')->flip();
        foreach ($buku as $b) {
            if (isset($dipakai[$b['kunci']]) || $b['tanggal']->lt($dari) || $b['tanggal']->gt($sampai)) {
                continue;
            }
            $tercakup = $cakupan[0] && $b['tanggal']->gte($cakupan[0]->copy()->startOfDay()) && $b['tanggal']->lt($penuhSampai);
            $bukan = self::ketat($b['tanggal'], $b) && self::master($b) && ! isset($tertaut[$b['tautan']]);
            $baris->push(['jenis' => 'buku', 'waktu' => $b['tanggal']->copy()->endOfDay(), 'tanggal' => $b['tanggal'], 'm' => null, 'fee' => null, 'saldo' => null, 'b' => $b,
                'catatan' => [], 'saran' => null, 'status' => $bukan ? 'bukan-mutasi' : ($tercakup ? 'tidak-di-bank' : 'menunggu')]);
        }
        $posisi = collect($v['urut'])->pluck('id')->flip();
        $baris = $baris->sortBy(fn ($r) => $r['waktu']->format('YmdHi').($r['m'] ? sprintf('%08d', $posisi[$r['m']->id] ?? 0) : '99999999'))->values();

        $hari = $baris->groupBy(fn ($r) => $r['tanggal']->toDateString())->map(function (Collection $g, $tgl) use ($kantong, $penuhSampai, $cakupan) {
            $bankHari = $g->where('jenis', 'bank');
            $saldoBank = $bankHari->last()['saldo'] ?? null;
            $saldoBuku = $kantong === 'kas' ? DB::table('kas_transfer')->whereDate('tanggal', $tgl)->orderByDesc('baris')->value('saldo') : null;
            $n = $g->countBy('status');
            $masalah = ($n['catatan'] ?? 0) + ($n['belum'] ?? 0) + ($n['tidak-di-bank'] ?? 0) + ($n['biaya'] ?? 0) + ($n['bukan-mutasi'] ?? 0);
            $penuh = $penuhSampai && $cakupan[0] && Carbon::parse($tgl)->gte($cakupan[0]->copy()->startOfDay()) && Carbon::parse($tgl)->lt($penuhSampai);

            return ['tanggal' => Carbon::parse($tgl), 'bank' => $bankHari->count(), 'n' => $n, 'saldo_bank' => $saldoBank, 'saldo_buku' => $saldoBuku, 'penuh' => $penuh,
                'cocok100' => $penuh && $bankHari->isNotEmpty() && $masalah === 0 && ($saldoBuku === null || $saldoBank === null || abs($saldoBank - $saldoBuku) < 1)];
        })->sortKeys();

        return ['baris' => $baris, 'hari' => $hari, 'cakupan' => $cakupan, 'buku' => self::KANTONG[$kantong]['buku'], 'validitas' => $v, 'belum_dicek' => $belumDicek];
    }

    /** Kas UJ: master transfer (nominal keluar, biaya dari detail Biaya Transfer, DO detail) + Reimburse UJ (transfer masuk). */
    private static function bukuUj(Carbon $dari, Carbon $sampai): array
    {
        $rentang = [$dari->copy()->subDays(3)->toDateString(), $sampai->copy()->addDays(3)->toDateString()];
        $hasil = [];
        $detail = DB::table('uj_detail')->whereIn('uj_transaksi_id', DB::table('uj_transaksi')->whereBetween('tanggal', $rentang)->select('id'))
            ->get(['uj_transaksi_id', 'id_uj', 'nominal', 'no_do', 'biaya_transfer', 'keterangan'])->groupBy('uj_transaksi_id');
        foreach (DB::table('uj_transaksi')->whereBetween('tanggal', $rentang)->orderBy('baris')->get() as $t) {
            if ((float) $t->nominal <= 0) {
                continue;
            }
            $d = $detail[$t->id] ?? collect();
            $hasil[] = ['kunci' => 'uj'.$t->id, 'tautan' => 'uj:'.$t->no_uj, 'id' => 'UJ-'.$t->no_uj, 'tanggal' => Carbon::parse($t->tanggal)->startOfDay(), 'nominal' => -(float) $t->nominal,
                'rek' => $t->rekening, 'bank' => $t->bank, 'nama' => (string) $t->nama, 'biaya' => (float) $d->where('biaya_transfer', true)->sum('nominal'),
                'do' => $d->where('biaya_transfer', false)->pluck('no_do')->map(fn ($v) => SaldoSopir::kunciDo($v))->filter()->unique()->values()->all(),
                'ket' => (string) ($d->firstWhere('biaya_transfer', false)->keterangan ?? ''), 'n' => $d->where('biaya_transfer', false)->count(), 'saldo' => null, 'fee' => false,
                'url' => route('uj.edit', $t->no_uj)];
        }
        foreach (DB::table('uj_reimburse')->whereBetween('tanggal', $rentang)->get(['id', 'tanggal', 'total']) as $r) {
            $hasil[] = ['kunci' => 'ruj'.$r->id, 'tautan' => null, 'id' => sprintf('RUJ-%04d', $r->id), 'tanggal' => Carbon::parse($r->tanggal)->startOfDay(), 'nominal' => (float) $r->total,
                'rek' => null, 'bank' => null, 'nama' => 'Reimburse UJ', 'biaya' => 0.0, 'do' => [], 'ket' => 'Reimburse UJ', 'n' => 0, 'saldo' => null, 'fee' => false, 'url' => null];
        }

        return $hasil;
    }

    /** Kas Harian: tiap baris transfer (debet = masuk, kredit = keluar), termasuk baris "Biaya Transfer Keluar". */
    private static function bukuKas(Carbon $dari, Carbon $sampai): array
    {
        $rentang = [$dari->copy()->subDays(3)->toDateString(), $sampai->copy()->addDays(3)->toDateString()];

        return DB::table('kas_transfer')->whereBetween('tanggal', $rentang)->orderBy('tanggal')->orderBy('baris')->get()->map(fn ($t) => [
            'kunci' => 'kas'.$t->id, 'tautan' => $t->no_id ? 'kas:'.$t->no_id : null, 'id' => $t->no_id ? 'NO ID '.$t->no_id : 'baris '.$t->baris, 'tanggal' => Carbon::parse($t->tanggal)->startOfDay(),
            'nominal' => (float) $t->debet - (float) $t->kredit, 'rek' => $t->no_rek_tujuan, 'bank' => $t->bank_tujuan, 'nama' => (string) $t->nama_tujuan, 'biaya' => 0.0,
            'do' => [], 'ket' => (string) $t->keterangan, 'n' => 0, 'saldo' => (float) $t->saldo, 'fee' => (bool) preg_match('/^biaya transfer/i', trim((string) $t->keterangan)), 'url' => null,
        ])->reject(fn ($b) => abs($b['nominal']) < 0.5)->values()->all();
    }

    private static function skor(array $u, array $b, string $kantong): ?int
    {
        $m = $u['m'];
        // Master dibuat dari mutasi ini (Input UJ / Input Kas): pasangan pasti.
        if ($m->dipakai && $m->dipakai === ($b['tautan'] ?? null)) {
            return 10000;
        }
        // Buku dicatat tanpa sen (mis. bunga 7.882,89 → 7.882): selisih < Rp1 dianggap sama.
        if (abs($m->jumlah - $b['nominal']) >= 1) {
            return null;
        }
        // Kas Harian: baris biaya hanya berpasangan dengan baris "Biaya Transfer" (dan sebaliknya).
        if ($kantong === 'kas' && $m->biaya !== $b['fee']) {
            return null;
        }
        // Master bertanggal sebelum MULAI dibayar dengan transfer di luar mutasi yang dicek — tidak boleh "menarik" transfer baru
        // (mis. 4 transfer 28 Okt "UJ 2 rit sidomuncul KIK" Rp540.000 sempat tertempel ke UJ RKS 26 Okt bernominal sama; owner 29 Okt 2026).
        if (self::master($b) && $b['tanggal']->lt(Carbon::parse(self::MULAI))) {
            return null;
        }
        // Sejak KETAT: master hanya lewat tautan; transfer bank yang sudah jadi master lain tidak ditebakkan ke buku lain.
        if (self::ketat($m->waktu, $b) && (self::master($b) || $m->dipakai)) {
            return null;
        }
        $hari = abs($m->waktu->copy()->startOfDay()->diffInDays($b['tanggal'], false));
        if ($hari > 3) {
            return null;
        }
        // Kas UJ: DO di catatan bank lain dari DO di Kas UJ & tanggal beda > 1 hari = transfer lain yang belum diinput
        // (mis. uang jalan harian sopir yang sama, nominal & rekening sama) — bukan pasangan (owner 29 Okt 2026).
        if ($hari > 1 && self::doBeda($u, $b)->isNotEmpty()) {
            return null;
        }
        $s = 100 - (int) $hari * 15;
        $r1 = self::digit($b['rek']);
        $r2 = self::digit($m->rekening);
        $nama = self::namaSama($b['nama'], $m->nama);
        $saldo = $b['saldo'] !== null && abs($b['saldo'] - $u['saldo']) < 1;
        // Baris biaya Rp2.500 semuanya serupa (tanpa nama / rekening): hanya berpasangan di hari yang sama (atau saldonya sama).
        if ($kantong === 'kas' && $m->biaya && $hari > 0 && ! $saldo) {
            return null;
        }
        if ($r1 !== '' && $r2 !== '') {
            // Rekening beda jauh & nama beda = transfer lain yang kebetulan nominalnya sama (mis. dua sopir @ Rp1.330.000) — bukan pasangan.
            if (! self::rekMirip($b['rek'], $m->rekening) && ! $nama && ! $saldo && ! ($u['unik'] && $b['unik'] && $hari == 0)) {
                return null;
            }
            $s += $r1 === $r2 ? 60 : (self::rekMirip($b['rek'], $m->rekening) ? 35 : -25);
        }
        if ($saldo) {
            $s += 80;
        }
        if ($nama) {
            $s += 15;
        }

        return $s;
    }

    /** Pasangan bank ↔ buku ini jatuh di masa KETAT (salah satu tanggalnya ≥ KETAT). */
    private static function ketat(Carbon $waktu, array $b): bool
    {
        $k = Carbon::parse(self::KETAT);

        return $waktu->gte($k) || $b['tanggal']->gte($k);
    }

    /** Baris master yang wajib dibuat dari mutasi: transfer Kas UJ / baris Kas Harian ber-NO ID, selain baris Biaya Transfer & Reimburse UJ. */
    private static function master(array $b): bool
    {
        return ($b['tautan'] ?? null) !== null && ! $b['fee'];
    }

    /** Selisih kecil pada pasangan yang cocok nominalnya. @return string[] */
    private static function catatan(array $u, array $b, string $kantong): array
    {
        $m = $u['m'];
        $c = [];
        if (! $b['tanggal']->isSameDay($m->waktu)) {
            $c[] = 'Tanggal di '.self::KANTONG[$kantong]['buku'].' '.$b['tanggal']->translatedFormat('j M').', di bank '.$m->waktu->translatedFormat('j M').'.';
        }
        $r1 = self::digit($b['rek']);
        $r2 = self::digit($m->rekening);
        if ($r1 !== '' && $r2 !== '' && $r1 !== $r2) {
            $c[] = "Rekening di buku {$b['bank']} {$b['rek']}, di bank {$m->bank} {$m->rekening}.";
        }
        if ($kantong === 'uj' && $m->arah === 'keluar') {
            $feeBank = $u['fee'] ? abs($u['fee']->jumlah) : 0.0;
            if (abs($feeBank - $b['biaya']) >= 0.5) {
                $c[] = 'Biaya transfer: bank '.rp((int) $feeBank).', Kas UJ '.rp((int) $b['biaya']).'.';
            }
            if (($lain = self::doBeda($u, $b))->isNotEmpty()) {
                $c[] = 'DO di catatan bank '.$lain->implode(', ').' tidak ada di Kas UJ (DO '.implode(', ', $b['do']).').';
            }
        }

        return $c;
    }

    /** Kas UJ, transfer keluar: DO yang disebut di catatan bank tetapi tidak ada di DO baris Kas UJ. */
    private static function doBeda(array $u, array $b): Collection
    {
        $m = $u['m'];
        if (! isset($b['do']) || ! $b['do'] || $m->arah !== 'keluar') {
            return collect();
        }
        // Infra memakai DO internal "00xx" di Kas UJ, sedangkan catatan bank menyebut nomor DO buku → tidak dibandingkan.
        if (collect($b['do'])->contains(fn ($v) => strlen((string) $v) <= 3)) {
            return collect();
        }
        preg_match_all('/\bDO\s*(?:No\.?\s*)?(\d{3,7})\b/i', (string) $m->catatan, $do);

        return collect($do[1] ?? [])->map(fn ($v) => SaldoSopir::kunciDo($v))->filter()->unique()->reject(fn ($v) => in_array($v, $b['do'], true))->values();
    }

    /** Nama penerima & nama di buku punya kata yang sama (≥ 3 huruf), mis. "AIIAS NAWAWI" & "Nawawi". */
    private static function namaSama(?string $a, ?string $b): bool
    {
        $kata = fn ($t) => collect(preg_split('/[^A-Z]+/', mb_strtoupper((string) $t)))->filter(fn ($w) => strlen($w) >= 3);

        return $kata($a)->intersect($kata($b))->isNotEmpty();
    }

    /**
     * Gabungan unit (maks. 8 transfer, dicoba semua kombinasi) yang jumlahnya tepat $target. @param  int[]  $kunci
     *
     * @return int[]|null
     */
    private static function gabunganPas(array $kunci, callable $nilai, float $target): ?array
    {
        $kunci = array_slice($kunci, 0, 8);
        $n = count($kunci);
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $sub = [];
            $jumlah = 0.0;
            for ($k = 0; $k < $n; $k++) {
                if ($mask & (1 << $k)) {
                    $sub[] = $kunci[$k];
                    $jumlah += $nilai($kunci[$k]);
                }
            }
            if (count($sub) > 1 && abs($jumlah - $target) < 1) {
                return $sub;
            }
        }

        return null;
    }

    private static function digit(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    /** Rekening sama atau salah ketik kecil (satu berisi yang lain / beda ≤ 2 karakter). */
    private static function rekMirip(?string $a, ?string $b): bool
    {
        [$a, $b] = [self::digit($a), self::digit($b)];
        if ($a === '' || $b === '') {
            return false;
        }

        return $a === $b || (strlen($a) >= 8 && strlen($b) >= 8 && (str_contains($a, $b) || str_contains($b, $a) || levenshtein($a, $b) <= 2));
    }
}
