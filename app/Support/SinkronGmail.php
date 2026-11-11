<?php

namespace App\Support;

use App\Models\PenawaranSpk;
use App\Models\ProyekTahap;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Ambil penawaran & SPK baru dari Gmail MNP (owner 11 Nov 2026):
 * - penawaran: email TERKIRIM berlampiran "… Penawaran …pdf" (biasanya ke Andre Maizon) — PDF teks (pdftotext);
 * - SPK: email dari spk_admasglr@yahoo.com — PDF scan, halaman 1–2 di-OCR (tesseract ind).
 * Hasil baca hanya isian awal: owner mengoreksi & menautkan SPK ke tahap di menu Penawaran & SPK.
 */
class SinkronGmail
{
    public const DARI_SPK = 'spk_admasglr@yahoo.com';

    private const BULAN = ['januari' => 1, 'februari' => 2, 'pebruari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7,
        'agustus' => 8, 'september' => 9, 'oktober' => 10, 'nopember' => 11, 'november' => 11, 'desember' => 12];

    /** @param  callable(string):void|null  $lapor */
    public static function jalankan(GmailMnp $gmail, string $sejak, ?callable $lapor = null): array
    {
        $lapor ??= fn () => null;
        $hasil = ['penawaran' => 0, 'spk' => 0];
        $q = ['spk' => 'from:'.self::DARI_SPK." has:attachment after:{$sejak}", 'penawaran' => "in:sent has:attachment penawaran after:{$sejak}"];
        foreach ($q as $jenis => $cari) {
            foreach (array_reverse($gmail->cari($cari, 1000)) as $id) {
                $kunci = "gmail-dibaca:{$jenis}:{$id}";
                if (Cache::has($kunci)) {
                    continue;
                }
                $pesan = $gmail->ringkas($id);
                usleep(250_000);
                foreach ($pesan['berkas'] as $b) {
                    if (! str_ends_with(strtolower($b['nama']), '.pdf') || PenawaranSpk::where('gmail_id', $id)->where('nama_file', $b['nama'])->exists()) {
                        continue;
                    }
                    if ($jenis === 'penawaran' && ! preg_match('/penawa?ran/i', $b['nama'])) {
                        continue;
                    }
                    $dok = self::simpan($gmail, $jenis, $pesan, $b);
                    if ($dok) {
                        $hasil[$jenis]++;
                        $lapor("{$jenis} {$dok->kode} · ".Str::limit((string) $dok->pekerjaan, 60).' · '.($dok->nilai ? number_format($dok->nilai, 0, ',', '.') : '-'));
                    }
                    usleep(250_000);
                }
                Cache::forever($kunci, 1);
            }
        }
        self::tautPenawaran();

        return $hasil;
    }

    private static function simpan(GmailMnp $gmail, string $jenis, array $pesan, array $b): ?PenawaranSpk
    {
        // Penawaran yang sama sering di-forward ulang: cukup satu baris per nomor penawaran.
        if ($jenis === 'penawaran' && ($n = self::kodePenawaranDariFile($b['nama'])) && PenawaranSpk::where('jenis', 'penawaran')->where('kode', $n)->exists()) {
            return null;
        }
        $isi = $gmail->lampiran($pesan['id'], $b['id']);
        $path = "penawaran-spk/{$pesan['id']}/".preg_replace('/[^A-Za-z0-9._ ()-]+/', '_', $b['nama']);
        Storage::put($path, $isi);
        $teks = $jenis === 'spk' ? self::ocr(Storage::path($path)) : self::teksPdf(Storage::path($path));

        $dok = new PenawaranSpk([
            'jenis' => $jenis, 'gmail_id' => $pesan['id'], 'gmail_thread' => $pesan['thread'], 'waktu_email' => $pesan['waktu'],
            'dari' => Str::limit($pesan['dari'], 195, ''), 'ke' => Str::limit($pesan['ke'], 295, ''), 'subjek' => Str::limit($pesan['subjek'], 295, ''),
            'nama_file' => $b['nama'], 'path' => $path, 'teks' => $teks,
        ]);
        $dok->fill($jenis === 'spk' ? self::uraiSpk($teks, $b['nama']) : self::uraiPenawaran($teks, $b['nama']));
        // Lampiran SPK untuk perusahaan lain (mis. PT. Naga Surya Indah) ikut terkirim — tidak dihitung.
        if ($jenis === 'spk' && ! preg_match('/multi\s*niaga/i', $b['nama'].' '.$teks)) {
            $dok->status = 'diabaikan';
            $dok->catatan = 'Bukan untuk PT. Multi Niaga Putra';
        }
        $dok->save();
        if ($jenis === 'spk' && $dok->status === 'baru') {
            self::sarankan($dok);
        }

        return $dok;
    }

    public static function teksPdf(string $file): string
    {
        $p = new Process(['pdftotext', '-layout', $file, '-']);
        $p->setTimeout(60)->run();

        return trim($p->getOutput());
    }

