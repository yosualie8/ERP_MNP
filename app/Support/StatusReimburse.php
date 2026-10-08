<?php

namespace App\Support;

use App\Models\KasBon;
use App\Models\KasTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Status reimburse transaksi Kas Harian. Sumber kebenarannya aplikasi (tabel kas_sudah_reimburse): data awal diambil SEKALI
 * dari lembar "Sudah Reimburse" ({@see imporAwal}); perubahan berikutnya dibuat di aplikasi ({@see tandai}) lalu ditulis ke
 * lembar itu ({@see dorongKeSheet}) supaya kolom Status di Mutasi Reimburse ikut berubah (rumusnya mencari ID di lembar ini).
 *
 * Lembar "Sudah Reimburse": A No, B ID Transaksi Kas, C ID UJ, D Tanggal (kas), E Tanggal Transaksi Terjadi, F PIC, G Keterangan,
 * H Jenis Mobil, I No DO, J Galian, K Kategori, L Kode GL, M Bon, N Debit, O Kredit, P Tgl Reimburse
 * (= kolom A–K, M–P Mutasi Reimburse + tanggal reimburse).
 */
class StatusReimburse
{
    public const LEMBAR = 'Sudah Reimburse';

    /** Ambil status dari sheet satu kali (migrasi awal). Ditolak bila tabel sudah berisi, kecuali $paksa. @return int jumlah ID */
    public static function imporAwal(GoogleSheets $sheets, bool $paksa = false): int
    {
        if (! $paksa && DB::table('kas_sudah_reimburse')->exists()) {
            throw new RuntimeException('Status reimburse sudah ada di aplikasi (sumber kebenaran). Impor awal hanya sekali; pakai --paksa untuk menimpa.');
        }
        $range = "'".self::LEMBAR."'!B2:P";
        $peta = [];
        foreach ($sheets->nilaiMentah(config('mnp.sheet_reimburse'), [$range])[$range] as $r) {
            $id = trim((string) ($r[0] ?? ''));
            if ($id !== '') {
                // Kolom P = tanggal reimburse sebenarnya (kolom D berisi tanggal transaksi kas).
                $peta[$id] = (KasSeabank::tanggal($r[14] ?? null) ?? KasSeabank::tanggal($r[2] ?? null))?->toDateString() ?? ($peta[$id] ?? null);
            }
        }
        DB::transaction(function () use ($peta) {
            DB::table('kas_sudah_reimburse')->delete();
            foreach (array_chunk($peta, 1000, true) as $potong) {
                DB::table('kas_sudah_reimburse')->insert(array_map(fn ($id, $tgl) => [
                    'id_transaksi' => mb_substr($id, 0, 40), 'tanggal_reimburse' => $tgl, 'di_sheet' => true, 'sumber' => 'sheet', 'created_at' => now(),
                ], array_keys($potong), $potong));
            }
        });

        return count($peta);
    }

    /**
     * Tandai transaksi sudah direimburse (di aplikasi), lalu tulis ke lembar "Sudah Reimburse" sesudah halaman terkirim.
     *
     * @param  string[]  $ids  ID transaksi kas (kolom B Mutasi Reimburse)
     * @return int jumlah yang baru ditandai
     */
    public static function tandai(array $ids, Carbon $tanggal, ?int $userId): int
    {
        $baru = array_values(array_diff(array_unique($ids), DB::table('kas_sudah_reimburse')->whereIn('id_transaksi', $ids)->pluck('id_transaksi')->all()));
        DB::table('kas_sudah_reimburse')->insert(array_map(fn ($id) => [
            'id_transaksi' => $id, 'tanggal_reimburse' => $tanggal->toDateString(), 'di_sheet' => false, 'sumber' => 'aplikasi',
            'user_id' => $userId, 'created_at' => now(),
        ], $baru));
        if ($baru) {
            dispatch(fn () => rescue(fn () => self::dorongKeSheet(GoogleSheets::wajib())))->afterResponse();
        }

        return count($baru);
    }

