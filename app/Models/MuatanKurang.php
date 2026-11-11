<?php

namespace App\Models;

use App\Support\DaftarSopir;
use App\Support\LembarRitasi;
use App\Support\MenuAkses;
use App\Support\SaldoSopir;
use App\Support\ValidasiRitasi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Muatan kurang (MK) per surat jalan (owner 11 Nov 2026) — sama untuk semua proyek (Kendal juga manual, standar volume tidak dipakai).
 *  - Ditandai di Monitor Ritasi (centang MK / kolom MK Excel) atau menu Muatan Kurang (MK) → hutang sopir bertambah (uj_pengalihan "muatan",
 *    penanda "[MK SJ <No Surat Jalan>]"), bertanggal hari bongkar. Nominal bawaan NOMINAL; hanya Valen, Eddy (hak akses "Ubah nominal MK")
 *    & Super Admin yang boleh mengubahnya.
 *  - Lunas saat dipasangkan ke uang jalan berikutnya yang ditransfer kurang di Input UJ (PotongHutangUj: "pakai" "uj" ber-DO itu, pakai_id) —
 *    uang jalan DO itu tetap utuh, sopir menanggung potongannya. Transaksi UJ dihapus → potongan ikut terhapus → MK kembali belum dipotong.
 */
class MuatanKurang extends Model
{
    protected $table = 'muatan_kurang';

    protected $fillable = ['no_seri', 'kunci_seri', 'no_do', 'no_mobil', 'sopir', 'tanggal', 'tahap', 'galian', 'nominal', 'alasan', 'muatan_id', 'pakai_id', 'id_uj', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'nominal' => 'integer'];

    public const NOMINAL = 25000;

    public const PENANDA = '[MK SJ ';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pakai(): BelongsTo
    {
        return $this->belongsTo(UjPengalihan::class, 'pakai_id');
    }

    public function dipotong(): bool
    {
        return $this->pakai_id !== null;
    }

    /** Valen & Eddy (lewat hak akses jabatan "Ubah nominal MK") dan Super Admin. */
    public static function bolehUbahNominal(?User $user): bool
    {
        return MenuAkses::boleh($user, 'mk-ubah-nominal');
    }

    public static function kunciSeri(?string $noSeri): ?string
    {
        return LembarRitasi::kunciAngka(strtoupper((string) $noSeri));
    }

