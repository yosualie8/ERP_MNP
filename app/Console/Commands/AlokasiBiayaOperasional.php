<?php

namespace App\Console\Commands;

use App\Models\ProyekAlias;
use App\Models\ProyekTahap;
use App\Support\DaftarProyek;
use App\Support\GoogleSheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alokasi tahap per transaksi Kas Harian dari lembar "Biaya Operasional" (sheet Cost Project, kolom I "Proyek"): baris sheet
 * dicocokkan ke transaksi detail lewat Kode Bon + nominal, atau tanggal + nominal + keterangan. "Ignore" = bukan biaya
 * proyek (cashbon). Proyek kosong = tidak dialokasikan (ikut Kode GL). Tanpa --simpan hanya menampilkan hasil.
 */
class AlokasiBiayaOperasional extends Command
{
    protected $signature = 'mnp:alokasi-biaya-operasional {--simpan : Tulis ke tabel kas_alokasi (menggantikan alokasi sumber ini)}';

    protected $description = 'Alokasi tahap transaksi Kas Harian dari lembar Biaya Operasional (Cost Project)';

    private const BULAN = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'Mei' => 5, 'Jun' => 6, 'Jul' => 7, 'Agu' => 8, 'Sep' => 9, 'Okt' => 10, 'Nov' => 11, 'Des' => 12];

    public function handle(): int
    {
        $r = "'Biaya Operasional'!A2:I";
        $sheet = GoogleSheets::wajib()->nilai(config('mnp.sheet_cost_project'), [$r])[$r];
        $ket = fn ($v) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));

        $bon = DB::table('kas_bon as b')->join('kas_transfer as t', 't.id', '=', 'b.kas_transfer_id')->join('kas_bulan as m', 'm.id', '=', 't.kas_bulan_id')
            ->selectRaw('b.tanggal, b.nominal, b.keterangan, b.kode_bon, '.DaftarProyek::SQL_KUNCI.' kunci')->get();
        $perKodeBon = [];
        $perIsi = [];
        foreach ($bon as $b) {
            if ($b->kode_bon) {
                $perKodeBon[$b->kode_bon.'|'.$b->nominal][] = $b->kunci;
            }
            $perIsi[substr($b->tanggal, 0, 10).'|'.$b->nominal.'|'.$ket($b->keterangan)][] = $b->kunci;
        }

        $alokasi = [];
        $hitung = ['tahap' => 0, 'abaikan' => 0, 'kosong' => 0, 'tidak_cocok' => 0];
        $tidakCocok = [];
        $tanpaTahap = [];
        foreach ($sheet as $x) {
            $nominal = (int) preg_replace('/\D/', '', (string) ($x[2] ?? ''));
            $proyek = trim((string) ($x[8] ?? ''));
            if (! $nominal) {
                continue;
            }
            if ($proyek === '') {
                $hitung['kosong']++;

                continue;
            }
            $abaikan = strcasecmp($proyek, 'Ignore') === 0;
            $tahap = $abaikan ? null : $this->tahap($proyek);
            if (! $abaikan && ! $tahap) {
                $tanpaTahap[$proyek] = ($tanpaTahap[$proyek] ?? 0) + 1;

                continue;
            }
            $calon = array_merge($perKodeBon[trim((string) ($x[7] ?? '')).'|'.$nominal] ?? [], $perIsi[$this->tanggal($x[1] ?? '').'|'.$nominal.'|'.$ket($x[4] ?? '')] ?? []);
            $kunci = collect($calon)->first(fn ($k) => ! isset($alokasi[$k]));
            if (! $kunci) {
                $hitung['tidak_cocok']++;
                $tidakCocok[] = [$x[0] ?? '', $x[1] ?? '', number_format($nominal, 0, ',', '.'), mb_strimwidth((string) ($x[4] ?? ''), 0, 50, '…'), $proyek];

                continue;
            }
            $alokasi[$kunci] = ['kunci' => $kunci, 'proyek_tahap_id' => $tahap, 'abaikan' => $abaikan, 'sumber' => 'biaya_operasional', 'created_at' => now(), 'updated_at' => now()];
            $hitung[$abaikan ? 'abaikan' : 'tahap']++;
        }

        $this->info("Dialokasikan ke tahap: {$hitung['tahap']} · Ignore (bukan biaya proyek): {$hitung['abaikan']} · Proyek kosong (ikut Kode GL): {$hitung['kosong']} · Tidak ketemu di Kas Harian: {$hitung['tidak_cocok']}");
        foreach ($tanpaTahap as $p => $n) {
            $this->warn("  Nama proyek tidak dikenal: \"{$p}\" ({$n} baris)");
        }
        if ($tidakCocok) {
            $this->table(['No', 'Tanggal', 'Nominal', 'Keterangan', 'Proyek'], $tidakCocok);
        }

        if ($this->option('simpan')) {
            DB::transaction(function () use ($alokasi) {
                DB::table('kas_alokasi')->where('sumber', 'biaya_operasional')->delete();
                foreach (array_chunk(array_values($alokasi), 500) as $potong) {
                    DB::table('kas_alokasi')->insert($potong);
                }
            });
            $this->info('Tersimpan '.count($alokasi).' alokasi.');
        } else {
            $this->line('Belum disimpan. Jalankan lagi dengan --simpan.');
        }

        return self::SUCCESS;
    }

    /** Nama di kolom Proyek → tahap: alias Cost Project, nama tahap baku, atau "ASG T25 …" = ASG Tahap 25. */
    private function tahap(string $proyek): ?int
    {
        return DaftarProyek::tahapId('cost_project', $proyek)
            ?? ProyekTahap::where('nama', $proyek)->value('id')
            ?? (preg_match('/^ASG T(?:ahap\s*)?(\d+)\b/i', $proyek, $m) ? ProyekTahap::where('nama', 'ASG Tahap '.(int) $m[1])->value('id') : null);
    }

    private function tanggal(string $v): ?string
    {
        return preg_match('/^(\d{1,2})-(\w{3})-(\d{2})$/', trim($v), $m) && isset(self::BULAN[$m[2]])
            ? sprintf('20%02d-%02d-%02d', $m[3], self::BULAN[$m[2]], $m[1]) : null;
    }
}
