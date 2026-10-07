<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Baca satu lembar bulanan Kas Bank Jago (mis. "0926") dari nilai sel yang tampil di sheet.
 * Baris dengan Debet/Kredit = transfer; baris dengan Detail Kredit = bon milik transfer terakhir.
 * Pembacaan berhenti di baris "TOTAL".
 */
class BacaLembarKas
{
    private const BULAN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7,
        'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12];

    /**
     * @param  array<int, array<int, string>>  $baris  nilai sel per baris (indeks 0 = baris 1 sheet)
     * @return array{lembar: string, bulan: CarbonImmutable, saldo_awal: int, saldo_akhir_sheet: ?int, transfer: array, catatan: string[]}
     */
    public static function baca(string $lembar, array $baris): array
    {
        if (! preg_match('/^(\d{2})(\d{2})$/', $lembar, $m)) {
            throw new RuntimeException("Nama lembar {$lembar} bukan format BBTT.");
        }
        $bulan = CarbonImmutable::create(2000 + (int) $m[2], (int) $m[1], 1);

        $judul = null;
        foreach ($baris as $i => $r) {
            if (in_array('Tanggal', array_map('trim', $r), true)) {
                $judul = $i;
                break;
            }
        }
        if ($judul === null) {
            throw new RuntimeException("Baris judul (Tanggal) tidak ditemukan di lembar {$lembar}.");
        }
        $k = self::kolom($baris[$judul]);

        $saldoAwal = null;
        $saldoAkhirSheet = null;
        $adaTotal = false;
        $transfer = [];
        $catatan = [];
        $sel = fn (array $r, string $nama) => trim((string) ($r[$k[$nama]] ?? ''));

        for ($i = $judul + 1; $i < count($baris); $i++) {
            $r = $baris[$i];
            $no = $i + 1;
            if (strtoupper($sel($r, 'tanggal')) === 'TOTAL') {
                $adaTotal = true;
                $saldoAkhirSheet = self::rupiah($sel($r, 'saldo'));
                break;
            }
            $debet = self::rupiah($sel($r, 'debet')) ?? 0;
            $kredit = self::rupiah($sel($r, 'kredit')) ?? 0;
            $bon = self::rupiah($sel($r, 'detail'));

            if ($saldoAwal === null && strcasecmp($sel($r, 'keterangan'), 'Saldo Awal') === 0) {
                $saldoAwal = $debet;

                continue;
            }

            if ($debet || $kredit) {
                $tanggal = self::tanggal($sel($r, 'tanggal'), $bulan, $no, $lembar);
                $transfer[] = [
                    'baris' => $no,
                    'tanggal' => $tanggal,
                    'nama_tujuan' => $sel($r, 'nama') ?: null,
                    'no_rek_tujuan' => $sel($r, 'norek') ?: null,
                    'bank_tujuan' => DaftarBank::rapikan($sel($r, 'bank')),
                    'keterangan' => $sel($r, 'keterangan') ?: null,
                    'debet' => $debet,
                    'kredit' => $kredit,
                    'saldo' => self::rupiah($sel($r, 'saldo')),
                    'no_id' => TulisKasSheet::angkaNoId($sel($r, 'no_id')),
                    'bon' => [],
                ];
            }

            if ($bon !== null && $bon !== 0) {
                if (! $transfer) {
                    $catatan[] = "Baris {$no}: transaksi detail ".number_format($bon, 0, ',', '.').' muncul sebelum ada transfer, diabaikan.';

                    continue;
                }
                $t = &$transfer[count($transfer) - 1];
                $t['bon'][] = [
                    'baris' => $no,
                    'tanggal' => $sel($r, 'tanggal') !== '' ? self::tanggal($sel($r, 'tanggal'), $bulan, $no, $lembar) : $t['tanggal'],
                    'nominal' => $bon,
                    'pic' => $sel($r, 'pic') ?: null,
                    'keterangan' => $sel($r, 'ket_bon') ?: null,
                    'gl' => $sel($r, 'gl') ?: null,
                    'kode_gl' => $sel($r, 'kode_gl') ?: null,
                    'kode_bon' => $sel($r, 'kode_bon') ?: null,
                    'no_id' => $sel($r, 'no_id') ?: null,
                    'id_transaksi' => $sel($r, 'id_transaksi') ?: null,
                ];
                unset($t);
            }
        }

        if ($saldoAwal === null) {
            $catatan[] = 'Baris "Saldo Awal" tidak ditemukan; saldo awal dianggap 0.';
        }
        if (! $adaTotal) {
            $catatan[] = 'Baris TOTAL tidak ditemukan; seluruh baris sampai akhir lembar dibaca.';
        } elseif ($saldoAkhirSheet === null) {
            $catatan[] = 'Saldo di baris TOTAL tidak terbaca (kosong atau error seperti #REF!).';
        }
        foreach ($transfer as $t) {
            $jumlahBon = array_sum(array_column($t['bon'], 'nominal'));
            if ($t['kredit'] && $jumlahBon !== $t['kredit']) {
                $catatan[] = "Baris {$t['baris']} ".mb_strimwidth((string) $t['keterangan'], 0, 60, '…').': transfer '
                    .number_format($t['kredit'], 0, ',', '.').' ≠ jumlah detail '.number_format($jumlahBon, 0, ',', '.').'.';
            }
            if ($t['debet'] && $t['bon']) {
                $catatan[] = "Baris {$t['baris']}: uang masuk tetapi punya transaksi detail.";
            }
        }

        return [
            'lembar' => $lembar,
            'bulan' => $bulan,
            'saldo_awal' => $saldoAwal ?? 0,
            'saldo_akhir_sheet' => $saldoAkhirSheet,
            'transfer' => $transfer,
            'catatan' => $catatan,
        ];
    }