    /** MK yang sudah ada untuk surat jalan ini (No Surat Jalan sama, atau sama + akhiran "-Kendal" / "-DT056"). */
    public static function untukSeri(?string $noSeri, ?string $noMobil = null): ?self
    {
        if (! ($k = self::kunciSeri($noSeri))) {
            return null;
        }
        $dasar = preg_replace('/-[A-Z]+\d*$/', '', $k);

        return self::where(fn ($q) => $q->whereIn('kunci_seri', array_unique([$k, $dasar]))->orWhere('kunci_seri', 'like', str_replace(['%', '_'], ['\%', '\_'], $dasar).'-%'))
            ->when($noMobil, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('no_mobil')->orWhere('no_mobil', $noMobil)))
            ->first();
    }

    /**
     * Rit yang bisa ditandai MK, dicari dari No Surat Jalan / LS atau No DO: Ritasi (sudah bongkar) + rit menunggu uang jalan.
     *
     * @return Collection<int, array{sumber: string, no_seri: string, no_do: ?string, no_mobil: ?string, sopir: ?string, tanggal: \Illuminate\Support\Carbon, jam: ?string, tahap: ?string, galian: ?string, mk: ?self}>
     */
    public static function cariRit(string $cari): Collection
    {
        $cari = trim($cari);
        if (mb_strlen($cari) < 3) {
            return collect();
        }
        $suka = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $cari).'%';
        $rit = Ritasi::where(fn ($q) => $q->where('no_seri', 'like', $suka)->orWhere('no_do', 'like', $suka))
            ->orderByDesc('tanggal')->limit(30)->get(['no_seri', 'no_do', 'no_lambung', 'no_polisi', 'tanggal', 'jam', 'tahap', 'galian']);
        $uj = ValidasiRitasi::dariUj($rit->pluck('no_do')->filter()->all());
        $hasil = $rit->map(function ($r) use ($uj) {
            $d = $uj[LembarRitasi::kunciAngka(str_replace(['`', "'"], '', (string) $r->no_do))] ?? null;
            $sopirRit = str_contains((string) $r->no_polisi, '/') ? trim(explode('/', $r->no_polisi, 2)[1]) : null;

            return ['sumber' => 'Ritasi', 'no_seri' => (string) $r->no_seri, 'no_do' => $r->no_do ? ltrim(trim($r->no_do), "`'") : null,
                'no_mobil' => $r->no_lambung ?: ($d['no_lambung'] ?? null), 'sopir' => SaldoSopir::kunci($d['driver'] ?? $sopirRit),
                'tanggal' => $r->tanggal, 'jam' => $r->jam, 'tahap' => $r->tahap, 'galian' => $r->galian];
        });
        $menunggu = RitasiMenunggu::where('no_seri', 'like', $suka)->orderByDesc('tanggal')->limit(10)->get()
            ->map(fn ($m) => ['sumber' => 'Menunggu uang jalan', 'no_seri' => (string) $m->no_seri, 'no_do' => null, 'no_mobil' => $m->no_mobil,
                'sopir' => SaldoSopir::kunci($m->sopir), 'tanggal' => $m->tanggal, 'jam' => $m->jam, 'tahap' => $m->tahap, 'galian' => $m->galian]);

        return $hasil->concat($menunggu->reject(fn ($m) => $hasil->contains(fn ($r) => self::kunciSeri($r['no_seri']) === self::kunciSeri($m['no_seri']))))
            ->map(fn ($r) => [...$r, 'mk' => self::untukSeri($r['no_seri'], $r['no_mobil'])])->values();
    }

    /**
     * Tandai satu surat jalan MK → hutang sopir bertambah. Surat jalan yang sudah ber-MK dikembalikan apa adanya (tidak dobel).
     *
     * @param  array{no_seri: string, no_do: ?string, no_mobil: ?string, sopir: ?string, tanggal: mixed, tahap: ?string, galian: ?string}  $rit
     */
    public static function tandai(array $rit, int $nominal, ?string $alasan, ?int $userId): self|string
    {
        $seri = trim((string) $rit['no_seri']);
        if ($seri === '') {
            return 'No Surat Jalan kosong.';
        }
        if ($lama = self::untukSeri($seri, $rit['no_mobil'] ?? null)) {
            return $lama;
        }
        $sopir = SaldoSopir::kunci($rit['sopir'] ?? null);
        if (! $sopir) {
            return "Sopir surat jalan {$seri} tidak diketahui (DO belum punya uang jalan) — tandai MK sesudah uang jalannya tercatat.";
        }
        if ($nominal <= 0 || $nominal > 1_000_000) {
            return 'Nominal MK tidak wajar.';
        }
        $tgl = \Illuminate\Support\Carbon::parse($rit['tanggal'])->toDateString();
        $alasan = ($alasan = trim((string) $alasan)) === '' ? null : mb_strimwidth($alasan, 0, 250, '…');

        return DB::transaction(function () use ($rit, $seri, $sopir, $nominal, $alasan, $tgl, $userId) {
            $ket = self::PENANDA.$seri.'] Muatan kurang (MK) — surat jalan '.$seri.($rit['no_mobil'] ? ' · '.$rit['no_mobil'] : '')
                .($rit['no_do'] ? ' · DO '.$rit['no_do'] : '').($alasan ? ' · '.$alasan : '');
            $muatan = UjPengalihan::create(['jenis' => 'muatan', 'no_mobil' => $rit['no_mobil'] ?? null, 'nama' => $sopir, 'sopir' => $sopir, 'tanggal' => $tgl,
                'nominal' => $nominal, 'keterangan' => mb_strimwidth($ket, 0, 490, '…'), 'user_id' => $userId]);
            $mk = self::create(['no_seri' => $seri, 'kunci_seri' => self::kunciSeri($seri), 'no_do' => $rit['no_do'] ?? null, 'no_mobil' => $rit['no_mobil'] ?? null,
                'sopir' => $sopir, 'tanggal' => $tgl, 'tahap' => $rit['tahap'] ?? null, 'galian' => $rit['galian'] ?? null, 'nominal' => $nominal, 'alasan' => $alasan,
                'muatan_id' => $muatan->id, 'user_id' => $userId]);
            KasRiwayat::create(['aksi' => 'mk-tandai', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $userId,
                'ringkasan' => mb_strimwidth("MK {$sopir} Rp".rp($nominal)." surat jalan {$seri}".($rit['no_mobil'] ? " ({$rit['no_mobil']})" : '').' → Hutang Supir', 0, 490, '…'),
                'isi' => $mk->toArray()]);
            SaldoSopir::lupakan();

            return $mk;
        });
    }

    /** Ubah nominal MK yang belum dipotong (hutang ikut berubah). */
    public function ubahNominal(int $nominal, ?int $userId): ?string
    {
        if ($this->dipotong()) {
            return "MK surat jalan {$this->no_seri} sudah dipotong di {$this->id_uj} — nominal tidak bisa diubah lagi.";
        }
        if ($nominal <= 0 || $nominal > 1_000_000) {
            return 'Nominal MK tidak wajar.';
        }
        $lama = $this->nominal;
        DB::transaction(function () use ($nominal, $userId, $lama) {
            $this->update(['nominal' => $nominal]);
            UjPengalihan::whereKey($this->muatan_id)->update(['nominal' => $nominal]);
            KasRiwayat::create(['aksi' => 'mk-ubah', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $userId,
                'ringkasan' => "MK {$this->sopir} surat jalan {$this->no_seri}: nominal Rp".rp($lama).' → Rp'.rp($nominal), 'isi' => $this->toArray()]);
        });
        SaldoSopir::lupakan();

        return null;
    }

    /** Hapus MK yang belum dipotong (hutang sopir kembali berkurang). */
    public function hapusMk(?int $userId): ?string
    {
        if ($this->dipotong()) {
            return "MK surat jalan {$this->no_seri} sudah dipotong di {$this->id_uj} — hapus dulu transaksi UJ itu bila potongannya salah.";
        }
        DB::transaction(function () use ($userId) {
            KasRiwayat::create(['aksi' => 'mk-hapus', 'lembar' => 'Ritasi', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $userId,
                'ringkasan' => "Hapus MK {$this->sopir} Rp".rp($this->nominal)." surat jalan {$this->no_seri}", 'isi' => $this->toArray()]);
            $muatan = $this->muatan_id;
            $this->delete();
            UjPengalihan::whereKey($muatan)->delete();
        });
        SaldoSopir::lupakan();

        return null;
    }

    /**
     * MK belum dipotong untuk Input UJ: per MK + ejaan nama sopir yang dikenali (nama lain di Data Karyawan) + uang jalan per rit
     * yang biasa diterima sopir itu (nominal Uang Jalan terbanyak 14 hari terakhir) untuk mengenali transfer yang kurang.
     *
     * @return array<int, array{id: int, sopir: string, alias: string[], no_seri: string, no_mobil: ?string, tanggal: string, nominal: int, tarif: ?int}>
     */
    public static function terbukaUntukForm(): array
    {
        $buka = self::whereNull('pakai_id')->orderBy('tanggal')->orderBy('id')->get();
        if ($buka->isEmpty()) {
            return [];
        }
        $peta = DaftarSopir::untukForm()['peta'];
        $tarif = UjDetail::where('kategori', 'Uang Jalan')->where('biaya_transfer', false)->where('tanggal', '>=', now()->subDays(14)->toDateString())
            ->where('nominal', '>', 0)->get(['nama', 'nominal'])->groupBy(fn ($u) => (string) SaldoSopir::kunci($u->nama))
            ->map(fn ($g) => (int) $g->countBy(fn ($u) => (int) $u->nominal)->sortDesc()->keys()->first());

        return $buka->map(fn ($m) => ['id' => $m->id, 'sopir' => $m->sopir,
            'alias' => collect($peta)->filter(fn ($s) => ($s['nama'] ?? null) === $m->sopir)->keys()->push(DaftarSopir::kunci($m->sopir))->unique()->values()->all(),
            'no_seri' => $m->no_seri, 'no_mobil' => $m->no_mobil, 'tanggal' => $m->tanggal->translatedFormat('j M'), 'nominal' => $m->nominal,
            'tarif' => $tarif[$m->sopir] ?? null])->values()->all();
    }
}
