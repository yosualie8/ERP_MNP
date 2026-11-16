<?php

namespace App\Support;

use App\Models\GoogleIntegrasi;
use App\Models\MutasiPt;
use App\Models\MutasiPtUnggah;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use ZipArchive;

/**
 * ERP → file xlsx Drive "Mutasi Rek PT Gabungan MNP" (config mnp.mutasi_pt_file), lembar "Mutasi Rekening PT" (owner 16 Nov 2026).
 * Sejak mutasi PT berasal dari upload KlikBCA, transaksi baru di ERP DITAMBAHKAN di bawah baris terakhir lembar itu:
 * - hanya menambah (baris lama & lembar lain tidak disentuh), transaksi PEND tidak ditulis (menunggu dibukukan bank);
 * - titik sambung = transaksi terakhir di lembar; transaksi ERP di tanggal itu yang sudah ada di lembar dilewati;
 * - saldo wajib bersambung dari saldo terakhir lembar — bila putus, hanya baris sebelum putus yang ditulis;
 * - xlsx diubah langsung di XML (gaya baris disalin dari baris data terakhir) supaya pivot/format lembar lain utuh;
 * - file batal diunggah bila berubah di Drive selama proses.
 * Dijalankan sesudah upload mutasi (afterResponse) dan tiap 30 menit (mnp:impor-mutasi-pt) sebagai cadangan.
 */
class TulisMutasiPtDrive
{
    public const KUNCI = 'mutasi-pt-drive'; // cache: hasil terakhir (status, pesan, waktu, sampai, ditambah, tanda, diubah)

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Seperti jalankan(), tetapi kesalahan dicatat ke cache & log, tidak dilempar (untuk afterResponse / jadwal). */
    public static function jalankanAman(bool $paksa = false): array
    {
        try {
            return self::jalankan($paksa);
        } catch (\Throwable $e) {
            report($e);

            return self::simpan(['status' => 'gagal', 'pesan' => $e->getMessage()]);
        }
    }

    /** @return array{status: string, pesan: string, ditambah?: int, sampai?: string} */
    public static function jalankan(bool $paksa = false): array
    {
        if (! MutasiPtUnggah::exists()) {
            return ['status' => 'nonaktif', 'pesan' => 'Belum ada upload KlikBCA — sumber data masih file Drive.'];
        }
        $kunci = Cache::lock(self::KUNCI.'-kunci', 600);
        if (! $kunci->get()) {
            return ['status' => 'sibuk', 'pesan' => 'Penulisan ke sheet sedang berjalan.'];
        }
        try {
            return self::tulis($paksa);
        } finally {
            $kunci->release();
        }
    }

