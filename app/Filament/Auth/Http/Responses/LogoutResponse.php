<?php

namespace App\Filament\Auth\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Response logout kustom yang menambahkan notifikasi
 * "Anda berhasil keluar" sebelum mengarahkan ulang ke halaman login.
 *
 * Terdaftar sebagai implementasi default dari kontrak
 * Filament\Auth\Http\Responses\Contracts\LogoutResponse.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        Notification::make()
            ->title('Anda berhasil keluar')
            ->success()
            ->send();

        return redirect()->to(
            Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl(),
        );
    }
}
