<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;

/**
 * Halaman login kustom yang menambahkan notifikasi sukses
 * "Selamat datang kembali, {nama}!" setelah autentikasi berhasil.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response && auth()->check()) {
            Notification::make()
                ->title('Selamat datang kembali, '.auth()->user()->name.'!')
                ->success()
                ->send();
        }

        return $response;
    }
}
