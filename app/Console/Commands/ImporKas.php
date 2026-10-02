<?php

namespace App\Console\Commands;

use App\Models\AkunGl;
use App\Models\CostCenter;
use App\Models\KasBulan;
use App\Models\KodeGl;
use App\Support\BacaLembarKas;
use App\Support\GoogleSheets;
use App\Support\UraiKodeGl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Impor lembar bulanan Kas Bank Jago (0126, 0226, …) dari spreadsheet Kas Harian MNP.
 * Tanpa --simpan hanya menampilkan hasil rekonsiliasi. Dengan --simpan, isi lembar yang
 * diimpor menggantikan isi lama lembar yang sama (Kode GL hasil koreksi manual tidak disentuh).
 */
class ImporKas extends Command
{
    protected $signature = 'mnp:impor-kas
        {--lembar=* : Hanya lembar tertentu, mis. --lembar=0926 (boleh berulang); kosong = semua lembar BBTT}
        {--simpan : Tulis ke database}
        {--dari-folder= : Baca dari folder JSON hasil mnp:baca-sheet, bukan dari Google}';

    protected $description = 'Impor & rekonsiliasi lembar kas bulanan dari Kas Harian MNP';

    public function handle(): int
    {
        $lembarSumber = $this->sumber();
        if (! $lembarSumber) {
            $this->error('Tidak ada lembar bulanan (format BBTT) yang cocok.');

            return self::FAILURE;
        }

        $hasil = [];
        foreach ($lembarSumber as $nama => $baris) {
            $hasil[$nama] = BacaLembarKas::baca($nama, $baris);
        }
        uasort($hasil, fn ($a, $b) => $a['bulan'] <=> $b['bulan']);

        $this->rekonsiliasi($hasil);

        if (! $this->option('simpan')) {
            $this->line('Belum disimpan. Jalankan lagi dengan --simpan untuk menulis ke database.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($hasil) {
            foreach ($hasil as $h) {
                $this->simpan($h);
            }
        });
        $this->info('Tersimpan: '.implode(', ', array_keys($hasil)).'.');

        return self::SUCCESS;
    }

    /** @return array<string, array> nama lembar => baris nilai */
    private function sumber(): array
    {
        $pilihan = $this->option('lembar');
        $cocok = fn (string $t) => preg_match('/^\d{4}$/', $t) && (! $pilihan || in_array($t, $pilihan, true));

        if ($folder = $this->option('dari-folder')) {
            $hasil = [];
            foreach (glob(rtrim($folder, '/\\').'/*.json') as $file) {
                $j = json_decode(file_get_contents($file), true);
                if (isset($j['lembar']) && $cocok($j['lembar'])) {
                    $hasil[$j['lembar']] = $j['nilai'];
                }
            }

            return $hasil;
        }

        $sheets = GoogleSheets::wajib();
        $id = config('mnp.sheet_kas_harian');
        $nama = collect($sheets->info($id)['sheets'])->pluck('properties.title')->filter($cocok)->values()->all();
        $hasil = [];
        foreach (array_chunk($nama, 4) as $kelompok) {
            $hasil += $sheets->nilai($id, $kelompok);
        }

        return $hasil;
    }

