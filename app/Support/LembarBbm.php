<?php

namespace App\Support;

use App\Models\AsetTruk;
use App\Models\Bbm;
use App\Models\BbmUnitPeta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lembar "Penggunaan BBM" (spreadsheet yang sama dengan Ritasi): satu baris = satu kejadian solar per unit per hari.
 * Kolom (sejak 14 Okt 2026, sama dengan urutan di aplikasi): A Tanggal, B Jenis, C Unit, D HM Awal, E HM Akhir, F Total HM (=E-D),
 * G Masuk, H Keluar, I Stok Solar (=I baris data sebelumnya + N(G) - N(H)), J Mulai Kerja, K Restime, L Selesai Kerja,
 * M Total Jam Kerja (=MOD(L-J;1)-N(K)), N Status, O Proyek, P Keterangan. Kolom Q ke kanan = tabel bantu admin (tidak dibaca).
 * Proyek "Tahap A + Tahap B" = liter & HM dibagi rata ke tahap-tahap itu. Baris tanpa tanggal & unit (mis. baris SUM admin) dilewati.
 */
class LembarBbm
{
    public const LEMBAR = 'Penggunaan BBM';

    public const STATUS = ['Running', 'Stand By', 'Breakdown', 'Lembur', 'Libur'];

    /** Judul baris 1 lembar (dipakai memastikan struktur sheet = struktur yang dipahami aplikasi). */
    public const JUDUL = ['Tanggal', 'Jenis', 'Unit', 'HM Awal', 'HM Akhir', 'Total HM', 'Masuk', 'Keluar', 'Stok Solar', 'Mulai Kerja', 'Restime',
        'Selesai Kerja', 'Total Jam Kerja', 'Status', 'Proyek', 'Keterangan'];

    /** Tulisan kolom Jenis di sheet. */
    public static function labelJenis(): array
    {
        return self::JENIS_INPUT + ['opname' => 'Stock Opname'];
    }

    /** Tolak bekerja bila judul kolom sheet tidak sama dengan JUDUL (mis. struktur lama / kolom digeser admin). */
    public static function pastikanStruktur(array $barisJudul): void
    {
        $ada = array_map(fn ($v) => mb_strtolower(trim((string) $v)), array_slice(array_pad($barisJudul, 16, ''), 0, 16));
        if ($ada !== array_map('mb_strtolower', self::JUDUL)) {
            throw new \RuntimeException('Struktur kolom lembar "'.self::LEMBAR.'" tidak sesuai (harus: '.implode(', ', self::JUDUL).'). Data tidak dibaca/ditulis supaya tidak tertukar.');
        }
    }

    /** Jenis yang dipilih admin di Input BBM & HM. */
    /** Sejak tanggal ini, isi solar ALAT BERAT wajib disertai HM awal & akhir (keputusan user 14 Okt 2026). Data sebelumnya dibiarkan. */
    public const WAJIB_HM_SEJAK = '2026-10-15';

    public const JENIS_INPUT = ['pakai' => 'Pemakaian alat', 'masuk' => 'Solar masuk', 'pindah' => 'Pindah lokasi', 'susut' => 'Susut', 'operasional' => 'Kendaraan operasional'];