    /** OCR halaman 1–2 (isi SPK + rujukan penawaran ada di sana). */
    public static function ocr(string $file): string
    {
        $teks = self::teksPdf($file);
        if (mb_strlen($teks) > 200) {
            return $teks;
        }
        $dir = sys_get_temp_dir().'/ocr-'.Str::random(10);
        mkdir($dir);
        try {
            (new Process(['pdftoppm', '-r', '250', '-f', '1', '-l', '2', '-gray', '-png', $file, "{$dir}/h"]))->setTimeout(120)->run();
            $hasil = [];
            foreach (glob("{$dir}/h*.png") ?: [] as $png) {
                $p = new Process(['tesseract', $png, '-', '-l', 'ind+eng', '--psm', '6']);
                $p->setTimeout(180)->run();
                $hasil[] = $p->getOutput();
            }

            return trim(implode("\n\f\n", $hasil));
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    public static function kodePenawaranDariFile(string $nama): ?string
    {
        return preg_match('/^\s*(\d{2,4})\b/', $nama, $m) ? (string) (int) $m[1] : null;
    }

    /** Angka Indonesia "3.644,60" / "506.599.000" → float. */
    public static function angka(?string $s): ?float
    {
        $s = preg_replace('/[^\d.,]/', '', (string) $s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/,\d{1,3}$/', $s)) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s) || preg_match('/^\d{1,3}(,\d{3})+$/', $s)) {
            $s = str_replace(['.', ','], '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    public static function tanggal(?string $s): ?string
    {
        if (! $s || ! preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $s, $m) || ! ($b = self::BULAN[strtolower($m[2])] ?? null)) {
            return null;
        }

        return checkdate($b, (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $b, $m[1]) : null;
    }

    private static function satuBaris(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function uraiPenawaran(string $t, string $nama): array
    {
        $d = ['kode' => self::kodePenawaranDariFile($nama)];
        if (preg_match('/^\s*No\s*:\s*(\S+)/mi', $t, $m)) {
            $d['nomor'] = $m[1];
        }
        if (preg_match('/Jakarta\s*,\s*(\d{1,2}\s+\w+\s+\d{4})/i', $t, $m)) {
            $d['tanggal'] = self::tanggal($m[1]);
        }
        if (preg_match('/Kepada\s+Yth\.?\s*:?\s*(.+)/i', $t, $m)) {
            $d['pihak'] = Str::limit(self::satuBaris($m[1]), 195, '');
        }
        if (preg_match('/dilaksanakannya\s+(.+?)\.\s*Dengan/is', $t, $m)) {
            $d['pekerjaan'] = self::satuBaris($m[1]);
        }
        $d['lokasi'] = Str::limit(self::satuBaris(preg_replace(['/^\s*\d+[-\s]*(Rev\d*\s*)?/i', '/Penawa?ran\s+Pekerjaan\s+(Pekerjaan\s+)?(Volume\s+)?/i', '/\.pdf$/i'], '', $nama)), 245, '');
        $vol = 0;
        $harga = [];
        if (preg_match_all('/=\s*([\d.,]+)\s*(?:m3|m³)?\s*x\s*Rp\.?\s*([\d.,]+)\s*=\s*Rp\.?\s*([\d.,]+)/i', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $vol += (float) self::angka($m[1]);
                $harga[] = self::angka($m[2]);
            }
            $d['volume'] = $vol ?: null;
            $d['harga'] = count(array_unique($harga)) === 1 ? $harga[0] : null;
        }
        if (preg_match('/Dibulatkan\s*=?\s*Rp\.?\s*([\d.,]+)/i', $t, $m) || preg_match('/Total\s*=?\s*Rp\.?\s*([\d.,]+)/i', $t, $m)) {
            $d['nilai'] = (int) round((float) self::angka($m[1]));
        }

        return $d;
    }

    public static function uraiSpk(string $t, string $nama): array
    {
        $d = [];
        // Dari nama file: "CAM 4100006742 PT. Multi Niaga Putra (RI)Y.pdf", "BCU - 26 10 005 E0-3B PT. …"
        if (preg_match('/^(.+?)\s+PT\.?\s/i', $nama, $m)) {
            $d['kode'] = Str::limit(self::satuBaris($m[1]), 58, '');
        }
        if (preg_match('/No\s*[:.]?\s*([A-Z0-9]+\s*\/\s*[A-Z]+\s*\/[0-9A-Z \/.-]+?\d{4,})/i', $t, $m)) {
            $d['nomor'] = str_replace(' ', '', $m[1]);
        }
        if (preg_match('/Tanggal\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})/i', $t, $m)) {
            $d['tanggal'] = self::tanggal($m[1]);
        }
        if (preg_match('/kami\s+(PT\.?\s+.+?)\s+yang/is', $t, $m)) {
            $d['pihak'] = Str::limit(self::satuBaris($m[1]), 195, '');
        }
        if (preg_match('/Nama\s+pekerjaan\s*[:;]?\s*(.+?)(?:\n\s*2\s*[.,]|Lokasi\s+pekerjaan)/is', $t, $m)) {
            $d['pekerjaan'] = self::satuBaris($m[1]);
        }
        if (preg_match('/Lokasi\s+pekerjaan\s*[:;]?\s*(.+)/i', $t, $m)) {
            $d['lokasi'] = Str::limit(self::satuBaris($m[1]), 245, '');
        }
        if (preg_match('/Biaya\s+pekerjaan\s*[:;]?\s*Rp\.?\s*([\d.,]+)/i', $t, $m) || preg_match('/DIBULATKAN\s+Rp\.?\s*([\d.,]+)/i', $t, $m)) {
            $d['nilai'] = (int) round((float) self::angka($m[1]));
        }
        // Baris tabel: "m³ 3.644,60 139.000 Rp 506.599.400" (OCR bisa membaca m³ sebagai m3/m?/rn3)
        if (preg_match('/\b(?:m3|m³|m\?|rn3|m°)\s*\|?\s*([\d.,]+)\s*\|?\s*([\d.,]+)\s*\|?\s*Rp/i', $t, $m)) {
            $d['volume'] = self::angka($m[1]);
            $d['harga'] = self::angka($m[2]);
        }
        if (preg_match('/Penawaran\s+Harga\s+No\.?\s*[:.]?\s*(\d{2,4})\s*\//i', $t, $m)) {
            $d['kode_penawaran'] = (string) (int) $m[1];
        }

        return $d;
    }

    /** SPK ↔ penawaran lewat nomor penawaran yang dirujuk SPK. */
    public static function tautPenawaran(): void
    {
        foreach (PenawaranSpk::where('jenis', 'spk')->whereNotNull('kode_penawaran')->whereNull('penawaran_id')->get() as $spk) {
            $p = PenawaranSpk::where('jenis', 'penawaran')->where('kode', $spk->kode_penawaran)->first();
            if ($p) {
                $spk->update(['penawaran_id' => $p->id]);
            }
        }
    }

    /** Saran tahap: kata lokasi/pekerjaan SPK yang juga ada di lokasi/nama tahap ASG yang berjalan atau baru selesai. */
    public static function sarankan(PenawaranSpk $dok): void
    {
        $kata = fn (?string $s) => collect(preg_split('/[^a-z0-9]+/', strtolower((string) $s)))
            ->reject(fn ($w) => strlen($w) < 4 || in_array($w, ['tanah', 'urugan', 'merah', 'super', 'klas', 'untuk', 'area', 'lokasi', 'sedayu', 'indo', 'city', 'pekerjaan', 'landscape', 'tahap', 'volume', 'biasa'], true))
            ->unique();
        $cari = $kata($dok->lokasi.' '.$dok->pekerjaan);
        $terbaik = null;
        foreach (self::calonTahap() as $t) {
            $sama = $cari->intersect($kata($t->lokasi.' '.$t->nama));
            if ($sama->isNotEmpty() && (! $terbaik || $sama->count() > $terbaik[1]->count())) {
                $terbaik = [$t, $sama];
            }
        }
        if ($terbaik) {
            $dok->update(['saran_tahap_id' => $terbaik[0]->id, 'saran_alasan' => 'Kata sama: '.$terbaik[1]->implode(', ')]);
        }
    }

    /** Tahap yang mungkin dituju SPK: semua tahap berjalan + tahap yang punya ritasi 12 bulan terakhir. */
    public static function calonTahap()
    {
        $aktif = \App\Models\Ritasi::where('tanggal', '>=', now()->subMonths(12)->toDateString())->distinct()->pluck('tahap')->filter();

        return ProyekTahap::with('proyek')->where('status', '!=', 'batal')
            ->where(fn ($q) => $q->where('status', 'progress')->orWhereIn('nama', $aktif))
            ->get()->sort(fn ($a, $b) => (($a->status !== 'progress') <=> ($b->status !== 'progress')) ?: strnatcasecmp($a->nama, $b->nama))->values();
    }

    /** Omset tahap = jumlah nilai SPK yang ditautkan; volume = jumlah volume; harga/m³ bila semua SPK berharga sama. */
    public static function hitungTahap(ProyekTahap $t): ?array
    {
        $spk = PenawaranSpk::where('jenis', 'spk')->where('status', 'ditautkan')->where('proyek_tahap_id', $t->id)->get();
        if ($spk->isEmpty()) {
            return null;
        }
        $harga = $spk->pluck('harga')->filter()->unique();
        $baru = ['omset' => (int) $spk->sum('nilai') ?: null, 'volume' => $spk->sum('volume') ?: null];
        if ($harga->count() === 1) {
            $baru['harga_m3'] = $harga->first();
        }

        return $baru;
    }

    public static function terakhir(): string
    {
        $w = PenawaranSpk::max('waktu_email');

        return ($w ? Carbon::parse($w)->subDays(7) : now()->subDays(30))->format('Y/m/d');
    }
}
