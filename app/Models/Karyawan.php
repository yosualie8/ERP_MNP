<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu karyawan MNP (data induk modul absensi). */
class Karyawan extends Model
{
    protected $table = 'karyawan';

    protected $fillable = ['no_master', 'nama', 'email', 'no_hp', 'nik', 'npwp', 'status', 'site', 'jabatan', 'no_mobil', 'bank', 'no_rekening', 'nama_rekening', 'user_id'];

    /** Nama lain / ejaan sopir di Kas UJ. */
    public function alias(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KaryawanAlias::class);
    }

    public const STATUS = ['aktif' => 'Aktif', 'nonaktif' => 'Non Aktif'];

    /** Site yang sudah dipakai (saran isian; boleh menulis site baru). */
    public const SITE = ['ASG', 'JKT', 'Site 21', 'PPA', 'BIS', 'PKP'];

    public function labelStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** No HP untuk tautan WhatsApp (wa.me): "0812-3456 789" → "628123456789"; null bila kosong / tidak wajar. */
    public function nomorWa(): ?string
    {
        $n = preg_replace('/\D/', '', (string) $this->no_hp);
        $n = str_starts_with($n, '0') ? '62'.substr($n, 1) : (str_starts_with($n, '8') ? '62'.$n : $n);

        return strlen($n) >= 10 && strlen($n) <= 15 ? $n : null;
    }

    /** Site dari sheet Master: "21" → "Site 21"; selain itu apa adanya. */
    public static function rapikanSite(?string $v): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));

        return $v === '' ? null : (preg_match('/^\d+$/', $v) ? "Site {$v}" : $v);
    }
}
