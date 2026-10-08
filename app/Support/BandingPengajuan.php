<?php

namespace App\Support;

use App\Models\UjPengajuanDetail;

/**
 * Bandingkan baris realisasi (Input UJ) dengan detail pengajuan asalnya.
 * - sesuai      : tidak ada yang berubah
 * - penyesuaian : tujuannya sama, hanya nominal / driver / tulisan keterangan yang berbeda
 * - dialihkan   : kategori, No DO, No Mobil, atau tempat tujuan di keterangan berubah — dana dipakai untuk keperluan lain.
 *   Pengajuan aslinya selesai (tidak bisa direalisasikan lagi) dan DO-nya tetap dihitung sudah dibiayai.
 * Jenis kendaraan tidak dibandingkan (terisi otomatis dari No Mobil).
 */
class BandingPengajuan
{
    public const SESUAI = 'sesuai';

    public const PENYESUAIAN = 'penyesuaian';

    public const DIALIHKAN = 'dialihkan';

    /**
     * @param  array  $d  baris realisasi (bentuk UjController::bacaInput: nama, keterangan, nominal, kategori, no_mobil, no_do)
     * @return array{jenis: string, beda: array<int, array{kolom: string, lama: string, baru: string}>}
     */
    public static function banding(UjPengajuanDetail $p, array $d): array
    {
        $teks = fn ($v) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));
        $do = fn ($v) => ($v = preg_replace('/\s+/', '', (string) $v)) === '' ? '' : (ltrim($v, '0') ?: '0');
        $kolom = [
            'nominal' => ['Nominal', (int) $p->nominal, (int) $d['nominal'], fn ($v) => rp((int) $v)],
            'nama' => ['Driver', $teks($p->nama), $teks($d['nama'] ?? ''), null],
            'keterangan' => ['Keterangan', $teks($p->keterangan), $teks($d['keterangan']), null],
            'kategori' => ['Kategori', $teks(KategoriUj::baku($p->kategori)), $teks(KategoriUj::baku($d['kategori'] ?? null)), null],
            'no_mobil' => ['No Mobil', (string) NomorMobil::rapikan($p->no_mobil), (string) NomorMobil::rapikan($d['no_mobil'] ?? null), null],
            'no_do' => ['No DO', $do($p->no_do), $do($d['no_do'] ?? null), null],
        ];
        $beda = [];
        foreach ($kolom as $f => [$label, $lama, $baru, $format]) {
            if ($lama !== $baru) {
                $asli = $f === 'nominal' ? $lama : $p->{$f};
                $kini = $f === 'nominal' ? $baru : ($d[$f] ?? null);
                $beda[$f] = ['kolom' => $label, 'lama' => $format ? $format($asli) : ((string) $asli ?: '(kosong)'), 'baru' => $format ? $format($kini) : ((string) $kini ?: '(kosong)')];
            }
        }
        if (! $beda) {
            return ['jenis' => self::SESUAI, 'beda' => []];
        }
        $tempatLama = TebakGalian::tempatDari((string) $p->keterangan)[0] ?? null;
        $tempatBaru = TebakGalian::tempatDari((string) $d['keterangan'])[0] ?? null;
        $dialihkan = ValidasiUj::jenisKategori($p->kategori) !== ValidasiUj::jenisKategori($d['kategori'] ?? null)
            || isset($beda['no_do']) || isset($beda['no_mobil'])
            || ($tempatLama && $tempatBaru && $tempatLama !== $tempatBaru);

        return ['jenis' => $dialihkan ? self::DIALIHKAN : self::PENYESUAIAN, 'beda' => array_values($beda)];
    }

    /** Kalimat ringkas untuk pesan & daftar: "Nominal 1.200.000 → 1.800.000; Kategori Uang Jalan → Uang Material". */
    public static function ringkas(array $beda): string
    {
        return implode('; ', array_map(fn ($b) => "{$b['kolom']} {$b['lama']} → {$b['baru']}", $beda));
    }
}