    /** Posisi kolom dari judul. "Keterangan" muncul dua kali: pertama untuk transfer, kedua untuk bon. */
    private static function kolom(array $judul): array
    {
        $judul = array_map(fn ($v) => strtolower(trim((string) $v)), $judul);
        $cari = function (string $nama, int $ke = 1) use ($judul) {
            $posisi = array_keys($judul, $nama, true);

            return $posisi[$ke - 1] ?? throw new RuntimeException("Kolom \"{$nama}\" tidak ditemukan.");
        };

        return [
            'tanggal' => $cari('tanggal'),
            'nama' => $cari('nama rek tujuan'),
            'norek' => $cari('no rektujuan'),
            'bank' => $cari('bank'),
            'keterangan' => $cari('keterangan'),
            'debet' => $cari('debet'),
            'kredit' => $cari('kredit'),
            'saldo' => $cari('saldo'),
            'detail' => $cari('detail kredit'),
            'pic' => $cari('pic'),
            'ket_bon' => $cari('keterangan', 2),
            'gl' => $cari('gl'),
            'kode_gl' => $cari('kode gl'),
            'kode_bon' => $cari('kode bon'),
            'no_id' => $cari('no id'),
            'id_transaksi' => $cari('id transaksi'),
        ];
    }

    /** "1.650.057.604", "26000", " - ", "(1.000)" → int; kosong → null. */
    public static function rupiah(string $v): ?int
    {
        $v = trim($v);
        if ($v === '' || $v === '-') {
            return null;
        }
        $negatif = str_starts_with($v, '-') || str_starts_with($v, '(');
        $angka = preg_replace('/\D/', '', $v);

        return $angka === '' ? null : ($negatif ? -1 : 1) * (int) $angka;
    }

    /** "1-Sep-26" / "01/09/2026" → tanggal; tahun & bulan harus sesuai lembar. */
    private static function tanggal(string $v, CarbonImmutable $bulan, int $no, string $lembar): CarbonImmutable
    {
        $hasil = null;
        if (preg_match('/^(\d{1,2})[-\s]([A-Za-z]{3})[A-Za-z]*[-\s](\d{2,4})$/', $v, $m) && isset(self::BULAN[strtolower($m[2])])) {
            $tahun = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            $hasil = CarbonImmutable::create($tahun, self::BULAN[strtolower($m[2])], (int) $m[1]);
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            $hasil = CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (! $hasil) {
            throw new RuntimeException("Lembar {$lembar} baris {$no}: tanggal \"{$v}\" tidak dikenali.");
        }

        return $hasil;
    }
}
