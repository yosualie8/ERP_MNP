<?php

namespace App\Support;

use App\Models\GoogleIntegrasi;
use App\Models\KasRiwayat;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Cadangan ERP ke Google Drive perusahaan (owner 19 Nov 2026) — salinan di luar VPS, supaya data selamat bila servernya rusak/hilang.
 * Folder "Cadangan ERP MNP" di My Drive akun foto (ptmultiniagaputra), TIDAK dibagikan (beda dengan folder Foto Bon yang dibagikan):
 * - Database/ : mysqldump harian (.sql.gz)
 * - File/     : arsip mingguan storage/app + .env (.env wajib: APP_KEY membuka token Google yang tersimpan terenkripsi di database)
 * Uji pulih mingguan (mnp:uji-cadangan) mengunduh cadangan terbaru dari Drive dan memulihkannya ke database mnp_uji.
 * Status terakhir disimpan di storage/app/cadangan-status.json (bukan Cache — cache dikosongkan tiap deploy).
 */
class CadanganDrive
{
    public const NAMA_AKAR = 'Cadangan ERP MNP';

    public const DB_UJI = 'mnp_uji';           // user mnp punya hak penuh atas database ini (tidak dipakai hal lain)

    public const SIMPAN_HARI_DB = 60;          // cadangan database harian disimpan 60 hari …

    public const SIMPAN_BULAN_DB = 24;         // … ditambah cadangan pertama tiap bulan selama 24 bulan

    public const SIMPAN_FILE = 8;              // arsip file mingguan: 8 terbaru

    public const BATAS_JAM_DB = 26;            // cadangan database lebih tua dari ini = peringatan

    public const BATAS_HARI_UJI = 8;           // uji pulih lebih lama dari ini = peringatan

    private const API = 'https://www.googleapis.com/drive/v3/files';

    private const FOLDER = 'application/vnd.google-apps.folder';

    private const POTONGAN = 16 * 1024 * 1024; // unggah bertahap per 16 MB (kelipatan 256 KB)

    public function __construct(private GoogleSheets $google)
    {
    }

    public static function terhubung(): ?self
    {
        $i = GoogleIntegrasi::foto();

        return $i ? new self(new GoogleSheets($i)) : null;
    }

    public static function wajib(): self
    {
        return self::terhubung() ?? throw new RuntimeException('Koneksi Google Drive foto (akun perusahaan) belum dihubungkan.');
    }

    // ---------------------------------------------------------------- Drive

    /** Folder Database / File di dalam "Cadangan ERP MNP" (dibuat bila belum ada); kembalikan id-nya. */
    public function folder(string $jenis): string
    {
        $akar = $this->cariAtauBuat(self::NAMA_AKAR, 'root', 'akar');

        return $this->cariAtauBuat($jenis, $akar, strtolower($jenis));
    }

    public function linkAkar(): string
    {
        return 'https://drive.google.com/drive/folders/'.$this->cariAtauBuat(self::NAMA_AKAR, 'root', 'akar');
    }

