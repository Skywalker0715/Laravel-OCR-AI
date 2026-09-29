<?php

namespace App\Providers;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            LogoutResponseContract::class,
            \App\Filament\Auth\Http\Responses\LogoutResponse::class,
        );
    }

    public function boot(): void
    {
        // Register konfigurasi Filament & auth response.
    }
}