    private function rekonsiliasi(array $hasil): void
    {
        $baris = [];
        $saldoSebelum = null;
        foreach ($hasil as $h) {
            $debet = array_sum(array_column($h['transfer'], 'debet'));
            $kredit = array_sum(array_column($h['transfer'], 'kredit'));
            $bon = array_sum(array_map(fn ($t) => array_sum(array_column($t['bon'], 'nominal')), $h['transfer']));
            $akhir = $h['saldo_awal'] + $debet - $kredit;
            $catatan = $h['catatan'];
            if ($h['saldo_akhir_sheet'] !== null && $akhir !== $h['saldo_akhir_sheet']) {
                $catatan[] = 'Saldo akhir hitungan '.number_format($akhir, 0, ',', '.').' ≠ saldo TOTAL di sheet '.number_format($h['saldo_akhir_sheet'], 0, ',', '.').'.';
            }
            if ($saldoSebelum !== null && $saldoSebelum !== $h['saldo_awal']) {
                $catatan[] = 'Saldo awal '.number_format($h['saldo_awal'], 0, ',', '.').' ≠ saldo akhir bulan sebelumnya '.number_format($saldoSebelum, 0, ',', '.').'.';
            }
            $luarBulan = collect($h['transfer'])->filter(fn ($t) => ! $t['tanggal']->isSameMonth($h['bulan']))->count();
            if ($luarBulan) {
                $catatan[] = "{$luarBulan} transfer bertanggal di luar bulan {$h['lembar']}.";
            }
            $saldoSebelum = $akhir;

            $baris[] = [
                $h['lembar'],
                $this->rp($h['saldo_awal']),
                count($h['transfer']),
                $this->rp($debet),
                $this->rp($kredit),
                array_sum(array_map(fn ($t) => count($t['bon']), $h['transfer'])),
                $this->rp($bon),
                $this->rp($akhir),
                $catatan ? count($catatan).' catatan' : 'cocok',
            ];
            foreach ($catatan as $c) {
                $this->warn("  {$h['lembar']}: {$c}");
            }
        }
        $this->table(['Lembar', 'Saldo awal', 'Transfer', 'Masuk', 'Keluar', 'Bon', 'Total bon', 'Saldo akhir', 'Rekonsiliasi'], $baris);
    }

    private function simpan(array $h): void
    {
        KasBulan::where('lembar', $h['lembar'])->delete();

        $debet = array_sum(array_column($h['transfer'], 'debet'));
        $kredit = array_sum(array_column($h['transfer'], 'kredit'));
        $sebelum = KasBulan::where('bulan', '<', $h['bulan'])->orderByDesc('bulan')->first();
        $catatan = $h['catatan'];
        $akhir = $h['saldo_awal'] + $debet - $kredit;
        if ($h['saldo_akhir_sheet'] !== null && $akhir !== $h['saldo_akhir_sheet']) {
            $catatan[] = 'Saldo akhir hitungan ≠ saldo TOTAL di sheet.';
        }
        if ($sebelum && $sebelum->saldo_akhir !== $h['saldo_awal']) {
            $catatan[] = 'Saldo awal ≠ saldo akhir '.$sebelum->lembar.'.';
        }

        $bulan = KasBulan::create([
            'lembar' => $h['lembar'],
            'bulan' => $h['bulan'],
            'saldo_awal' => $h['saldo_awal'],
            'total_debet' => $debet,
            'total_kredit' => $kredit,
            'total_bon' => array_sum(array_map(fn ($t) => array_sum(array_column($t['bon'], 'nominal')), $h['transfer'])),
            'saldo_akhir' => $akhir,
            'saldo_akhir_sheet' => $h['saldo_akhir_sheet'],
            'catatan' => $catatan ?: null,
            'diimpor_pada' => now(),
        ]);

        foreach ($h['transfer'] as $t) {
            $transfer = $bulan->transfer()->create([
                ...collect($t)->except('bon')->all(),
                'saldo' => $t['saldo'] ?? 0,
            ]);
            $bon = [];
            foreach ($t['bon'] as $b) {
                $bon[] = [
                    ...collect($b)->except('kode_gl')->all(),
                    'kas_transfer_id' => $transfer->id,
                    'kode_gl_id' => $this->kodeGl($b['kode_gl']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($bon, 200) as $potong) {
                DB::table('kas_bon')->insert($potong);
            }
        }
    }

    private array $cacheKode = [];

    private function kodeGl(?string $asli): ?int
    {
        $asli = trim(preg_replace('/\s+/', ' ', (string) $asli));
        if ($asli === '') {
            return null;
        }
        if (isset($this->cacheKode[$asli])) {
            return $this->cacheKode[$asli];
        }

        $kode = KodeGl::firstWhere('kode_asli', $asli);
        if (! $kode) {
            $u = UraiKodeGl::urai($asli);
            $akun = $u['akun'] ? AkunGl::firstOrCreate(['nama' => $u['akun']], ['kelompok' => UraiKodeGl::kelompok($u['akun'])]) : null;
            $cc = $u['cost_center'] ? CostCenter::firstOrCreate(['kode' => $u['cost_center']]) : null;
            $kode = KodeGl::create(['kode_asli' => $asli, 'akun_gl_id' => $akun?->id, 'cost_center_id' => $cc?->id, 'ref' => $u['ref']]);
        }

        return $this->cacheKode[$asli] = $kode->id;
    }

    private function rp(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
