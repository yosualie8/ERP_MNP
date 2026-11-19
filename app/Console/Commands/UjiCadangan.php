<?php

namespace App\Console\Commands;

use App\Support\CadanganDrive as Cadangan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Uji pulih mingguan: cadangan terbaru DIUNDUH DARI DRIVE (bukan salinan lokal), database dipulihkan ke mnp_uji lalu jumlah baris
 * tiap tabel dibandingkan dengan database asli; arsip file diperiksa utuh dan .env-nya memuat APP_KEY yang sama. Database uji dihapus lagi.
 */
class UjiCadangan extends Command
{
    protected $signature = 'mnp:uji-cadangan {--tanpa-file : Lewati pemeriksaan arsip file}';

    protected $description = 'Uji pulihkan cadangan terbaru dari Google Drive ke database uji dan bandingkan dengan data asli';

    public function handle(): int
    {
        $drive = Cadangan::wajib();
        $tmp = sys_get_temp_dir().'/mnp-uji-cadangan-'.now()->format('Ymd-His');
        @mkdir($tmp, 0700, true);
        $mulai = microtime(true);
        $catatan = [];

        try {
            // ---- Database
            $db = $drive->daftar($drive->folder('Database'))[0] ?? throw new RuntimeException('Belum ada cadangan database di Drive.');
            $umurJam = round(now()->diffInMinutes(\Carbon\Carbon::parse($db['createdTime'])) / 60, 1);
            if ($umurJam > Cadangan::BATAS_JAM_DB) {
                throw new RuntimeException("Cadangan database terbaru di Drive sudah {$umurJam} jam ({$db['name']}).");
            }
            $f = "{$tmp}/{$db['name']}";
            $drive->unduh($db['id'], $f);
            $this->periksaUkuran($f, $db['size'], $db['name']);
            $this->jalankan(['gzip', '-t', $f], "{$db['name']} rusak (gzip)");
            $this->line("Unduh {$db['name']} (".round($db['size'] / 1048576, 1).' MB) → pulihkan ke '.Cadangan::DB_UJI.' …');
            Cadangan::pulihkanKeUji($f);

            $asli = Cadangan::hitungBaris(config('database.connections.mysql.database'));
            $pulih = Cadangan::hitungBaris(Cadangan::DB_UJI);
            $masalah = [];
            if (count($pulih) < 10 || array_sum($pulih) < array_sum($asli) * 0.5) {
                $masalah[] = 'hasil pulih hanya '.count($pulih).' tabel / '.array_sum($pulih).' baris (asli '.count($asli).' / '.array_sum($asli).')';
            }
            foreach ($asli as $t => $n) {
                if ($n >= 100 && ($pulih[$t] ?? 0) < $n * 0.5) {
                    $masalah[] = "{$t}: ".($pulih[$t] ?? 'tidak ada')." baris vs asli {$n}";
                }
            }
            $hilang = array_keys(array_diff_key($asli, $pulih));
            $selisih = collect($asli)->map(fn ($n, $t) => ($pulih[$t] ?? 0) - $n)->filter()->sortBy(fn ($d) => -abs($d))->take(5)->all();
            $catatan['db'] = ['file' => $db['name'], 'umur_jam' => $umurJam, 'tabel' => count($pulih), 'baris' => array_sum($pulih),
                'baris_asli' => array_sum($asli), 'tabel_baru_belum_tercadang' => $hilang, 'selisih_terbesar' => $selisih];
            $this->info('Database pulih: '.count($pulih).' tabel, '.number_format(array_sum($pulih), 0, ',', '.').' baris (asli sekarang '
                .number_format(array_sum($asli), 0, ',', '.').'; cadangan berumur '.$umurJam.' jam).');
            if ($masalah) {
                throw new RuntimeException('Isi database hasil pulih tidak wajar: '.implode('; ', array_slice($masalah, 0, 5)));
            }

            // ---- Arsip file
            if (! $this->option('tanpa-file')) {
                $arsip = $drive->daftar($drive->folder('File'))[0] ?? null;
                if (! $arsip) {
                    throw new RuntimeException('Belum ada arsip file di Drive.');
                }
                $fa = "{$tmp}/{$arsip['name']}";
                $drive->unduh($arsip['id'], $fa);
                $this->periksaUkuran($fa, $arsip['size'], $arsip['name']);
                $isi = $this->jalankan(['tar', '-tzf', $fa], "{$arsip['name']} rusak (tar)");
                $jumlah = substr_count($isi, "\n");
                $env = $this->jalankan(['tar', '-xzOf', $fa, '.env'], "{$arsip['name']} tidak memuat .env");
                if (! preg_match('/^APP_KEY=(.+)$/m', $env, $k) || trim($k[1]) !== config('app.key')) {
                    throw new RuntimeException('.env di arsip tidak memuat APP_KEY yang sama dengan server — token Google di cadangan database tidak akan bisa dibuka.');
                }
                $catatan['file'] = ['file' => $arsip['name'], 'mb' => round($arsip['size'] / 1048576, 1), 'isi' => $jumlah,
                    'umur_hari' => round(now()->diffInHours(\Carbon\Carbon::parse($arsip['createdTime'])) / 24, 1)];
                $this->info("Arsip file utuh: {$arsip['name']} ({$catatan['file']['mb']} MB, {$jumlah} entri), .env + APP_KEY cocok.");
            }

            $detik = round(microtime(true) - $mulai);
            Cadangan::simpanStatus('uji', ['lulus' => true, 'detik' => $detik] + $catatan);
            Cadangan::riwayat("✓ Uji pulih cadangan Google Drive lulus: {$catatan['db']['file']} → {$catatan['db']['tabel']} tabel, "
                .number_format($catatan['db']['baris'], 0, ',', '.').' baris'.(isset($catatan['file']) ? "; arsip {$catatan['file']['file']} utuh" : '')." ({$detik} dtk)", $catatan);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Cadangan::simpanStatus('uji', ['lulus' => false, 'pesan' => mb_strimwidth($e->getMessage(), 0, 300, '…')] + $catatan);
            Cadangan::riwayat("⚠ Uji pulih cadangan GAGAL: {$e->getMessage()}", $catatan);
            $this->error("Uji pulih GAGAL: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            try {
                Cadangan::mysql('DROP DATABASE IF EXISTS `'.Cadangan::DB_UJI.'`');
            } catch (\Throwable) {
            }
            Process::run(['rm', '-rf', $tmp]);
        }
    }

    private function periksaUkuran(string $f, int $harus, string $nama): void
    {
        if (filesize($f) !== $harus) {
            throw new RuntimeException("Unduhan {$nama} tidak lengkap (".filesize($f)." dari {$harus} byte).");
        }
    }

    private function jalankan(array $perintah, string $galat): string
    {
        $h = Process::timeout(1800)->run($perintah);
        if (! $h->successful()) {
            throw new RuntimeException("{$galat}: ".mb_strimwidth(trim($h->errorOutput()), 0, 200, '…'));
        }

        return $h->output();
    }
}
