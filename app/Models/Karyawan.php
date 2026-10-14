<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu karyawan MNP (data induk modul absensi). */
class Karyawan extends Model
{
    protected $table = 'karyawan';

    protected $fillable = ['no_master', 'nama', 'email', 'nik', 'npwp', 'status', 'site', 'jabatan', 'bank', 'no_rekening', 'nama_rekening', 'user_id'];

    public const STATUS = ['aktif' => 'Aktif', 'nonaktif' => 'Non Aktif'];

    /** Site yang sudah dipakai (saran isian; boleh menulis site baru). */
    public const SITE = ['ASG', 'JKT', 'Site 21', 'PPA', 'BIS', 'PKP'];

    public function labelStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }

    /** Site dari sheet Master: "21" → "Site 21"; selain itu apa adanya. */
    public static function rapikanSite(?string $v): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v));

        return $v === '' ? null : (preg_match('/^\d+$/', $v) ? "Site {$v}" : $v);
    }
}