    /**
     * Tulis status dari aplikasi yang belum ada di lembar "Sudah Reimburse" (di_sheet = false), di bawah data terakhir.
     * Isi baris diambil dari baris Mutasi Reimburse ID yang sama (termasuk kolom UJ yang diisi admin); bila belum ada di sana,
     * disusun dari data Kas Harian. Dipanggil sesudah menandai, dan sebagai cadangan oleh mnp:dorong-reimburse.
     */
    public static function dorongKeSheet(GoogleSheets $sheets, ?string $spreadsheetId = null): int
    {
        $id = $spreadsheetId ?? config('mnp.sheet_reimburse');

        return Cache::lock('tulis-sudah-reimburse', 120)->block(60, function () use ($sheets, $id) {
            $antre = DB::table('kas_sudah_reimburse')->where('di_sheet', false)->orderBy('created_at')->get();
            if ($antre->isEmpty()) {
                return 0;
            }
            $l = "'".self::LEMBAR."'";
            $m = "'".CerminReimburse::LEMBAR."'";
            $isi = $sheets->nilaiMentah($id, ["{$l}!B:B", "{$m}!A:P"]);
            $sudahDiSheet = collect($isi["{$l}!B:B"])->map(fn ($r) => trim((string) ($r[0] ?? '')))->filter()->flip();
            $akhir = 1;
            foreach ($isi["{$l}!B:B"] as $i => $r) {
                if (trim((string) ($r[0] ?? '')) !== '') {
                    $akhir = $i + 1;
                }
            }
            $mutasi = [];
            foreach ($isi["{$m}!A:P"] as $i => $r) {
                if ($i > 0 && ($k = trim((string) ($r[1] ?? ''))) !== '') {
                    $mutasi[$k] ??= array_pad($r, 16, '');
                }
            }

            $baris = [];
            foreach ($antre as $s) {
                if (isset($sudahDiSheet[$s->id_transaksi])) {
                    continue; // sudah ada (mis. diketik admin) — cukup ditandai tertulis
                }
                $r = $mutasi[$s->id_transaksi] ?? self::dariKas($s->id_transaksi);
                if (! $r) {
                    continue; // transaksi tidak ditemukan — tetap di antrean
                }
                $tgl = Carbon::parse($s->tanggal_reimburse)->format('Y-m-d');
                // A–K sama dengan Mutasi Reimburse, L–O = Mutasi M–P (Kode GL, Bon, Debit, Kredit), P = tanggal reimburse.
                $baris[$s->id_transaksi] = [...array_slice($r, 0, 11), ...array_slice($r, 12, 4), $tgl];
            }
            if ($baris) {
                $mulai = $akhir + 1;
                $sheetId = collect($sheets->info($id)['sheets'])->firstWhere('properties.title', self::LEMBAR)['properties'];
                if ($mulai + count($baris) > $sheetId['gridProperties']['rowCount']) {
                    $sheets->permintaan($id, [['appendDimension' => ['sheetId' => $sheetId['sheetId'], 'dimension' => 'ROWS', 'length' => count($baris) + 200]]], 'menambah baris Sudah Reimburse');
                }
                $sheets->permintaan($id, [['copyPaste' => [
                    'source' => ['sheetId' => $sheetId['sheetId'], 'startRowIndex' => $akhir - 1, 'endRowIndex' => $akhir, 'startColumnIndex' => 0, 'endColumnIndex' => 16],
                    'destination' => ['sheetId' => $sheetId['sheetId'], 'startRowIndex' => $mulai - 1, 'endRowIndex' => $mulai - 1 + count($baris), 'startColumnIndex' => 0, 'endColumnIndex' => 16],
                    'pasteType' => 'PASTE_FORMAT',
                ]]], 'menyalin format baris Sudah Reimburse');
                $data = [];
                foreach (array_values($baris) as $i => $r) {
                    $n = $mulai + $i;
                    $data["{$l}!A{$n}:P{$n}"] = [array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'".$v : $v, $r)];
                }
                $sheets->tulis($id, $data);
            }
            $tertulis = [...array_keys($baris), ...$antre->pluck('id_transaksi')->filter(fn ($i) => isset($sudahDiSheet[$i]))->all()];
            DB::table('kas_sudah_reimburse')->whereIn('id_transaksi', $tertulis)->update(['di_sheet' => true]);

            return count($baris);
        });
    }

    /** Baris Mutasi Reimburse (A–P) untuk satu ID, disusun dari data Kas Harian. */
    private static function dariKas(string $idTransaksi): ?array
    {
        if (! preg_match('/^(\d{6})-Jago-(\d+)$/', $idTransaksi, $m)) {
            return null;
        }
        $bon = KasBon::where('no_id', $m[2])->first();
        $t = $bon?->transfer ?? KasTransfer::where('no_id', $m[2])->first();
        if (! $t) {
            return null;
        }
        $r = collect(CerminReimburse::baris($t))->firstWhere('id', $idTransaksi);
        if (! $r) {
            return null;
        }
        $baris = [];
        for ($k = 0; $k <= 15; $k++) {
            $baris[] = $r['sel'][$k] ?? '';
        }

        return $baris;
    }

    /** ID transaksi yang dipakai di Mutasi Reimburse untuk satu detail. */
    public static function idBon(KasBon $b, KasTransfer $t): string
    {
        return trim((string) $b->id_transaksi) ?: $t->tanggal->format('ymd').'-Jago-'.($b->no_id ?: $t->no_id);
    }

    /**
     * @param  string[]  $ids
     * @return array<string, ?Carbon> ID yang sudah direimburse => tanggal reimburse
     */
    public static function untuk(array $ids): array
    {
        return DB::table('kas_sudah_reimburse')->whereIn('id_transaksi', array_unique($ids))->pluck('tanggal_reimburse', 'id_transaksi')
            ->map(fn ($t) => $t ? Carbon::parse($t) : null)->all();
    }

    /** Pesan penolakan Edit/Hapus bila ada ID yang sudah direimburse; null bila semua belum. */
    public static function pesanTolak(array $ids): ?string
    {
        $sudah = array_keys(self::untuk($ids));

        return $sudah ? 'Ditolak: transaksi ini sudah direimburse ('.implode(', ', array_slice($sudah, 0, 3)).(count($sudah) > 3 ? ', …' : '')
            .'). Hanya transaksi yang belum direimburse yang bisa diubah atau dihapus.' : null;
    }

    /** Jumlah status dari aplikasi yang belum tertulis di sheet. */
    public static function antreanSheet(): int
    {
        return DB::table('kas_sudah_reimburse')->where('di_sheet', false)->count();
    }
}
