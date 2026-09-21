<?php

namespace App\Providers;

use App\Exceptions\RateLimitExceededException;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

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
        // Rate limiter untuk fitur "Tanya AI": maksimal 10 pertanyaan per user per hari.
        RateLimiter::for('ai-insights', function (Request $request) {
            return Limit::perDay(10)->by($request->user()?->id ?? 'guest');
        });
    }
}
