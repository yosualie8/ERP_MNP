<?php

namespace App\Support;

use App\Models\GoogleIntegrasi;
use App\Models\MutasiPt;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Impor Mutasi Rekening PT (BCA) dari file xlsx di Drive (config mnp.mutasi_pt_file), sheet "Mutasi Rekening PT":
 * Tanggal Transaksi | Keterangan | Cabang | DB/CR | Jumlah | Saldo. File diunduh hanya bila waktu ubahnya berbeda dari
 * impor terakhir; isi tabel diganti utuh (sumber berisi seluruh riwayat).
 */
class ImporMutasiPt
{
    public const LEMBAR = 'Mutasi Rekening PT';

    public const KUNCI = 'mutasi-pt-impor'; // cache: ['diubah' => modifiedTime Drive, 'waktu' => kapan diimpor, 'baris' => n]

    /** @return array{status: string, baris?: int, diubah?: string} */
    public static function jalankan(bool $paksa = false): array
    {
        $id = config('mnp.mutasi_pt_file');
        // Akun pemilik file (integrasi foto, akses Drive penuh) atau akun Sheets (drive.readonly).
        $g = ($f = GoogleIntegrasi::foto()) ? new GoogleSheets($f) : GoogleSheets::terhubung();
        if (! $id || ! $g) {
            throw new RuntimeException('File mutasi PT belum diatur / akun Google belum terhubung.');
        }
        $meta = $g->http(30)->get("https://www.googleapis.com/drive/v3/files/{$id}", ['fields' => 'name,modifiedTime', 'supportsAllDrives' => 'true']);
        if (! $meta->successful()) {
            throw new RuntimeException('Tidak bisa membaca file Drive mutasi PT: '.$meta->status());
        }
        $lalu = Cache::get(self::KUNCI);
        if (! $paksa && ($lalu['diubah'] ?? null) === $meta->json('modifiedTime')) {
            return ['status' => 'tetap', 'diubah' => $meta->json('modifiedTime')];
        }
        $isi = $g->http(180)->get("https://www.googleapis.com/drive/v3/files/{$id}", ['alt' => 'media', 'supportsAllDrives' => 'true']);
        if (! $isi->successful()) {
            throw new RuntimeException('Gagal mengunduh file mutasi PT: '.$isi->status());
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mutasi-pt-').'.xlsx';
        file_put_contents($tmp, $isi->body());
        try {
            $baris = self::urai($tmp);
        } finally {
            @unlink($tmp);
        }
        if (! $baris) {
            throw new RuntimeException('Sheet "'.self::LEMBAR.'" kosong / tidak ditemukan — impor dibatalkan (data lama dipertahankan).');
        }
        DB::transaction(function () use ($baris) {
            MutasiPt::query()->delete();
            foreach (array_chunk($baris, 1000) as $potong) {
                MutasiPt::insert($potong);
            }
        });
        Cache::forever(self::KUNCI, ['diubah' => $meta->json('modifiedTime'), 'waktu' => now()->toDateTimeString(), 'baris' => count($baris), 'nama' => $meta->json('name')]);

        return ['status' => 'diimpor', 'baris' => count($baris), 'diubah' => $meta->json('modifiedTime')];
    }

    /** @return array<int, array<string, mixed>> */
    public static function urai(string $file): array
    {
        $r = new Reader();
        $r->open($file);
        $hasil = [];
        $sekarang = now();
        try {
            foreach ($r->getSheetIterator() as $s) {
                if ($s->getName() !== self::LEMBAR) {
                    continue;
                }
                $no = 0;
                foreach ($s->getRowIterator() as $row) {
                    $no++;
                    $v = $row->toArray();
                    $tgl = self::tanggal($v[0] ?? null);
                    $jumlah = $v[4] ?? null;
                    if (! $tgl || ! is_numeric($jumlah)) {
                        continue; // judul / baris kosong
                    }
                    $hasil[] = ['baris' => $no, 'tanggal' => $tgl, 'keterangan' => trim(preg_replace('/\s+/', ' ', (string) ($v[1] ?? ''))) ?: null,
                        'cabang' => trim((string) ($v[2] ?? '')) ?: null, 'dbcr' => strtoupper(trim((string) ($v[3] ?? ''))) ?: null,
                        'jumlah' => (float) $jumlah, 'saldo' => is_numeric($v[5] ?? null) ? (float) $v[5] : null, 'created_at' => $sekarang, 'updated_at' => $sekarang];
                }
            }
        } finally {
            $r->close();
        }

        return $hasil;
    }

    private static function tanggal($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_numeric($v) && $v > 30000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
