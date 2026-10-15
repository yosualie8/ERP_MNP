<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu tahap proyek: unit biaya & tagihan. Nama lama/variasi tulisan di sumber lain ada di alias. */
class ProyekTahap extends Model
{
    protected $table = 'proyek_tahap';

    protected $fillable = ['proyek_id', 'nama', 'tipe', 'status', 'surveyor', 'lokasi', 'cost_center', 'volume', 'luas', 'harga_m3', 'omset', 'tarif_bbm', 'tarif_rental',
        'komisi_lapangan', 'komisi_yosua', 'komisi_verlix', 'bayar_lapangan', 'bayar_yosua', 'bayar_verlix', 'catatan', 'user_id'];

    protected $casts = ['volume' => 'float', 'luas' => 'float', 'komisi_lapangan' => 'float', 'komisi_yosua' => 'float', 'komisi_verlix' => 'float',
        'bayar_lapangan' => 'date', 'bayar_yosua' => 'date', 'bayar_verlix' => 'date'];

    public const TIPE = ['luas' => 'Luas & volume', 'ritasi' => 'Ritasi', 'biaya' => 'Biaya umum']; // biaya = biaya tanpa tahap / biaya lokasi

    public const STATUS = ['belum' => 'Belum mulai', 'progress' => 'Progress', 'selesai' => 'Selesai', 'batal' => 'Batal'];

    /** Tarif bawaan (sama dengan sheet Cost Project); bisa diubah per tahap. */
    public const BAWAAN = ['harga_m3' => 139000, 'tarif_bbm' => 12000, 'tarif_rental' => 160000, 'komisi_lapangan' => 3.5, 'komisi_yosua' => 2, 'komisi_verlix' => 2];

    /** Nama surveyor yang dikenal — dipakai memisahkan isian lama "Surveyor Lokasi" (mis. "Bobby Parkiran Ginza"). */
    public const SURVEYOR = ['Andika', 'Barnabas', 'Bobby', 'Dedi', 'Dindin', 'Eddy', 'Fery', 'Harun', 'Hikmat', 'Ibnu', 'Indra', 'Medi', 'Ponco', 'Zulham'];

    /** Variasi ejaan nama surveyor → nama baku. */
    public const SURVEYOR_ALIAS = ['Dedy' => 'Dedi', 'Feri' => 'Fery'];

    /**
     * Pisahkan isian gabungan menjadi [surveyor, lokasi]. Nama surveyor dikenali di awal atau akhir teks;
     * isian berpola "Nama A / Nama B" dengan surveyor yang sama digabung ("Bobby GINZA / RUKO" → Bobby, "GINZA / RUKO").
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function pisahSurveyor(?string $teks): array
    {
        $teks = trim(preg_replace('/\s+/', ' ', (string) $teks));
        if ($teks === '') {
            return [null, null];
        }
        $kenal = fn ($w) => collect(self::SURVEYOR)->first(fn ($n) => strcasecmp($n, $w) === 0)
            ?? collect(self::SURVEYOR_ALIAS)->first(fn ($n, $alias) => strcasecmp($alias, $w) === 0);
        $orang = null;
        $lokasi = [];
        foreach (array_map('trim', explode('/', $teks)) as $bagian) {
            $kata = explode(' ', $bagian);
            if ($n = $kenal($kata[0])) {
                array_shift($kata);
            } elseif (count($kata) > 1 && ($n = $kenal(end($kata)))) {
                array_pop($kata);
            }
            if ($n && $orang && $n !== $orang) {
                return [null, $teks]; // dua surveyor berbeda: biarkan utuh sebagai lokasi
            }
            $orang = $orang ?: $n;
            if (($b = trim(implode(' ', $kata))) !== '') {
                $lokasi[] = $b;
            }
        }

        return [$orang, $lokasi ? implode(' / ', $lokasi) : null];
    }

    public function proyek(): BelongsTo
    {
        return $this->belongsTo(Proyek::class);
    }

    public function alias(): HasMany
    {
        return $this->hasMany(ProyekAlias::class);
    }

    /** Cost center tahap (per proyek detail; tidak ada pewarisan dari proyek induk). */
    public function costCenter(): ?string
    {
        return $this->cost_center;
    }

    public function tinggiUrugan(): ?float
    {
        return $this->volume && $this->luas ? round($this->volume / $this->luas, 3) : null;
    }

    /** Omset: diisi manual, atau volume × harga per m³. */
    public function omsetHitung(): ?int
    {
        return $this->omset ?? ($this->volume && $this->harga_m3 ? (int) round($this->volume * $this->harga_m3) : null);
    }
}