    private function cariAtauBuat(string $nama, string $induk, string $tanda): string
    {
        $q = sprintf("appProperties has { key='mnp_cadangan' and value='%s' } and mimeType = '%s' and '%s' in parents and trashed = false", $tanda, self::FOLDER, $induk);
        $ada = $this->google->http(30)->get(self::API, ['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]);
        $this->pastikan($ada, "mencari folder {$nama}");
        if ($id = $ada->json('files.0.id')) {
            return $id;
        }
        $baru = $this->google->http(30)->post(self::API.'?fields=id', [
            'name' => $nama, 'mimeType' => self::FOLDER, 'parents' => [$induk], 'appProperties' => ['mnp_cadangan' => $tanda],
        ]);
        $this->pastikan($baru, "membuat folder {$nama}");

        return $baru->json('id');
    }

    /** Unggah file lokal (berapa pun besarnya) secara bertahap; kembalikan [id, link]. */
    public function unggah(string $path, string $nama, string $folderId, string $mime = 'application/gzip'): array
    {
        $ukuran = filesize($path);
        $mulai = $this->google->http(30)->withHeaders(['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) $ukuran])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,webViewLink', ['name' => $nama, 'parents' => [$folderId]]);
        $this->pastikan($mulai, "memulai unggah {$nama}");
        $sesi = $mulai->header('Location') ?: throw new RuntimeException('Google Drive tidak memberi alamat unggah.');

        $f = fopen($path, 'rb');
        try {
            $posisi = 0;
            do {
                $isi = (string) fread($f, self::POTONGAN);
                $akhir = $posisi + strlen($isi) - 1;
                for ($coba = 1; ; $coba++) {
                    $res = $this->google->http(300)->withHeaders(['Content-Range' => $ukuran === 0 ? 'bytes */0' : "bytes {$posisi}-{$akhir}/{$ukuran}"])
                        ->withBody($isi, $mime)->put($sesi);
                    if ($res->status() === 308 || $res->successful() || $coba >= 3 || $res->clientError()) {
                        break;
                    }
                    sleep(5 * $coba); // gangguan sementara (5xx): ulangi potongan yang sama
                }
                if ($res->status() !== 308) {
                    $this->pastikan($res, "mengunggah {$nama}");
                }
                $posisi += strlen($isi);
            } while ($res->status() === 308);
        } finally {
            fclose($f);
        }

        return [$res->json('id'), $res->json('webViewLink')];
    }

    /** Unduh file Drive langsung ke disk (tidak lewat memori). */
    public function unduh(string $fileId, string $tujuan): void
    {
        $res = $this->google->http(900)->sink($tujuan)->get(self::API."/{$fileId}", ['alt' => 'media']);
        $this->pastikan($res, 'mengunduh cadangan');
    }

    /** @return array<int, array{id: string, name: string, size: int, createdTime: string}> terbaru dulu */
    public function daftar(string $folderId): array
    {
        $hasil = [];
        $halaman = null;
        do {
            $res = $this->google->http(30)->get(self::API, array_filter([
                'q' => "'{$folderId}' in parents and trashed = false", 'orderBy' => 'createdTime desc', 'pageSize' => 1000,
                'fields' => 'nextPageToken,files(id,name,size,createdTime)', 'pageToken' => $halaman,
            ]));
            $this->pastikan($res, 'membaca daftar cadangan');
            foreach ($res->json('files', []) as $f) {
                $hasil[] = ['id' => $f['id'], 'name' => $f['name'], 'size' => (int) ($f['size'] ?? 0), 'createdTime' => $f['createdTime']];
            }
        } while ($halaman = $res->json('nextPageToken'));

        return $hasil;
    }

    /**
     * Pilih cadangan yang dibuang: database = di luar 60 hari terakhir, kecuali cadangan pertama tiap bulan (24 bulan);
     * file = di luar 8 terbaru. Cadangan terbaru tidak pernah dibuang.
     *
     * @param  array<int, array{id: string, name: string, createdTime: string}>  $daftar  terbaru dulu
     * @return array<int, array{id: string, name: string, createdTime: string}>
     */
    public static function pilihBuang(array $daftar, string $jenis, ?\Carbon\CarbonInterface $kini = null): array
    {
        $kini ??= now();
        if ($jenis === 'File') {
            return array_slice($daftar, self::SIMPAN_FILE);
        }
        $pertamaBulan = [];
        foreach (array_reverse($daftar) as $f) {                       // terlama dulu → yang pertama tiap bulan
            $pertamaBulan[substr($f['createdTime'], 0, 7)] ??= $f['id'];
        }
        $bulanSimpan = array_slice(array_values($pertamaBulan), -self::SIMPAN_BULAN_DB);

        return array_values(array_filter(array_slice($daftar, 1), fn ($f) => \Carbon\Carbon::parse($f['createdTime'])->lt($kini->copy()->subDays(self::SIMPAN_HARI_DB))
            && ! in_array($f['id'], $bulanSimpan, true)));
    }

    /** Buang cadangan lama ke sampah Drive (masih bisa dipulihkan 30 hari); kembalikan jumlahnya. */
    public function rapikan(string $jenis): int
    {
        $buang = self::pilihBuang($this->daftar($this->folder($jenis)), $jenis);
        foreach ($buang as $f) {
            $res = $this->google->http(30)->patch(self::API."/{$f['id']}", ['trashed' => true]);
            $this->pastikan($res, "membuang {$f['name']}");
        }

        return count($buang);
    }

    // ---------------------------------------------------------------- MySQL

    /** File kredensial sementara (0600) supaya kata sandi DB tidak muncul di daftar proses. */
    public static function fileKredensial(): string
    {
        $c = config('database.connections.mysql');
        $f = tempnam(sys_get_temp_dir(), 'mnp-my-');
        chmod($f, 0600);
        file_put_contents($f, "[client]\nuser=\"{$c['username']}\"\npassword=\"".addcslashes((string) $c['password'], '"\\')."\"\nhost=\"{$c['host']}\"\nport={$c['port']}\n");

        return $f;
    }

    /** mysqldump database aplikasi → $tujuan (.sql.gz). */
    public static function dumpDatabase(string $tujuan): void
    {
        $kred = self::fileKredensial();
        try {
            $db = escapeshellarg(config('database.connections.mysql.database'));
            $hasil = Process::timeout(1800)->run(['bash', '-o', 'pipefail', '-c',
                'mysqldump --defaults-extra-file='.escapeshellarg($kred)." --single-transaction --no-tablespaces --quick {$db} | gzip -6 > ".escapeshellarg($tujuan)]);
            if (! $hasil->successful()) {
                throw new RuntimeException('mysqldump gagal: '.mb_strimwidth(trim($hasil->errorOutput()), 0, 300, '…'));
            }
        } finally {
            @unlink($kred);
        }
    }

    /** Jalankan SQL lewat klien mysql (untuk database uji). */
    public static function mysql(string $sql, ?string $db = null): string
    {
        $kred = self::fileKredensial();
        try {
            $hasil = Process::timeout(120)->run(array_values(array_filter(['mysql', '--defaults-extra-file='.$kred, '-N', '-B', $db, '-e', $sql])));
            if (! $hasil->successful()) {
                throw new RuntimeException('mysql gagal: '.mb_strimwidth(trim($hasil->errorOutput()), 0, 300, '…'));
            }

            return $hasil->output();
        } finally {
            @unlink($kred);
        }
    }

    /** Pulihkan $fileGz ke database uji (dikosongkan dulu). */
    public static function pulihkanKeUji(string $fileGz): void
    {
        self::mysql('DROP DATABASE IF EXISTS `'.self::DB_UJI.'`; CREATE DATABASE `'.self::DB_UJI.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $kred = self::fileKredensial();
        try {
            $hasil = Process::timeout(3600)->run(['bash', '-o', 'pipefail', '-c',
                'gunzip -c '.escapeshellarg($fileGz).' | mysql --defaults-extra-file='.escapeshellarg($kred).' '.self::DB_UJI]);
            if (! $hasil->successful()) {
                throw new RuntimeException('Memulihkan ke database uji gagal: '.mb_strimwidth(trim($hasil->errorOutput()), 0, 300, '…'));
            }
        } finally {
            @unlink($kred);
        }
    }

    /** @return array<string, int> jumlah baris tepat per tabel */
    public static function hitungBaris(string $db): array
    {
        $tabel = array_filter(explode("\n", trim(self::mysql('SHOW TABLES', $db))));
        if (! $tabel) {
            return [];
        }
        $sql = implode(' UNION ALL ', array_map(fn ($t) => "SELECT '{$t}', COUNT(*) FROM `{$t}`", $tabel));
        $hasil = [];
        foreach (array_filter(explode("\n", trim(self::mysql($sql, $db)))) as $baris) {
            [$t, $n] = explode("\t", $baris);
            $hasil[$t] = (int) $n;
        }
        ksort($hasil);

        return $hasil;
    }

    // ---------------------------------------------------------------- Status

    public static function status(): array
    {
        $f = storage_path('app/cadangan-status.json');

        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    public static function simpanStatus(string $kunci, array $isi): void
    {
        $s = self::status();
        $s[$kunci] = $isi + ['waktu' => now()->toIso8601String()];
        file_put_contents(storage_path('app/cadangan-status.json'), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Catat ke riwayat (tampil di Riwayat; kegagalan juga muncul di Beranda lewat CekServer). */
    public static function riwayat(string $ringkasan, array $isi = []): void
    {
        KasRiwayat::create(['aksi' => 'cadangan', 'lembar' => 'Server', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => null,
            'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'), 'isi' => $isi]);
    }

    /** Masalah cadangan untuk banner Beranda Super Admin (lewat CekServer::periksa). @return string[] */
    public static function masalah(): array
    {
        $s = self::status();
        $m = [];
        $db = $s['db_berhasil']['waktu'] ?? null;
        if (! $db || \Carbon\Carbon::parse($db)->lt(now()->subHours(self::BATAS_JAM_DB))) {
            $m[] = 'Cadangan database ke Google Drive '.($db ? 'terakhir berhasil '.\Carbon\Carbon::parse($db)->diffForHumans() : 'belum pernah berhasil')
                .(isset($s['db_gagal']) && (! $db || $s['db_gagal']['waktu'] > $db) ? ' — galat: '.$s['db_gagal']['pesan'] : '').'.';
        }
        $uji = $s['uji']['waktu'] ?? null;
        if (! $uji || \Carbon\Carbon::parse($uji)->lt(now()->subDays(self::BATAS_HARI_UJI))) {
            $m[] = 'Uji pulih cadangan '.($uji ? 'terakhir '.\Carbon\Carbon::parse($uji)->diffForHumans() : 'belum pernah dijalankan').'.';
        } elseif (! ($s['uji']['lulus'] ?? false)) {
            $m[] = 'Uji pulih cadangan GAGAL: '.($s['uji']['pesan'] ?? '?');
        }

        return $m;
    }

    private function pastikan(Response $res, string $kegiatan): void
    {
        if ($res->successful()) {
            return;
        }
        $pesan = $res->json('error.message') ?? $res->body();
        throw new RuntimeException("Google Drive gagal saat {$kegiatan}: ".mb_strimwidth((string) $pesan, 0, 300, '…'));
    }
}
