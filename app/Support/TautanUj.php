<?php

namespace App\Support;

use App\Models\KasFoto;
use App\Models\UjDetail;
use App\Models\UjTransaksi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Foto bon Kas UJ: folder Drive per transaksi (nomor ID UJ master) di subfolder "UJ BBTT", chip folder di kolom Q (Bon)
 * setiap detail di lembar Kas Seabank. Ditulis sesaat setelah halaman terkirim, seperti Kode Bon Kas Harian.
 */
class TautanUj
{
    public static function lembarFoto(CarbonInterface $tanggal): string
    {
        return 'UJ '.$tanggal->format('my');
    }

    public static function namaFolder(int $noUj, ?CarbonInterface $tanggal, ?string $nama, ?string $keterangan): string
    {
        $isi = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', trim(implode(' - ', array_filter([trim((string) $nama), trim((string) $keterangan)]))));

        return trim("UJ-{$noUj} - ".($tanggal?->format('Y-m-d') ?? '').' - '.Str::limit($isi, 80, ''), ' -');
    }

    /**
     * Nama file & folder Drive untuk foto ini (dipakai FotoBon::unggahKeDrive).
     *
     * @return array{0: string, 1: array{id: string, link: string, nama: string}}
     */
    public static function tujuanFoto(KasFoto $foto, DriveFoto $drive): array
    {
        $t = UjTransaksi::with('detail')->where('no_uj', $foto->no_id)->first();
        $ket = $t?->detail->first()?->keterangan;
        $judul = Str::limit(self::namaFolder($foto->no_id, $t?->tanggal, $t?->nama, $ket), 120, '').' - '.$foto->id.'.jpg';

        return [$judul, $drive->folderTransaksi($foto->no_id, $foto->lembar, self::namaFolder($foto->no_id, $t?->tanggal, $t?->nama, $ket), 'uj')];
    }

    public static function pastikanSegera(int $noUj): void
    {
        dispatch(fn () => rescue(fn () => self::pastikan($noUj)))->afterResponse();
    }

    /**
     * Pastikan kolom Bon (Q) setiap detail transaksi ini berisi chip folder fotonya (baris dicocokkan lewat ID UJ di kolom A).
     *
     * @return int jumlah sel yang ditulis
     */
    public static function pastikan(int $noUj): int
    {
        $t = UjTransaksi::with('detail')->where('no_uj', $noUj)->first();
        if (! $t || ! ($drive = DriveFoto::terhubung()) || ! KasFoto::uj()->where('no_id', $noUj)->exists()) {
            return 0;
        }
        $ket = $t->detail->first()?->keterangan;
        $folder = $drive->folderTransaksi($noUj, self::lembarFoto($t->tanggal ?? now()), self::namaFolder($noUj, $t->tanggal, $t->nama, $ket), 'uj');
        $perlu = $t->detail->reject->biaya_transfer->filter(fn (UjDetail $d) => trim((string) $d->bon) !== $folder['nama']);
        if ($perlu->isEmpty()) {
            return 0;
        }
        $sheets = GoogleSheets::wajib();
        $id = KasSeabank::id();
        $l = KasSeabank::LEMBAR;

        return Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($sheets, $id, $l, $perlu, $folder) {
            [$dari, $sampai] = [$perlu->min('baris'), $perlu->max('baris')];
            $isi = $sheets->nilai($id, ["{$l}!A{$dari}:A{$sampai}", "{$l}!Q1"]);
            $sel = [];
            foreach ($perlu as $d) {
                if (trim((string) ($isi["{$l}!A{$dari}:A{$sampai}"][$d->baris - $dari][0] ?? '')) !== (string) $d->id_uj) {
                    continue; // baris sudah bergeser; dicoba lagi setelah sinkron berikutnya
                }
                $sel[$d->baris] = $folder['link'];
                $d->update(['bon' => $folder['nama']]);
            }
            if ($sel) {
                $info = collect($sheets->info($id)['sheets'])->firstWhere('properties.title', $l)['properties'];
                $sheets->chipDrive($id, $info['sheetId'], KasSeabank::KOLOM_BON, $sel);
                if (trim((string) ($isi["{$l}!Q1"][0][0] ?? '')) === '') {
                    $sheets->tulis($id, ["{$l}!Q1" => [['Bon']]]);
                }
            }

            return count($sel);
        });
    }
}