    private static function tulis(bool $paksa): array
    {
        $id = config('mnp.mutasi_pt_file');
        $f = GoogleIntegrasi::foto();
        if (! $id || ! $f) {
            throw new RuntimeException('File mutasi PT belum diatur / akun Drive (ptmultiniagaputra) belum terhubung.');
        }
        $g = new GoogleSheets($f);
        $url = "https://www.googleapis.com/drive/v3/files/{$id}";
        $meta = $g->http(30)->get($url, ['fields' => 'name,modifiedTime', 'supportsAllDrives' => 'true']);
        if (! $meta->successful()) {
            throw new RuntimeException('Tidak bisa membaca file Drive mutasi PT: '.$meta->status());
        }
        // Lewati bila ERP & file Drive tidak berubah sejak penulisan terakhir.
        $tanda = MutasiPt::where('pending', false)->count().'|'.MutasiPt::max('id');
        $lalu = Cache::get(self::KUNCI);
        if (! $paksa && ($lalu['tanda'] ?? null) === $tanda && ($lalu['diubah'] ?? null) === $meta->json('modifiedTime') && in_array($lalu['status'] ?? '', ['ditambah', 'sama'], true)) {
            return $lalu;
        }

        $isi = $g->http(180)->get($url, ['alt' => 'media', 'supportsAllDrives' => 'true']);
        if (! $isi->successful()) {
            throw new RuntimeException('Gagal mengunduh file mutasi PT: '.$isi->status());
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mutasi-pt-tulis-').'.xlsx';
        file_put_contents($tmp, $isi->body());
        try {
            $lama = ImporMutasiPt::urai($tmp);
            if (! $lama) {
                throw new RuntimeException('Lembar "'.ImporMutasiPt::LEMBAR.'" kosong / tidak ditemukan.');
            }
            [$tambah, $putus] = self::barisBaru($lama);
            $akhir = end($lama);
            if (! $tambah) {
                return self::simpan(['status' => $putus ? 'putus' : 'sama', 'pesan' => $putus ?: 'Sheet sudah sama dengan ERP.',
                    'sampai' => $akhir['tanggal'], 'tanda' => $tanda, 'diubah' => $meta->json('modifiedTime')]);
            }
            self::tambahKeXlsx($tmp, $tambah);

            // Periksa hasil sebelum diunggah: baris lama utuh, baris baru = ERP.
            $hasil = ImporMutasiPt::urai($tmp);
            if (count($hasil) !== count($lama) + count($tambah)) {
                throw new RuntimeException('Pemeriksaan xlsx gagal (jumlah baris tidak sesuai) — file tidak diunggah.');
            }
            foreach ($tambah as $i => $m) {
                $h = $hasil[count($lama) + $i];
                if ($h['tanggal'] !== $m->tanggal->toDateString() || $h['dbcr'] !== $m->dbcr || abs($h['jumlah'] - $m->jumlah) > 0.001 || abs((float) $h['saldo'] - $m->saldo) > 0.001) {
                    throw new RuntimeException('Pemeriksaan xlsx gagal (isi baris baru berbeda) — file tidak diunggah.');
                }
            }

            $kini = $g->http(30)->get($url, ['fields' => 'modifiedTime', 'supportsAllDrives' => 'true'])->json('modifiedTime');
            if ($kini !== $meta->json('modifiedTime')) {
                throw new RuntimeException('File Drive diubah orang lain selama proses — dicoba lagi nanti.');
            }
            $up = $g->http(300)->withBody(file_get_contents($tmp), self::XLSX)
                ->patch("https://www.googleapis.com/upload/drive/v3/files/{$id}?uploadType=media&supportsAllDrives=true&fields=id,modifiedTime");
            if (! $up->successful()) {
                throw new RuntimeException('Gagal mengunggah file ke Drive: '.$up->json('error.message', (string) $up->status()));
            }
        } finally {
            @unlink($tmp);
        }
        $n = count($tambah);
        $sampai = end($tambah)->tanggal->toDateString();

        return self::simpan(['status' => 'ditambah', 'ditambah' => $n, 'sampai' => $sampai, 'tanda' => $tanda, 'diubah' => $up->json('modifiedTime'),
            'pesan' => "{$n} transaksi ditambahkan ke sheet (s/d ".\Illuminate\Support\Carbon::parse($sampai)->translatedFormat('j M Y').').'.($putus ? ' '.$putus : '')]);
    }

    /**
     * Transaksi ERP (bukan PEND) sesudah transaksi terakhir lembar, berurutan, saldo bersambung.
     *
     * @param  array<int, array<string, mixed>>  $lama  hasil ImporMutasiPt::urai
     * @return array{0: MutasiPt[], 1: ?string} [baris baru, pesan bila saldo putus]
     */
    public static function barisBaru(array $lama): array
    {
        $akhir = end($lama);
        $k = fn ($dbcr, $jumlah, $saldo) => $dbcr.'|'.number_format((float) $jumlah, 2, '.', '').'|'.number_format((float) $saldo, 2, '.', '');
        // Transaksi tanggal terakhir yang sudah ada di lembar.
        $ada = collect($lama)->where('tanggal', $akhir['tanggal'])->map(fn ($b) => $k($b['dbcr'], $b['jumlah'], $b['saldo']))->countBy()->all();
        $tambah = [];
        $saldo = (float) $akhir['saldo'];
        foreach (MutasiPt::where('tanggal', '>=', $akhir['tanggal'])->where('pending', false)->orderBy('tanggal')->orderBy('baris')->get() as $m) {
            $kk = $k($m->dbcr, $m->jumlah, $m->saldo);
            if ($m->tanggal->toDateString() === $akhir['tanggal'] && ($ada[$kk] ?? 0) > 0) {
                $ada[$kk]--;

                continue;
            }
            $saldo += $m->dbcr === 'CR' ? $m->jumlah : -$m->jumlah;
            if ($m->saldo === null || abs($saldo - $m->saldo) > 0.01) {
                return [$tambah, 'Saldo tidak bersambung pada '.$m->tanggal->translatedFormat('j M Y').' (seharusnya '.rp((int) round($saldo)).', bank '
                    .rp((int) round((float) $m->saldo)).') — sebagian mutasi belum diupload atau sheet diubah manual; transaksi sesudahnya belum ditulis.'];
            }
            $tambah[] = $m;
        }

        return [$tambah, null];
    }

    /** Sisipkan baris di bawah baris data terakhir lembar (XML langsung; baris kosong sesudahnya digeser ke bawah). */
    private static function tambahKeXlsx(string $file, array $tambah): void
    {
        $zip = new ZipArchive;
        if ($zip->open($file) !== true) {
            throw new RuntimeException('File xlsx tidak bisa dibuka.');
        }
        try {
            $path = self::pathLembar($zip, ImporMutasiPt::LEMBAR);
            $sx = $zip->getFromName($path);
            $ss = $zip->getFromName('xl/sharedStrings.xml');
            if ($sx === false || $ss === false) {
                throw new RuntimeException('Struktur xlsx tidak dikenal (lembar / sharedStrings).');
            }
            $awalData = strpos($sx, '<sheetData>');
            $akhirData = strpos($sx, '</sheetData>');
            if ($awalData === false || $akhirData === false) {
                throw new RuntimeException('Struktur xlsx tidak dikenal (sheetData).');
            }
            // Baris data terakhir = <row> terakhir yang berisi nilai.
            preg_match_all('/<row\b([^>]*?)(?:\/>|>(.*?)<\/row>)/s', substr($sx, $awalData, $akhirData - $awalData), $rows, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $terakhir = null;
            foreach ($rows as $r) {
                if (isset($r[2]) && preg_match('/<v>|<is>|<f[ >]/', $r[2][0])) {
                    $terakhir = $r;
                }
            }
            if (! $terakhir || ! preg_match('/\sr="(\d+)"/', $terakhir[1][0], $no)) {
                throw new RuntimeException('Baris data terakhir tidak ditemukan.');
            }
            $R = (int) $no[1];
            $posSesudah = $awalData + $terakhir[0][1] + strlen($terakhir[0][0]);
            $ekor = substr($sx, $posSesudah, $akhirData - $posSesudah);
            // Gaya sel A-F disalin dari baris data terakhir.
            $gaya = [];
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $kol) {
                $gaya[$kol] = preg_match('/<c r="'.$kol.$R.'"[^>]*\ss="(\d+)"/', $terakhir[0][0], $g) ? ' s="'.$g[1].'"' : '';
            }

            // Shared strings: indeks DB / CR yang sudah ada, keterangan ditambahkan di akhir.
            preg_match_all('/<si>(.*?)<\/si>/s', $ss, $si);
            $unik = count($si[1]);
            $idx = [];
            foreach ($si[1] as $i => $isi) {
                $t = html_entity_decode(strip_tags($isi), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (($t === 'DB' || $t === 'CR') && ! isset($idx[$t])) {
                    $idx[$t] = $i;
                }
            }
            $siBaru = '';
            $str = function (string $t) use (&$siBaru, &$unik) {
                $siBaru .= '<si><t xml:space="preserve">'.htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';

                return $unik++;
            };
            foreach (['DB', 'CR'] as $t) {
                $idx[$t] ??= $str($t);
            }

            $xml = '';
            $r = $R;
            $nilai = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
            foreach ($tambah as $m) {
                $r++;
                $serial = (int) \Illuminate\Support\Carbon::create(1899, 12, 30)->diffInDays($m->tanggal->copy()->startOfDay());
                $xml .= "<row r=\"{$r}\" ht=\"15.0\" customHeight=\"1\">"
                    ."<c r=\"A{$r}\"{$gaya['A']}><v>{$serial}</v></c>"
                    ."<c r=\"B{$r}\"{$gaya['B']} t=\"s\"><v>".$str((string) $m->keterangan).'</v></c>'
                    ."<c r=\"C{$r}\"{$gaya['C']}/>"
                    ."<c r=\"D{$r}\"{$gaya['D']} t=\"s\"><v>".$idx[$m->dbcr].'</v></c>'
                    ."<c r=\"E{$r}\"{$gaya['E']}><v>".$nilai($m->jumlah).'</v></c>'
                    ."<c r=\"F{$r}\"{$gaya['F']}><v>".$nilai($m->saldo).'</v></c></row>';
            }
            // Baris kosong (berformat) sesudah data digeser sebanyak baris yang ditambahkan.
            $n = count($tambah);
            $ekor = preg_replace_callback('/(<(?:row|c) [^>]*r=")([A-Z]*)(\d+)"/', fn ($x) => $x[1].$x[2].((int) $x[3] + $n).'"', $ekor);
            $sx = substr($sx, 0, $posSesudah).$xml.$ekor.substr($sx, $akhirData);
            $maks = $r;
            $sx = preg_replace_callback('/<dimension ref="([A-Z]+\d+):([A-Z]+)(\d+)"/', fn ($x) => '<dimension ref="'.$x[1].':'.$x[2].max((int) $x[3] + $n, $maks).'"', $sx, 1);

            $ss = preg_replace_callback('/<sst\b[^>]*>/', function ($x) use ($unik, $n) {
                $tag = preg_replace_callback('/\scount="(\d+)"/', fn ($c) => ' count="'.((int) $c[1] + 3 * $n).'"', $x[0]);

                return preg_replace('/\suniqueCount="\d+"/', ' uniqueCount="'.$unik.'"', $tag);
            }, $ss, 1);
            $ss = str_replace('</sst>', $siBaru.'</sst>', $ss);

            $zip->addFromString($path, $sx);
            $zip->addFromString('xl/sharedStrings.xml', $ss);
        } finally {
            $zip->close();
        }
    }

    private static function pathLembar(ZipArchive $zip, string $nama): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! preg_match('/<sheet [^>]*name="'.preg_quote(htmlspecialchars($nama, ENT_XML1 | ENT_QUOTES), '/').'"[^>]*r:id="([^"]+)"/', (string) $wb, $s)
            || ! preg_match('/<Relationship [^>]*Id="'.preg_quote($s[1], '/').'"[^>]*Target="([^"]+)"|<Relationship [^>]*Target="([^"]+)"[^>]*Id="'.preg_quote($s[1], '/').'"/', (string) $rels, $t)) {
            throw new RuntimeException('Lembar "'.$nama.'" tidak ditemukan di xlsx.');
        }
        $target = ltrim(($t[1] ?? '') ?: $t[2], '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    private static function simpan(array $h): array
    {
        $h['waktu'] = now()->toDateTimeString();
        Cache::forever(self::KUNCI, $h);

        return $h;
    }
}
