<?php

namespace App\Support;

use App\Models\AkunGl;
use App\Models\CostCenter;
use App\Models\KasBulan;
use App\Models\KodeGl;
use Illuminate\Support\Facades\DB;

/**
 * Impor lembar bulanan Kas Bank Jago (0126, 0226, …) ke database. Isi lembar yang diimpor
 * menggantikan isi lama lembar yang sama, jadi sheet tetap satu-satunya sumber data.
 */
class ImporKas
{
    private array $cacheKode = [];

    /**
     * Baca lembar dari sheet lalu simpan, di bawah kunci yang sama dengan penulisan ke sheet (tulis-kas-sheet):
     * impor berkala tidak boleh membaca sheet tepat sebelum aplikasi menulis lalu menimpa hasilnya dengan data lama.
     *
     * @return array<string, array> hasil baca per lembar
     */
    public static function imporUlang(array $lembar = []): array
    {
        return \Illuminate\Support\Facades\Cache::lock('tulis-kas-sheet', 120)->block(90, function () use ($lembar) {
            $hasil = self::baca(self::ambilDariSheet($lembar));
            (new self)->simpan($hasil);

            return $hasil;
        });
    }

    /** @return array<string, array> lembar => baris nilai, diurutkan per bulan */
    public static function ambilDariSheet(array $pilihan = []): array
    {
        $sheets = GoogleSheets::wajib();
        $id = config('mnp.sheet_kas_harian');
        $nama = collect($sheets->info($id)['sheets'])->pluck('properties.title')
            ->filter(fn ($t) => preg_match('/^\d{4}$/', $t) && (! $pilihan || in_array($t, $pilihan, true)))
            ->values()->all();

        $hasil = [];
        foreach (array_chunk($nama, 4) as $kelompok) {
            $hasil += $sheets->nilai($id, $kelompok);
        }

        return $hasil;
    }

    /** @return array<string, array> lembar => hasil BacaLembarKas + rekonsiliasi, urut per bulan */
    public static function baca(array $sumber): array
    {
        $hasil = [];
        foreach ($sumber as $nama => $baris) {
            $hasil[$nama] = BacaLembarKas::baca($nama, $baris);
        }
        uasort($hasil, fn ($a, $b) => $a['bulan'] <=> $b['bulan']);

        foreach ($hasil as &$h) {
            $h['total_debet'] = array_sum(array_column($h['transfer'], 'debet'));
            $h['total_kredit'] = array_sum(array_column($h['transfer'], 'kredit'));
            $h['total_bon'] = array_sum(array_map(fn ($t) => array_sum(array_column($t['bon'], 'nominal')), $h['transfer']));
            $h['jumlah_bon'] = array_sum(array_map(fn ($t) => count($t['bon']), $h['transfer']));
            $h['saldo_akhir'] = $h['saldo_awal'] + $h['total_debet'] - $h['total_kredit'];
            if ($h['saldo_akhir_sheet'] !== null && $h['saldo_akhir'] !== $h['saldo_akhir_sheet']) {
                $h['catatan'][] = 'Saldo akhir hitungan '.rp($h['saldo_akhir']).' ≠ saldo TOTAL di sheet '.rp($h['saldo_akhir_sheet']).'.';
            }
            $luarBulan = collect($h['transfer'])->filter(fn ($t) => ! $t['tanggal']->isSameMonth($h['bulan']))->count();
            if ($luarBulan) {
                $h['catatan'][] = "{$luarBulan} transfer bertanggal di luar bulan lembar {$h['lembar']}.";
            }
        }

        return $hasil;
    }

    /** Tulis ke database dalam satu transaksi. Saldo awal dicek terhadap bulan sebelumnya yang sudah tersimpan. */
    public function simpan(array $hasil): void
    {
        DB::transaction(function () use ($hasil) {
            foreach ($hasil as $h) {
                $this->simpanLembar($h);
            }
            // Cost center & akun yang tidak dipakai lagi setelah urai ulang (mis. "PM Infra Marina" digabung ke "Infra PM").
            CostCenter::whereDoesntHave('kodeGl')->delete();
            AkunGl::whereDoesntHave('kodeGl')->delete();
        });
    }

    private function simpanLembar(array $h): void
    {
        KasBulan::where('lembar', $h['lembar'])->delete();

        $catatan = $h['catatan'];
        $sebelum = KasBulan::where('bulan', '<', $h['bulan'])->orderByDesc('bulan')->first();
        if ($sebelum && $sebelum->saldo_akhir !== $h['saldo_awal']) {
            $catatan[] = 'Saldo awal '.rp($h['saldo_awal']).' ≠ saldo akhir '.$sebelum->lembar.' '.rp($sebelum->saldo_akhir).'.';
        }

        $bulan = KasBulan::create([
            'lembar' => $h['lembar'],
            'bulan' => $h['bulan'],
            'saldo_awal' => $h['saldo_awal'],
            'total_debet' => $h['total_debet'],
            'total_kredit' => $h['total_kredit'],
            'total_bon' => $h['total_bon'],
            'saldo_akhir' => $h['saldo_akhir'],
            'saldo_akhir_sheet' => $h['saldo_akhir_sheet'],
            'catatan' => $catatan ?: null,
            'diimpor_pada' => now(),
        ]);

        foreach ($h['transfer'] as $t) {
            $transfer = $bulan->transfer()->create([...collect($t)->except('bon')->all(), 'saldo' => $t['saldo'] ?? 0]);
            $bon = [];
            foreach ($t['bon'] as $b) {
                $tebakan = $b['kode_gl'] ? null : TebakKodeGl::dari($b['keterangan'] ?? $t['keterangan'], $b['gl']);
                $bon[] = [
                    ...collect($b)->except('kode_gl')->all(),
                    'kas_transfer_id' => $transfer->id,
                    'kode_gl_id' => $this->kodeGl($b['kode_gl'] ?? $tebakan),
                    'kode_gl_ditebak' => $tebakan !== null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($bon, 200) as $potong) {
                DB::table('kas_bon')->insert($potong);
            }
        }
    }

    /** Kode GL asli → id; hasil urai diperbarui tiap impor kecuali yang sudah dikoreksi manual. */
    private function kodeGl(?string $asli): ?int
    {
        $asli = trim(preg_replace('/\s+/', ' ', (string) $asli));
        if ($asli === '') {
            return null;
        }
        if (isset($this->cacheKode[$asli])) {
            return $this->cacheKode[$asli];
        }

        $kode = KodeGl::firstOrNew(['kode_asli' => $asli]);
        if (! $kode->dikoreksi_manual) {
            $u = UraiKodeGl::urai($asli);
            $kode->fill([
                'akun_gl_id' => $u['akun'] ? AkunGl::firstOrCreate(['nama' => $u['akun']], ['kelompok' => UraiKodeGl::kelompok($u['akun'])])->id : null,
                'cost_center_id' => $u['cost_center'] ? CostCenter::firstOrCreate(['kode' => $u['cost_center']], ['nama' => UraiKodeGl::NAMA_COST_CENTER[$u['cost_center']] ?? null])->id : null,
                'ref' => $u['ref'],
            ])->save();
        }

        return $this->cacheKode[$asli] = $kode->id;
    }
}
