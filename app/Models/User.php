<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'role',
        'menu',
    ];

    public const ROLE = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'menu' => 'array',
    ];

    /** Boleh melihat menu ini? (lihat App\Support\MenuAkses) */
    public function bolehMenu(string $menu): bool
    {
        return \App\Support\MenuAkses::boleh($this, $menu);
    }

    /** Super admin = email di SUPER_ADMIN_EMAILS (.env) atau peran super_admin di menu Pengguna. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin' || in_array(strtolower($this->email), self::emailSuperAdminEnv(), true);
    }

    /** @return string[] */
    public static function emailSuperAdminEnv(): array
    {
        return collect(explode(',', (string) config('services.super_admin_emails')))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter()
            ->values()
            ->all();
    }
}
