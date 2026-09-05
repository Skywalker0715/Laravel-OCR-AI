<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Notifications\Notification;

/**
 * Halaman registrasi kustom yang menambahkan notifikasi sukses setelah akun
 * berhasil dibuat & user otomatis login.
 */
class Register extends BaseRegister
{
    public function register(): ?RegistrationResponse
    {
        $response = parent::register();

        if ($response && auth()->check()) {
            Notification::make()
                ->title('Akun berhasil dibuat! Selamat datang, '.auth()->user()->name)
                ->success()
                ->send();
        }

        return $response;
    }
}