    /** Total jam kerja (menit) = Selesai − Mulai − Rest; lewat tengah malam = hari berikutnya. Null bila Mulai/Selesai kosong. */
    public static function jamKerja(?string $mulai, ?string $rest, ?string $selesai): ?int
    {
        $menit = fn ($v) => preg_match('/^(\d{1,2})[:.](\d{2})$/', trim((string) $v), $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
        [$a, $b] = [$menit($mulai), $menit($selesai)];
        if ($a === null || $b === null) {
            return null;
        }
        $t = $b - $a;
        if ($t < 0) {
            $t += 1440;
        }

        return $t - ($menit($rest) ?? 0);
    }

    /** "8 jam 30 mnt" dari menit. */
    public static function teksJam(?int $menit): string
    {
        return $menit === null ? '' : intdiv(max($menit, 0), 60).' jam'.($menit % 60 ? ' '.($menit % 60).' mnt' : '');
    }

    public static function id(): string
    {
        return config('mnp.sheet_ritasi');
    }

    public static function kutip(): string
    {
        return "'".self::LEMBAR."'";
    }

    /** Baris data = ada tanggal atau unit. */
    public static function adaData(array $r): bool
    {
        return trim((string) ($r[0] ?? '')) !== '' || trim((string) ($r[2] ?? '')) !== '';
    }

    public static function angka(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^-?\d+,\d+$/', $v)) {
            $v = str_replace(',', '.', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Arti tulisan unit: [jenis, aset_id]. Nama aset di Data Aset & "Isi Solar dari DT xxx" dikenali otomatis (pemetaannya dibuat).
     *
     * @param  array<string, BbmUnitPeta>|null  $peta
     */
    public static function arti(?string $unit, ?array &$peta = null): array
    {
        $peta ??= BbmUnitPeta::get()->keyBy('kunci')->all();
        $k = BbmUnitPeta::kunci($unit);
        if ($k === '') {
            return ['cek', null];
        }
        if (! isset($peta[$k])) {
            $aset = AsetTruk::get(['id', 'no_lambung'])->first(fn ($a) => BbmUnitPeta::kunci($a->no_lambung) === $k);
            $baru = match (true) {
                (bool) $aset => ['jenis' => 'pakai', 'aset_id' => $aset->id],
                (bool) preg_match('/^isi solar dari (dt \d{3})$/', $k, $m) => ['jenis' => 'masuk', 'sumber' => 'Solar dari '.strtoupper($m[1])],
                default => null,
            };
            if (! $baru) {
                return ['cek', null];
            }
            $peta[$k] = BbmUnitPeta::create(['nama' => trim((string) $unit), 'kunci' => $k, ...$baru, 'catatan' => 'Dibuat otomatis']);
        }

        return [$peta[$k]->jenis, $peta[$k]->aset_id];
    }

    /** "Tahap A + Tahap B" → [id, id] (null bila ada bagian yang tidak dikenali). */
    public static function tahapIds(?string $proyek): ?array
    {
        $p = trim(preg_replace('/\s+/', ' ', (string) $proyek));
        if ($p === '') {
            return null;
        }
        $ids = array_map(fn ($b) => DaftarProyek::tahapId('bbm', trim($b)), explode(' + ', $p));

        return in_array(null, $ids, true) ? null : $ids;
    }

    /** @return array<int, array> baris DB dari baris sheet mentah A..P (indeks 0 = baris $mulai) */
    public static function urai(array $mentah, int $mulai): array
    {
        $peta = BbmUnitPeta::get()->keyBy('kunci')->all();
        $tahap = [];
        $hasil = [];
        foreach ($mentah as $i => $r) {
            if (! self::adaData($r)) {
                continue;
            }
            $teks = fn (int $k, int $maks) => ($v = trim(preg_replace('/\s+/', ' ', (string) ($r[$k] ?? '')))) === '' ? null : mb_substr($v, 0, $maks);
            $unit = $teks(2, 100);
            [$jenis, $aset] = self::arti($unit, $peta);
            // Jenis tertulis di kolom B diutamakan (dipilih admin); kosong / tak dikenal → dari pemetaan unit.
            if (($k = array_search($teks(1, 40), self::labelJenis(), true)) !== false) {
                $jenis = $k;
            }
            $proyek = $teks(14, 500);
            $hmA = self::angka($r[3] ?? null);
            $hmB = self::angka($r[4] ?? null);
            $hasil[] = [
                'baris' => $mulai + $i, 'tanggal' => KasSeabank::tanggal($r[0] ?? null)?->toDateString(), 'unit' => $unit, 'jenis' => $jenis, 'aset_id' => $aset,
                'hm_awal' => $hmA, 'hm_akhir' => $hmB,
                // Total HM kosong tetapi HM awal & akhir ada → dihitung (akhir − awal).
                'total_hm' => self::angka($r[5] ?? null) ?? ($hmA !== null && $hmB !== null ? round($hmB - $hmA, 2) : null),
                'masuk' => self::angka($r[6] ?? null), 'keluar' => self::angka($r[7] ?? null), 'stok' => self::angka($r[8] ?? null),
                'jam_mulai' => LembarRitasi::jam($r[9] ?? null), 'istirahat' => LembarRitasi::jam($r[10] ?? null), 'jam_selesai' => LembarRitasi::jam($r[11] ?? null),
                'status' => $teks(13, 40), 'keterangan' => $teks(15, 300), 'rental' => null,
                'proyek' => $proyek, 'tahap_ids' => $proyek === null ? null : json_encode($tahap[$proyek] ??= self::tahapIds($proyek)),
                'keterangan_kerja' => null,
            ];
        }

        return $hasil;
    }

    /** Impor ulang seluruh lembar; berbagi kunci dengan penulisan dari aplikasi. */
    public static function impor(GoogleSheets $sheets): int
    {
        return Cache::lock('tulis-bbm-sheet', 120)->block(60, function () use ($sheets) {
            $r = self::kutip().'!A1:P';
            $semua = $sheets->nilaiMentah(self::id(), [$r])[$r];
            self::pastikanStruktur($semua[0] ?? []);
            $baris = self::urai(array_slice($semua, 1), 2);
            DB::transaction(function () use ($baris) {
                Bbm::query()->delete();
                $waktu = now();
                foreach (array_chunk($baris, 500) as $potong) {
                    Bbm::insert(array_map(fn ($b) => [...$b, 'created_at' => $waktu, 'updated_at' => $waktu], $potong));
                }
            });
            Cache::forever('bbm-diimpor-pada', now()->toIso8601String());
            Cache::forget('daftar-proyek-bbm');

            return count($baris);
        });
    }
}
