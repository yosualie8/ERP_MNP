<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu absen karyawan (masuk / pulang / mulai lembur / selesai lembur) — waktu dari server, lokasi & selfie dari HP. */
class Absensi extends Model
{
    protected $table = 'absensi';

    protected $fillable = ['karyawan_id', 'tanggal', 'jenis', 'waktu', 'lokasi_absen_id', 'lat', 'lng', 'akurasi_m', 'jarak_m', 'foto', 'lapor_id', 'otomatis', 'ip', 'perangkat', 'user_id'];

    protected $casts = ['tanggal' => 'date', 'waktu' => 'datetime', 'lat' => 'float', 'lng' => 'float'];

    public const JENIS = ['masuk' => 'Masuk', 'pulang' => 'Pulang', 'lembur_mulai' => 'Mulai lembur', 'lembur_selesai' => 'Selesai lembur'];

    /** Satu sesi kerja dianggap berakhir bila lewat dari sekian jam sejak absen Masuk (lupa absen Pulang). */
    public const SESI_JAM = 20;

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(Karyawan::class);
    }

    /** Kiriman Lapor Lapangan asal absen ini (absen otomatis). */
    public function lapor(): BelongsTo
    {
        return $this->belongsTo(Lapor::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiAbsen::class, 'lokasi_absen_id');
    }

    /**
     * Timpakan koreksi jam admin (tabel absen_koreksi) ke absen hasil query, untuk tampilan & hitungan (owner 9 Nov 2026).
     * Absen yang dikoreksi: waktu diganti jam koreksi, jam aslinya di waktu_asli, relasi 'koreksi' terisi. Absen yang tidak ada
     * aslinya (bolong) ditambahkan sebagai model BELUM tersimpan (exists = false, tanpa foto). Tidak ada yang disimpan ke absensi.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $absen
     * @param  array{0: string, 1: string}  $rentang  tanggal sesi [dari, sampai]
     * @param  array<int>|null  $karyawan  batasi ke karyawan ini (null = semua)
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function denganKoreksi(\Illuminate\Support\Collection $absen, array $rentang, ?array $karyawan = null): \Illuminate\Support\Collection
    {
        $koreksi = AbsenKoreksi::with('user')->whereBetween('tanggal', $rentang)
            ->when($karyawan !== null, fn ($q) => $q->whereIn('karyawan_id', $karyawan))->get();
        if ($koreksi->isEmpty()) {
            return $absen;
        }
        $kunci = fn ($kid, $tgl, $jenis) => "{$kid}|{$tgl}|{$jenis}";
        $hasil = $absen->keyBy(fn (self $a) => $kunci($a->karyawan_id, $a->tanggal->toDateString(), $a->jenis).'|'.$a->id);
        foreach ($koreksi as $k) {
            $awalan = $kunci($k->karyawan_id, $k->tanggal->toDateString(), $k->jenis);
            $ada = $hasil->first(fn ($a, $key) => str_starts_with($key, $awalan.'|'));
            if ($ada) {
                $ada->setAttribute('waktu_asli', $ada->waktu);
                $ada->waktu = $k->waktu;
            } else {
                $ada = new self(['karyawan_id' => $k->karyawan_id, 'tanggal' => $k->tanggal->toDateString(), 'jenis' => $k->jenis, 'waktu' => $k->waktu]);
                $hasil->put($awalan.'|baru', $ada);
            }
            $ada->setRelation('koreksi', $k);
        }

        return $hasil->sortBy(fn (self $a) => $a->waktu->getTimestamp())->values();
    }

    /** Koreksi admin yang ditimpakan ke absen ini (lihat denganKoreksi), atau null bila jamnya asli. */
    public function koreksiAdmin(): ?AbsenKoreksi
    {
        return $this->relationLoaded('koreksi') ? $this->getRelation('koreksi') : null;
    }

    /** Keterangan singkat penanda edit, mis. "diedit Valen 9/11 · asli 09:12" / "ditambah Valen 9/11 · tidak ada absen asli". */
    public function catatanKoreksi(): ?string
    {
        $k = $this->koreksiAdmin();
        if (! $k) {
            return null;
        }
        $asli = $this->getAttribute('waktu_asli');

        return ($asli ? 'diedit ' : 'ditambah ').($k->user?->name ?? 'admin').' '.$k->updated_at?->format('j/n H:i')
            .' · '.($asli ? 'asli '.$asli->format('H:i') : 'tidak ada absen asli').($k->catatan ? ' · '.$k->catatan : '');
    }

    /**
     * Jenis absen yang boleh dilakukan karyawan sekarang, urut: Masuk → Pulang → Mulai lembur → Selesai lembur.
     * Sesi = sejak absen Masuk terakhir; sesi baru dimulai bila sesi lama lewat SESI_JAM jam, atau sudah ganti hari & sesi selesai.
     *
     * @return array{jenis: ?string, sesi: \Illuminate\Support\Collection, tanggal: string}
     */
    public static function berikutnya(Karyawan $k): array
    {
        $masuk = self::where('karyawan_id', $k->id)->where('jenis', 'masuk')->orderByDesc('waktu')->first();
        $hariIni = now()->toDateString();
        if (! $masuk || $masuk->waktu->lt(now()->subHours(self::SESI_JAM))) {
            return ['jenis' => 'masuk', 'sesi' => collect(), 'tanggal' => $hariIni];
        }
        $sesi = self::where('karyawan_id', $k->id)->where('waktu', '>=', $masuk->waktu)->orderBy('waktu')->get();
        $akhir = $sesi->last()->jenis;
        $selesai = in_array($akhir, ['pulang', 'lembur_selesai'], true);
        // Sesi kemarin sudah ditutup → hari ini mulai sesi baru.
        if ($selesai && $masuk->tanggal->toDateString() < $hariIni) {
            return ['jenis' => 'masuk', 'sesi' => collect(), 'tanggal' => $hariIni];
        }
        $lanjut = ['masuk' => 'pulang', 'pulang' => 'lembur_mulai', 'lembur_mulai' => 'lembur_selesai', 'lembur_selesai' => null][$akhir];

        return ['jenis' => $lanjut, 'sesi' => $sesi, 'tanggal' => $masuk->tanggal->toDateString()];
    }
}
