<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User aplikasi (pemilik expense & akun login admin panel).
 *
 * Mengimplementasikan FilamentUser (kontrak wajib Filament) agar panel admin
 * tetap bisa diakses di lingkungan non-local: tanpa kontrak ini, middleware
 * Authenticate milik Filament memblokir SEMUA user dengan 403 kecuali di env
 * "local" (lihat Filament\Http\Middleware\Authenticate::authenticate()).
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Kontrak FilamentUser: semua user terdaftar boleh mengakses panel admin
     * — aplikasi ini tidak membedakan role admin/non-admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
