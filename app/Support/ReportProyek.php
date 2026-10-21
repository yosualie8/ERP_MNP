<?php

namespace App\Support;

use App\Models\KasRiwayat;
use App\Models\ProyekAlias;
use App\Models\ProyekTahap;
use App\Models\Ritasi;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use ZipArchive;

/**
 * Report Proyek (laporan tagihan proyek ke Sedayu, mis. "Report Proyek ASG - 20261021.xlsx" di Drive): bagian "Proyek Masih
 * Dikerjakan (Biaya yang Sudah Keluar)" kolom Nilai diisi biaya yang sudah tercatat di ERP per tahap = ritasi (harga jual) +
 * ngepok + solar + rental alat (jam HM) + operasional Kas Harian — sama dengan Anggaran::rincianRealisasi, tanpa biaya umum
 * teralokasi (owner 21 Okt 2026). Bagian proyek selesai (nilai tagihan) & kolom lain tidak disentuh; SubTotal / total dihitung ulang.
 * File Excel (.xlsx) di Drive disunting langsung di XML-nya supaya format tetap; Google Sheet ditulis lewat Sheets API.
 */
class ReportProyek
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const GSHEET = 'application/vnd.google-apps.spreadsheet';

    private const DRIVE = 'https://www.googleapis.com/drive/v3/files';

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** Sumber ProyekAlias untuk nama baris di Report Proyek. */
    public const SUMBER = 'report_proyek';

    public static function idDari(string $link): string
    {
        foreach (['#/spreadsheets/d/([A-Za-z0-9_-]+)#', '#/file/d/([A-Za-z0-9_-]+)#', '#[?&]id=([A-Za-z0-9_-]+)#'] as $pola) {
            if (preg_match($pola, $link, $m)) {
                return $m[1];
            }
        }
        if (! preg_match('/^[A-Za-z0-9_-]{20,}$/', $id = trim($link))) {
            throw new InvalidArgumentException('Link / ID file Report Proyek tidak dikenali.');
        }

        return $id;
    }

    /**
     * Pratinjau ($tulis = false) atau tulis biaya ERP ke file.
     *
     * @param  array<string, string>  $pemetaan  nama baris di sheet => nama tahap ERP (disimpan sebagai nama lain saat menulis)
     */
    public static function jalankan(string $link, bool $tulis, array $pemetaan = [], ?int $userId = null): array
    {
        // Koneksi Google "foto" (akun perusahaan, izin Drive penuh) bisa menimpa file Excel di Drive; koneksi sheet hanya drive.readonly.
        $foto = \App\Models\GoogleIntegrasi::foto();
        $g = $foto ? new GoogleSheets($foto) : GoogleSheets::wajib();
        $id = self::idDari($link);
        $meta = $g->http(30)->get(self::DRIVE."/{$id}", ['fields' => 'id,name,mimeType,modifiedTime,capabilities(canEdit)', 'supportsAllDrives' => 'true']);
        if (! $meta->successful()) {
            throw new InvalidArgumentException('File tidak bisa dibuka dengan akun Google ERP ('.$g->email().'): '.$meta->json('error.message', (string) $meta->status()));
        }
        $mime = $meta->json('mimeType');
        $xlsx = $mime === self::XLSX;
        if (! $xlsx && $mime !== self::GSHEET) {
            throw new InvalidArgumentException("File \"{$meta->json('name')}\" bukan Excel / Google Sheet ({$mime}).");
        }
        if ($tulis && ! $meta->json('capabilities.canEdit')) {
            throw new InvalidArgumentException('Akun Google ERP ('.$g->email().') tidak punya izin edit file ini.');
        }

        // ===== Baca lembar pertama: nilai + rumus per sel =====
        $zip = $tmp = $dom = $pathLembar = null;
        if ($xlsx) {
            $tmp = tempnam(sys_get_temp_dir(), 'report');
            file_put_contents($tmp, $g->http(120)->get(self::DRIVE."/{$id}", ['alt' => 'media', 'supportsAllDrives' => 'true'])->throw()->body());
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                throw new InvalidArgumentException('File Excel tidak bisa dibuka (rusak?).');
            }
            [$judul, $pathLembar] = self::lembarPertama($zip);
            $dom = new DOMDocument;
            $dom->loadXML($zip->getFromName($pathLembar));
            [$nilai, $rumus] = self::bacaXlsx($dom, self::teksBersama($zip));
        } else {
            $judul = $g->info($id)['sheets'][0]['properties']['title'];
            [$nilai, $rumus] = self::bacaGsheet($g, $id, $judul);
        }

        try {
            $hasil = self::susun($nilai, $rumus, $judul, $pemetaan);
            $hasil = ['file' => ['id' => $id, 'nama' => $meta->json('name'), 'jenis' => $xlsx ? 'Excel (.xlsx) di Drive' : 'Google Sheet',
                'diubah_terakhir' => $meta->json('modifiedTime'), 'lembar' => $judul], ...$hasil];
            if (! $tulis) {
                return ['mode' => 'pratinjau — belum ada yang ditulis; panggil lagi dengan tulis=true untuk menulis', ...$hasil];
            }
            if (! $hasil['ubah']) {
                return ['mode' => 'tulis — tidak ada nilai yang berubah, file tidak disentuh', ...$hasil];
            }
            if ($xlsx) {
                foreach ($hasil['ubah'] as $sel => $v) {
                    self::isiSelXlsx($dom, $sel, $v);
                }
                $zip->addFromString($pathLembar, $dom->saveXML());
                // Excel menghitung ulang semua rumus saat dibuka (nilai tersimpan rumus SubTotal sudah diperbarui di atas).
                $wb = new DOMDocument;
                $wb->loadXML($zip->getFromName('xl/workbook.xml'));
                $calc = $wb->getElementsByTagNameNS(self::NS, 'calcPr')->item(0) ?? $wb->documentElement->appendChild($wb->createElementNS(self::NS, 'calcPr'));
                $calc->setAttribute('fullCalcOnLoad', '1');
                $zip->addFromString('xl/workbook.xml', $wb->saveXML());
                $zip->close();
                $zip = null;
                $up = $g->http(180)->withBody(file_get_contents($tmp), self::XLSX)
                    ->patch("https://www.googleapis.com/upload/drive/v3/files/{$id}?uploadType=media&supportsAllDrives=true&fields=id,modifiedTime");
                if (! $up->successful()) {
                    throw new InvalidArgumentException('Gagal mengunggah file ke Drive: '.$up->json('error.message', (string) $up->status()));
                }
            } else {
                // Google Sheet menghitung rumusnya sendiri: hanya sel isian (bukan rumus) yang ditulis.
                $g->tulis($id, collect($hasil['ubah'])->reject(fn ($v, $sel) => isset($rumus[$sel]))->mapWithKeys(fn ($v, $sel) => ["{$judul}!{$sel}" => [[$v]]])->all());
            }
        } finally {
            $zip?->close();
            if ($tmp) {
                @unlink($tmp);
            }
        }

        foreach ($hasil['pemetaan_dipakai'] as $nama => $tahapId) {
            ProyekAlias::updateOrCreate(['sumber' => self::SUMBER, 'kunci' => ProyekAlias::kunci($nama)], ['nama' => $nama, 'proyek_tahap_id' => $tahapId]);
        }
        $berubah = collect($hasil['baris'])->filter(fn ($b) => $b['nilai_baru'] !== null && $b['nilai_baru'] !== $b['nilai_lama']);
        KasRiwayat::create(['aksi' => 'report-proyek', 'lembar' => 'Report', 'baris_awal' => (int) ($berubah->min('baris') ?? 0), 'baris_akhir' => (int) ($berubah->max('baris') ?? 0),
            'ringkasan' => mb_strimwidth("Update biaya Report Proyek \"{$meta->json('name')}\": {$berubah->count()} proyek · SubTotal ".number_format((float) $hasil['subtotal']['lama'], 0, ',', '.')
                .' → '.number_format((float) $hasil['subtotal']['baru'], 0, ',', '.'), 0, 490, '…'),
            'isi' => ['file' => $id, 'nama' => $meta->json('name'), 'sel' => collect($hasil['ubah'])->map(fn ($v, $sel) => ['lama' => $nilai[$sel] ?? null, 'baru' => $v])->all()],
            'user_id' => $userId]);

        return ['mode' => 'tulis — sudah ditulis ke file (versi sebelumnya tetap ada di riwayat versi Drive)', ...$hasil];
    }

    /**
     * Cari bagian "Proyek Masih Dikerjakan", cocokkan tiap baris ke tahap ERP, hitung biaya & sel yang berubah.
     *
     * @param  array<string, mixed>  $nilai  sel A1 => nilai
     * @param  array<string, string>  $rumus  sel A1 => rumus (tanpa "=")
     */
    private static function susun(array $nilai, array $rumus, string $judul, array $pemetaan): array
    {
        $teks = fn (int $r, string $k = 'A') => trim((string) ($nilai["{$k}{$r}"] ?? ''));
        $barisMaks = collect(array_keys($nilai + $rumus))->map(fn ($s) => (int) preg_replace('/\D/', '', $s))->max() ?? 0;
        $mulai = collect(range(1, max(1, $barisMaks)))->first(fn ($r) => stripos($teks($r), 'masih dikerjakan') !== false);
        if (! $mulai) {
            throw new InvalidArgumentException("Bagian \"Proyek Masih Dikerjakan\" tidak ditemukan di lembar {$judul} (kolom A).");
        }
        $kepala = collect(range($mulai + 1, $mulai + 3))->first(fn ($r) => strcasecmp($teks($r), 'Proyek') === 0);
        if (! $kepala) {
            throw new InvalidArgumentException("Baris judul kolom (\"Proyek\") di bawah \"Proyek Masih Dikerjakan\" (baris {$mulai}) tidak ditemukan.");
        }
        $kol = collect(range('A', 'Z'))->first(fn ($k) => strcasecmp($teks($kepala, $k), 'Nilai') === 0);
        if (! $kol) {
            throw new InvalidArgumentException("Kolom \"Nilai\" tidak ditemukan di baris judul {$kepala}.");
        }

        $tahap = ProyekTahap::with('proyek')->get();
        $alias = ProyekAlias::where('sumber', self::SUMBER)->get()->keyBy('kunci');
        $peta = [];
        $galat = [];
        $lewati = [];
        foreach ($pemetaan as $nama => $tujuan) {
            // "-" = baris ini sengaja tidak diisi dari ERP (berlaku untuk panggilan ini saja).
            if (in_array(trim((string) $tujuan), ['-', 'lewati'], true)) {
                $lewati[ProyekAlias::kunci($nama)] = true;

                continue;
            }
            $t = ctype_digit((string) $tujuan) ? $tahap->firstWhere('id', (int) $tujuan)
                : $tahap->first(fn ($t) => ProyekAlias::kunci($t->nama) === ProyekAlias::kunci((string) $tujuan));
            if ($t) {
                $peta[ProyekAlias::kunci($nama)] = [$nama, $t];
            } else {
                $galat[] = "Pemetaan \"{$nama}\": tahap ERP \"{$tujuan}\" tidak ditemukan.";
            }
        }
        if ($galat) {
            throw new InvalidArgumentException(implode(' ', $galat));
        }

        [$ritasi, $kas, $bbm] = [DaftarProyek::ritasi(), DaftarProyek::kas(), DaftarProyek::bbm()];
        $baru = [];
        $barisHasil = [];
        $dipakai = [];
        $akhir = null;
        for ($r = $kepala + 1; $r <= $barisMaks; $r++) {
            $nama = $teks($r);
            if (stripos($nama, 'subtotal') === 0 || stripos($nama, 'sub total') === 0) {
                $akhir = $r;
                break;
            }
            if ($nama === '') {
                continue;
            }
            $lama = is_numeric($nilai["{$kol}{$r}"] ?? null) ? (float) $nilai["{$kol}{$r}"] : null;
            if (isset($lewati[ProyekAlias::kunci($nama)])) {
                $barisHasil[] = ['baris' => $r, 'nama_di_sheet' => $nama, 'tahap_erp' => null, 'nilai_lama' => $lama, 'nilai_baru' => null, 'catatan' => 'dilewati (pemetaan "-") — tidak diubah'];

                continue;
            }
            [$t, $calon, $cara] = isset($peta[ProyekAlias::kunci($nama)])
                ? [$peta[ProyekAlias::kunci($nama)][1], [], 'pemetaan']
                : self::cocokkan($nama, $tahap, $alias);
            if ($cara === 'pemetaan') {
                $dipakai[$nama] = $t->id;
            }
            if (! $t || isset($rumus["{$kol}{$r}"])) {
                $barisHasil[] = ['baris' => $r, 'nama_di_sheet' => $nama, 'tahap_erp' => null, 'nilai_lama' => $lama, 'nilai_baru' => null,
                    'catatan' => isset($rumus["{$kol}{$r}"]) ? 'sel Nilai berisi rumus — tidak diubah'
                        : ($calon ? 'lebih dari satu tahap cocok ('.implode(', ', $calon).') — tentukan lewat "pemetaan"' : 'tahap ERP tidak dikenali — tidak diubah; tentukan lewat "pemetaan"')];

                continue;
            }
            $c = \App\Support\Anggaran::rincianRealisasi($t, $ritasi, $kas, $bbm);
            $biaya = (int) round(array_sum(array_column($c, 1)));
            $rt = $ritasi['per_tahap'][$t->id] ?? [];
            $baru["{$kol}{$r}"] = $biaya;
            $barisHasil[] = ['baris' => $r, 'nama_di_sheet' => $nama, 'tahap_erp' => $t->nama, 'status_erp' => $t->status, 'dicocokkan' => $cara,
                'nilai_lama' => $lama, 'nilai_baru' => $biaya, 'selisih' => $lama === null ? null : $biaya - (int) round($lama),
                'rincian' => [
                    'ritasi' => ['rit' => (int) $c['rit'][0], 'm3' => (float) ($rt['m3'] ?? 0), 'rp' => (int) $c['rit'][1], 'rit_terakhir' => $rt['selesai'] ?? null],
                    'ngepok' => ['rit' => (int) $c['ngepok'][0], 'rp' => (int) $c['ngepok'][1]],
                    'solar' => ['liter' => round((float) $c['solar'][0], 2), 'rp' => (int) $c['solar'][1]],
                    'rental_alat' => ['jam' => round((float) $c['rental'][0], 2), 'rp' => (int) $c['rental'][1]],
                    'operasional_kas' => ['transaksi' => (int) $c['kas'][0], 'rp' => (int) $c['kas'][1]],
                ]];
        }

        // Tanggal "Update Ritasi Terakhir" (bila ada di baris atas) = tanggal ritasi terakhir di ERP, sebagai nomor seri Excel.
        $tanggal = null;
        foreach (range(1, min(5, $barisMaks)) as $r) {
            if (stripos($teks($r), 'update') !== false && stripos($teks($r), 'ritasi') !== false) {
                $tgl = Ritasi::max('tanggal');
                if ($tgl) {
                    $tanggal = ['sel' => "B{$r}", 'tanggal' => Carbon::parse($tgl)->toDateString()];
                    $baru["B{$r}"] = (int) Carbon::parse('1899-12-30')->diffInDays(Carbon::parse($tgl)->startOfDay());
                }
                break;
            }
        }

        // Hitung ulang rumus sederhana di kolom Nilai (SUM(Cx:Cy), Cx+Cy-…) dengan nilai baru — urut baris.
        $sekarang = array_replace($nilai, $baru);
        $subLama = $subBaru = null;
        $rumusKol = collect($rumus)->filter(fn ($f, $sel) => preg_match('/^'.$kol.'(\d+)$/', $sel))->sortBy(fn ($f, $sel) => (int) substr($sel, strlen($kol)));
        foreach ($rumusKol as $sel => $f) {
            $v = self::hitungRumus($f, $sekarang);
            if ($v !== null) {
                $sekarang[$sel] = $v;
                if ((float) ($nilai[$sel] ?? 0) !== (float) $v) {
                    $baru[$sel] = $v;
                }
            }
        }
        if ($akhir) {
            $subLama = $nilai["{$kol}{$akhir}"] ?? null;
            $subBaru = $sekarang["{$kol}{$akhir}"] ?? null;
        }
        $total = $rumusKol->keys()->filter(fn ($sel) => $akhir && (int) substr($sel, strlen($kol)) > $akhir)
            ->map(fn ($sel) => ['sel' => $sel, 'label' => $teks((int) substr($sel, strlen($kol))), 'lama' => $nilai[$sel] ?? null, 'baru' => $sekarang[$sel] ?? null])->values()->all();

        $ubah = collect($baru)->filter(fn ($v, $sel) => ! is_numeric($nilai[$sel] ?? null) || (float) $nilai[$sel] !== (float) $v)->all();
        $belum = collect($barisHasil)->whereNull('nilai_baru');

        return [
            'rumus_biaya' => 'ritasi (rit × harga jual) + ngepok + solar (liter × tarif BBM tahap) + rental alat (jam HM × tarif rental) + operasional Kas Harian (akun yang dihitung); tanpa biaya umum teralokasi',
            'bagian' => ['judul_baris' => $mulai, 'kolom_nilai' => $kol, 'baris_data' => [$kepala + 1, ($akhir ?? $barisMaks + 1) - 1], 'subtotal_baris' => $akhir],
            'baris' => $barisHasil,
            'subtotal' => ['lama' => $subLama, 'baru' => $subBaru],
            'total_di_bawahnya' => $total,
            'tanggal_update_ritasi' => $tanggal,
            'tidak_diubah' => $belum->map(fn ($b) => "baris {$b['baris']} {$b['nama_di_sheet']}: {$b['catatan']}")->values()->all(),
            'ubah' => $ubah,
            'pemetaan_dipakai' => $dipakai,
        ];
    }

    /**
     * Tahap ERP untuk nama baris di sheet: nama lain tersimpan (sumber report_proyek) → nomor tahap ("ASG Tahap 113 …",
     * "Infra Tahap 04 …" ~ "Infra T04", "ASG Tahap 99 - 2") di proyek berawalan kata pertama → kata-kata nama (≥ 4 huruf) ada di nama + lokasi tahap.
     *
     * @return array{0: ?ProyekTahap, 1: string[], 2: ?string}
     */
    private static function cocokkan(string $nama, Collection $tahap, Collection $alias): array
    {
        if ($a = $alias[ProyekAlias::kunci($nama)] ?? null) {
            return [$tahap->firstWhere('id', $a->proyek_tahap_id), [], 'nama lain tersimpan'];
        }
        $kunci = fn ($s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s));
        $kata = preg_split('/\s+/', trim($nama));
        $induk = mb_strtolower($kata[0] ?? '');
        $calon = $tahap->filter(fn ($t) => str_starts_with(mb_strtolower($t->proyek->nama ?? ''), $induk) || str_starts_with(mb_strtolower($t->nama), $induk));
        if (preg_match('/\btahap\s*0*(\d+)(?:\s*-\s*(\d+))?/i', $nama, $m)) {
            $cara = 'nomor tahap';
            $hasil = $calon->filter(fn ($t) => preg_match('/\b(?:tahap\s*|t)0*(\d+)(?:\s*-\s*(\d+))?\b/i', $t->nama, $x)
                && (int) $x[1] === (int) $m[1] && ($x[2] ?? '') === ($m[2] ?? ''));
        } else {
            $cara = 'nama / lokasi tahap';
            $token = collect($kata)->slice(1)->map($kunci)->filter(fn ($w) => strlen($w) >= 4)->values();
            $hasil = $token->isEmpty() ? collect() : $calon->filter(fn ($t) => $token->every(fn ($w) => str_contains($kunci($t->nama.' '.$t->lokasi), $w)));
        }

        return $hasil->count() === 1 ? [$hasil->first(), [], $cara] : [null, $hasil->pluck('nama')->values()->all(), null];
    }

    /** SUM(Cx:Cy) atau penjumlahan / pengurangan sel (C12+C30); null bila bentuk lain. */
    private static function hitungRumus(string $f, array $nilai): ?float
    {
        $f = strtoupper(str_replace(' ', '', ltrim(trim($f), '=')));
        $v = fn (string $sel) => is_numeric($nilai[$sel] ?? null) ? (float) $nilai[$sel] : 0.0;
        if (preg_match('/^SUM\(([A-Z]+)(\d+):\1(\d+)\)$/', $f, $m)) {
            return array_sum(array_map(fn ($r) => $v($m[1].$r), range((int) $m[2], (int) $m[3])));
        }
        if (preg_match('/^[+-]?[A-Z]+\d+([+-][A-Z]+\d+)*$/', $f)) {
            preg_match_all('/([+-]?)([A-Z]+\d+)/', $f, $t, PREG_SET_ORDER);

            return array_sum(array_map(fn ($x) => ($x[1] === '-' ? -1 : 1) * $v($x[2]), $t));
        }

        return null;
    }

    // ===== Excel (.xlsx) =====

    /** @return array{0: string, 1: string} judul & path XML lembar pertama */
    private static function lembarPertama(ZipArchive $zip): array
    {
        $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $sheet = $wb->sheets->sheet[0];
        $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');

                return [(string) $sheet['name'], str_starts_with($target, 'xl/') ? $target : 'xl/'.$target];
            }
        }
        throw new InvalidArgumentException('Lembar pertama file Excel tidak ditemukan.');
    }

    /** @return string[] */
    private static function teksBersama(ZipArchive $zip): array
    {
        $isi = $zip->getFromName('xl/sharedStrings.xml');
        if ($isi === false) {
            return [];
        }
        $hasil = [];
        foreach (simplexml_load_string($isi)->si as $si) {
            $t = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $r) {
                $t .= (string) $r->t;
            }
            $hasil[] = $t;
        }

        return $hasil;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function bacaXlsx(DOMDocument $dom, array $teks): array
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('m', self::NS);
        $nilai = [];
        $rumus = [];
        foreach ($xp->query('//m:sheetData/m:row/m:c') as $c) {
            $sel = $c->getAttribute('r');
            $v = $xp->query('m:v', $c)->item(0)?->textContent;
            $jenis = $c->getAttribute('t');
            $nilai[$sel] = match ($jenis) {
                's' => $teks[(int) $v] ?? '',
                'inlineStr' => $xp->query('m:is', $c)->item(0)?->textContent,
                'str', 'b', 'e' => $v,
                default => $v === null ? null : (float) $v,
            };
            if ($f = $xp->query('m:f', $c)->item(0)) {
                $rumus[$sel] = $f->textContent;
            }
        }

        return [$nilai, $rumus];
    }

    /** Isi angka ke sel (sel & baris dibuat bila belum ada); rumus di sel tetap, hanya nilai tersimpannya diganti. */
    private static function isiSelXlsx(DOMDocument $dom, string $sel, int|float $v): void
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('m', self::NS);
        preg_match('/^([A-Z]+)(\d+)$/', $sel, $m);
        $c = $xp->query("//m:sheetData/m:row/m:c[@r='{$sel}']")->item(0);
        if (! $c) {
            $data = $xp->query('//m:sheetData')->item(0);
            $row = $xp->query("m:row[@r='{$m[2]}']", $data)->item(0);
            if (! $row) {
                $row = $dom->createElementNS(self::NS, 'row');
                $row->setAttribute('r', $m[2]);
                $setelah = null;
                foreach ($xp->query('m:row', $data) as $x) {
                    if ((int) $x->getAttribute('r') > (int) $m[2]) {
                        $setelah = $x;
                        break;
                    }
                }
                $data->insertBefore($row, $setelah);
            }
            $c = $dom->createElementNS(self::NS, 'c');
            $c->setAttribute('r', $sel);
            $setelah = null;
            foreach ($xp->query('m:c', $row) as $x) {
                if (self::nomorKolom($x->getAttribute('r')) > self::nomorKolom($sel)) {
                    $setelah = $x;
                    break;
                }
            }
            $row->insertBefore($c, $setelah);
        }
        /** @var DOMElement $c */
        $c->removeAttribute('t');
        foreach (iterator_to_array($xp->query('m:is', $c)) as $x) {
            $c->removeChild($x);
        }
        $vEl = $xp->query('m:v', $c)->item(0) ?? $c->appendChild($dom->createElementNS(self::NS, 'v'));
        $vEl->textContent = is_float($v) && floor($v) !== $v ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : (string) (int) round($v);
    }

    private static function nomorKolom(string $sel): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/\d/', '', $sel)) as $h) {
            $n = $n * 26 + (ord($h) - 64);
        }

        return $n;
    }

    // ===== Google Sheet =====

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function bacaGsheet(GoogleSheets $g, string $id, string $judul): array
    {
        $range = "{$judul}!A1:Z300";
        $mentah = $g->nilaiMentah($id, [$range])[$range];
        $f = $g->nilai($id, [$range], 'FORMULA')[$range];
        $nilai = [];
        $rumus = [];
        foreach ($mentah as $i => $baris) {
            foreach ($baris as $j => $v) {
                $sel = self::hurufKolom($j + 1).($i + 1);
                $nilai[$sel] = $v === '' ? null : $v;
                $isiF = $f[$i][$j] ?? null;
                if (is_string($isiF) && str_starts_with($isiF, '=')) {
                    $rumus[$sel] = substr($isiF, 1);
                }
            }
        }

        return [$nilai, $rumus];
    }

    private static function hurufKolom(int $n): string
    {
        $s = '';
        for (; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }
}
