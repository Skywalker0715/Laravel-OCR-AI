<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->registration(Register::class)
            ->passwordReset()
            // Aktifkan lonceng notifikasi database (dipakai peringatan budget
            // terlampaui yang dikirim dari AIParserJob).
            ->databaseNotifications()
            // Aktifkan halaman profil akun; item "Profile" otomatis muncul di menu avatar.
            // Docs: https://filamentphp.com/docs/4.x/panels/configuration#enabling-the-profile-page
            ->profile()
            ->brandName('Catatan Belanja')
            // Logo header = gambar logo + nama "Catatan Belanja" (kata
            // "Belanja" hijau, sesuai gaya welcome.blade.php).
            ->brandLogo(fn (): Htmlable => new HtmlString(
                // Warna teks brand TIDAK ditulis inline di sini (inline style
                // menimpa CSS apapun, sehingga teks gelap #0f172a ini tidak
                // pernah ikut dark mode & nyaris tak terlihat di topbar gelap).
                // Warna terang/gelap diatur via class .fi-brand-text di file
                // public/css/admin-panel.css (lihat selector .fi-brand-text).
                '<span class="fi-brand-text" style="display:inline-flex;align-items:center;gap:0.5rem;font-weight:700;font-size:1.05rem;white-space:nowrap;">'
                .'<img src="'.asset('images/logo-catatan-belanja.png').'" alt="Catatan Belanja" style="height:2rem;width:auto;flex-shrink:0;">'
                .'Catatan <span style="color:#10B981;">Belanja</span>'
                .'</span>'
            ))
            ->favicon(asset('favicon.png'))
            ->colors([
                'primary' => Color::hex('#10B981'),
            ])
            // Izinkan user menutup/membuka sidebar di layar desktop.
            ->sidebarCollapsibleOnDesktop()
            // Kustomisasi tampilan panel (sidebar, topbar, header, breadcrumb)
            // memakai CSS statis di public/css/admin-panel.css - dimuat via
            // render hook HEAD_END agar menyesuaikan tema default Filament
            // tanpa rebuild theme.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): Htmlable => new HtmlString(
                    '<link rel="stylesheet" href="'.asset('css/admin-panel.css').'">'
                ),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Widget "Welcome" bawaan sengaja dihapus; dashboard kini
                // memakai widget kustom (StatsOverview + grafik) via Pages/Dashboard.
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
