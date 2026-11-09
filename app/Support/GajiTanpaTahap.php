<?php

namespace App\Support;

use App\Models\ProyekTahap;
use Illuminate\Support\Collection;

/**
 * Transaksi gaji Kas Harian yang belum jelas tahap proyeknya (owner 9 Nov 2026: "tidak mau lagi ada data gaji karyawan yang
 * tidak ada tahapnya"). Belum jelas = belum dipilah manual DAN jatuh ke: tanpa tahap, pos biaya umum (mis. "Infra (tanpa tahap)",
 * "ASG (biaya tanpa tahap)") atau tahap bawaan cost center (mis. "Pantai Mutiara 2 - Infra" untuk Kode GL "Gaji Karyawan Infra PM").
 * Dipilah di Pilah Transaksi — termasuk sengaja ke pos biaya umum — maka dianggap sudah diputuskan.
 */
class GajiTanpaTahap
{
    /** Sama dengan periode data laporan (standar keyakinan: Mei 2026 ke atas). */
    public const MULAI = '2026-05-01';

    /** @return Collection<int, object{tanggal: string, nominal: float, keterangan: string, gl: string, tahap: ?string, proyek_id: ?int}> */
    public static function daftar(): Collection
    {
        $tahap = ProyekTahap::get(['id', 'nama', 'pos_overhead', 'proyek_id', 'status'])->keyBy('id');
        // Tahap bawaan cost center hanya "belum jelas" bila proyeknya punya tahap lain yang berjalan (mis. PM2 - Infra vs Marina / Regatta);
        // cost center bertahap tunggal (mis. Kawasan Industri Kendal) sudah jelas.
        $punyaSaudara = fn ($t) => $tahap->contains(fn ($x) => $x->id !== $t->id && $x->proyek_id === $t->proyek_id && ! $x->pos_overhead && $x->status === 'progress');

        return DaftarProyek::queryKas()->where('b.tanggal', '>=', self::MULAI)->where('a.nama', 'like', 'Gaji%')->whereNull('al.proyek_tahap_id')
            ->selectRaw('b.tanggal, b.nominal, b.keterangan, k.kode_asli gl, a.nama akun, c.kode cc, k.ref')->orderBy('b.tanggal')->get()
            ->map(function ($r) use ($tahap, $punyaSaudara) {
                $id = DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, substr((string) $r->tanggal, 0, 10));
                $t = $id ? $tahap[$id] ?? null : null;
                $umum = ! $t || $t->pos_overhead || ($id === DaftarProyek::tahapId('kas_cc', $r->cc ?: '-') && $punyaSaudara($t));

                return $umum ? (object) ['tanggal' => substr((string) $r->tanggal, 0, 10), 'nominal' => (float) $r->nominal, 'keterangan' => (string) $r->keterangan,
                    'gl' => (string) $r->gl, 'tahap' => $t?->nama, 'proyek_id' => $t?->proyek_id] : null;
            })->filter()->values();
    }
}
