<?php

namespace App\Support;

use App\Models\KasRiwayat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Kesehatan server (owner 23 Okt 2026, disk VPS hanya 38 GB): sisa disk & foto yang tertahan di server karena belum terunggah
 * ke Google Drive (foto asli Lapor Lapangan / bon menumpuk bila koneksi Drive putus). Tampil sebagai banner di Beranda Super Admin;
 * mnp:cek-server tiap jam mencatat ke riwayat bila ada masalah baru.
 */
class CekServer
{
    public const BATAS_PERSEN = 20;      // sisa disk di bawah ini = peringatan

    public const BATAS_MENIT_FOTO = 60;  // foto sementara lebih lama dari ini = unggahan Drive macet

    /** @return array{disk: array{total: float, terpakai: float, sisa: float, persen_sisa: float}, masalah: string[]} */
    public static function periksa(): array
    {
        $total = (float) @disk_total_space(base_path());
        $sisa = (float) @disk_free_space(base_path());
        $gb = fn ($b) => round($b / 1073741824, 1);
        $disk = ['total' => $gb($total), 'terpakai' => $gb($total - $sisa), 'sisa' => $gb($sisa), 'persen_sisa' => $total > 0 ? round($sisa / $total * 100, 1) : 0.0];
        $masalah = [];
        if ($total > 0 && $disk['persen_sisa'] < self::BATAS_PERSEN) {
            $masalah[] = "Disk server tinggal {$disk['sisa']} GB dari {$disk['total']} GB ({$disk['persen_sisa']}%). Bila penuh, web error dan absen tidak bisa dikirim.";
        }
        foreach (['lapor-sementara' => 'foto Lapor Lapangan', 'bon-sementara' => 'foto bon'] as $folder => $label) {
            $lama = collect(Storage::allFiles($folder))->filter(fn ($f) => Storage::lastModified($f) < now()->subMinutes(self::BATAS_MENIT_FOTO)->timestamp);
            if ($lama->isNotEmpty()) {
                $mb = round($lama->sum(fn ($f) => Storage::size($f)) / 1048576, 1);
                $masalah[] = "{$lama->count()} {$label} ({$mb} MB) tertahan di server lebih dari ".self::BATAS_MENIT_FOTO.' menit — unggahan ke Google Drive macet (cek koneksi Google Drive foto di Beranda).';
            }
        }

        return ['disk' => $disk, 'masalah' => $masalah];
    }

    /** Dipanggil mnp:cek-server: masalah yang baru muncul dicatat ke riwayat (tidak berulang tiap jam untuk masalah yang sama). */
    public static function catat(): array
    {
        $h = self::periksa();
        $sebelum = Cache::get('cek-server-masalah', []);
        $baru = array_values(array_diff($h['masalah'], $sebelum));
        if ($baru) {
            KasRiwayat::create(['aksi' => 'cek-server', 'lembar' => 'Server', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => null,
                'ringkasan' => mb_strimwidth('⚠ '.implode(' · ', $baru), 0, 490, '…'), 'isi' => $h]);
        }
        Cache::forever('cek-server-masalah', $h['masalah']);

        return $h;
    }
}
