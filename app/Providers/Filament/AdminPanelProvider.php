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
            // Aktifkan halaman profil akun (nama, email & password pribadi).
            // Item "Profile" otomatis muncul di dropdown avatar (ikon
            // user di pojok kanan atas) lewat HasUserMenu, karena
            // ->profile() mendaftarkan Filament\Auth\Pages\EditProfile
            // sebagai profile page (layout sederhana / simple, nilai
            // default Filament v4). Lihat dokumentasi resmi:
            // https://filamentphp.com/docs/4.x/panels/configuration#enabling-the-profile-page
            ->profile()
            ->brandName('Catatan Belanja')
            // Logo header = gambar logo + nama "Catatan Belanja" (kata
            // "Belanja" hijau, sesuai gaya welcome.blade.php).
            ->brandLogo(fn (): Htmlable => new HtmlString(
                // Warna teks brand TIDAK ditulis inline di sini (inline style
                // menimpa CSS apapun, sehingga teks gelap #0f172a ini tidak
                // pernah ikut dark mode & nyaris tak terlihat di topbar gelap).
                // Warna terang/gelap diatur via class .fi-brand-text pada blok
                // <style> render hook di bawah (lihat selector .fi-brand-text).
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
            // Kustomisasi tampilan sidebar (CSS di-inject via HEAD_END agar
            // menyesuaikan tema default Filament tanpa rebuild theme).
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): Htmlable => new HtmlString(<<<'HTML'
                    <style>
                        /* ============================================================
                           Kustomisasi Sidebar Admin Panel (Catatan Belanja)
                           1) Tombol toggle collapse/expand desktop = ikon hamburger (☰)
                           2) Garis pemisah vertikal sidebar vs. area konten
                           3) Transisi buka/tutup yang smooth (di desktop)
                           ============================================================ */

                        /* ============================================================
                           OVERRIDE SIDEBAR COLLAPSED (icon-only) — Filament v4
                           ------------------------------------------------------------
                           State collapsed ditandai dengan TIDAK adanya class
                           `.fi-sidebar-open` pada elemen `.fi-sidebar`. Override di
                           bawah hanya menyentuh sisi tersebut, tidak mengubah layout
                           terbuka (default) Filament.
                           ============================================================ */

                        /* Ikon hamburger (Heroicons bars-3) untuk kedua tombol
                           collapse/expand sidebar di desktop (.fi-topbar-start hanya
                           tampil di layar lg+) + lebar rail collapsed yang dipakai
                           konsisten. Catatan: `--collapsed-sidebar-width` TIDAK
                           didefinisikan oleh Filament v4, jadi kita definisikan
                           sendiri supaya nilainya pasti & seragam (72px = 4.5rem). */
                        :root {
                            --fi-hamburger-icon: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5'/%3E%3C/svg%3E");
                            --collapsed-sidebar-width: 4.5rem; /* 72px — cukup utk ikon */

                            /* ================================================
                               DESIGN TOKENS DARK/LIGHT MODE (Catatan Belanja)
                               ------------------------------------------------
                               Variabel CSS milik aplikasi untuk komponen custom
                               yang dirender sebagai RAW HTML (view di
                               resources/views/filament/**). Utility Tailwind di
                               view tersebut TIDAK ikut ter-compile ke tema
                               bawaan Filament (default theme hanya memindai
                               view milik Filament sendiri), sehingga class
                               `dark:*` di view custom tidak berlaku.

                               Solusinya: komponen custom memakai var(--cb-...)
                               via inline style, dan nilai variabel ini otomatis
                               "berbalik" saat dark mode aktif (selector
                               html.dark — mekanisme yang sama dengan
                               kustomisasi sidebar di blok CSS ini).

                               Cara pakai di blade (raw HTML):
                               style="color: var(--cb-muted);"
                               ================================================ */
                            --cb-border: #e5e7eb;   /* garis/pemisah (gray-200) */
                            --cb-track: #e5e7eb;    /* jalur progress bar */
                            --cb-muted: #6b7280;    /* teks sekunder (gray-500) */
                            --cb-strong: #111827;   /* teks utama (gray-900) */
                            --cb-surface: #f9fafb;  /* latar sel/panel (gray-50) */
                            --cb-link: #2563eb;     /* tautan (blue-600) */
                        }

                        /* Mode gelap: nilai variabel --cb-* dibalik agar semua
                           komponen custom ikut berubah warna saat toggle dark
                           mode di menu akun (avatar). */
                        html.dark {
                            --cb-border: #3f3f46;   /* zinc-700 — senada dgn border sidebar gelap */
                            --cb-track: #3f3f46;
                            --cb-muted: #9ca3af;    /* gray-400 — tetap terbaca di latar gelap */
                            --cb-strong: #f9fafb;   /* gray-50 */
                            --cb-surface: #27272a;  /* zinc-800 */
                            --cb-link: #60a5fa;     /* blue-400 — kontras cukup di gelap */
                        }

                        /* Teks brand di topbar & halaman login: gelap di mode
                           terang, terang di mode gelap. Sebelumnya warna ini
                           ditulis inline (#0f172a) sehingga mati di dark mode. */
                        .fi-brand-text {
                            color: #0f172a;
                        }

                        html.dark .fi-brand-text {
                            color: #f9fafb;
                        }


                        /* Sembunyikan ikon chevron bawaan tombol di desktop */
                        .fi-topbar-start .fi-icon-btn .fi-icon {
                            display: none;
                        }

                        .fi-topbar-start .fi-icon-btn::before {
                            content: "";

                            /* Mask ikon hamburger agar warnanya mengikuti warna teks
                               tombol (termasuk state hover / dark mode). */
                            display: block;
                            width: 1.5rem;
                            height: 1.5rem;
                            background: currentColor;
                            -webkit-mask: var(--fi-hamburger-icon) center / contain no-repeat;
                            mask: var(--fi-hamburger-icon) center / contain no-repeat;
                            transition: transform 0.2s ease, opacity 0.2s ease;
                        }

                        .fi-topbar-start .fi-icon-btn:hover::before {
                            transform: scale(1.15);
                        }

                        /* 1) Tombol toggle hamburger selalu di KIRI ATAS, baik saat
                           sidebar terbuka maupun tertutup.
                           - `.fi-topbar-start` adalah flex-item pertama di topbar
                             (paling kiri); hilangkan margin kiri agar menempel.
                           - Kedua tombol collapse/expand dipaksa `order: -10` supaya
                             selalu menjadi elemen paling kiri (sebelum logo) dan
                             tidak bergeser saat state berganti. */
                        .fi-topbar-start {
                            margin-inline-start: 0;
                        }

                        .fi-topbar-start .fi-topbar-open-collapse-sidebar-btn,
                        .fi-topbar-start .fi-topbar-close-collapse-sidebar-btn {
                            order: -10;
                            flex-shrink: 0;
                            margin-inline: 0;
                        }

                        /* 1b) JARAK HAMBURGER ↔ SEARCH BAR (fix mobile Android).
                           ------------------------------------------------------------
                           Container .fi-topbar bawaan Filament v4 adalah flex
                           TANPA `gap`; di mobile jarak tombol hamburger ke search
                           bar murni bergantung pada `margin-inline-start: auto`
                           milik .fi-topbar-end — artinya jaraknya = "sisa ruang"
                           flex. Di Android Chrome scrollbar klasik memakan lebar
                           viewport (iOS Safari memakai overlay scrollbar), jadi
                           sisa ruang di Android bisa habis (0px) → keduanya
                           tampak mepet, sementara di iOS masih ada sisa beberapa
                           px → tampak normal.

                           Solusinya: beri `gap` eksplisit di container supaya
                           SELALU ada jarak minimum antar elemen flex di semua
                           browser/device, apapun sisa ruangnya. Nilai 0.75rem
                           (setara gap-3) cukup lega di mobile dan tidak mengubah
                           layout desktop (di desktop .fi-topbar-end tetap
                           didorong ms-auto ke pinggir kanan, jadi gap ini hanya
                           mengurangi "sisa ruang", bukan memindahkan elemen).
                           Catatan: elemen dengan display:none (.fi-topbar-start
                           yang hidden di mobile) tidak dihitung dalam gap. */
                        .fi-topbar {
                            gap: 0.75rem;
                        }

                        /* Defensif: tombol hamburger mobile (buka/tutup sidebar)
                           tidak boleh menyusut/tertekan saat search input butuh
                           ruang di layar sempit — yang boleh menyusut hanya
                           search input (perilaku bawaan Filament). */
                        .fi-topbar > .fi-topbar-open-sidebar-btn,
                        .fi-topbar > .fi-topbar-close-sidebar-btn {
                            flex-shrink: 0;
                        }

                        /* Defensif lanjutan (viewport sangat sempit, mis. 320px):
                           izinkan wrapper search menyusut di bawah lebar intrinsik
                           input-nya, sehingga search input "menyerah" dulu
                           (menyusut) sebelum mendorong hamburger — mencegah
                           horizontal overflow khas perhitungan viewport Android.
                           Ini hanya perubahan layout/spacing, perilaku & fungsi
                           search bar tidak disentuh. */
                        .fi-topbar .fi-global-search-ctn {
                            min-width: 0;
                        }

                        /* 2) Garis pemisah vertikal (border-kanan) agar sidebar tampak
                           sebagai area terpisah dari konten. Hadir baik saat sidebar
                           terbuka maupun collapsed (state konsisten). */
                        .fi-main-sidebar {
                            border-inline-end: 1px solid #e5e7eb;
                        }

                        html.dark .fi-main-sidebar {
                            border-inline-end: 1px solid #3f3f46;
                        }

                        /* 3) Latar putih solid + animasi lebar yang halus di desktop.
                           Transisi `width` pada `.fi-sidebar` otomatis menggeser area
                           konten (layout-nya flex), sehingga buka/tutup terasa smooth. */
                        @media (min-width: 64rem) {
                            .fi-sidebar.fi-main-sidebar {
                                width: var(--collapsed-sidebar-width, 4.5rem);
                                background-color: #ffffff;
                                transition:
                                    width 0.25s cubic-bezier(0.4, 0, 0.2, 1),
                                    margin 0.25s cubic-bezier(0.4, 0, 0.2, 1),
                                    border-color 0.25s ease,
                                    background-color 0.25s ease;
                            }

                            .fi-sidebar.fi-main-sidebar.fi-sidebar-open {
                                width: var(--sidebar-width, 20rem);
                            }

                            html.dark .fi-sidebar.fi-main-sidebar {
                                background-color: #18181b;
                            }
                        }

                        /* 4) STATE COLLAPSED (icon-only rail):
                           saat sidebar tertutup, ikon menu tetap RATA KIRI dengan
                           ukuran NORMAL dan spacing yang konsisten antar ikon —
                           bukan `justify-center` / menyusut seperti default Filament.
                           Selector dibatasi body collapsible + `:not(.fi-sidebar-open)`
                           sehingga tidak memengaruhi layout terbuka bawaan. */
                        @media (min-width: 64rem) {
                            .fi-body.fi-body-has-sidebar-collapsible-on-desktop
                            .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-nav {
                                padding-inline: 0;
                            }

                            .fi-body.fi-body-has-sidebar-collapsible-on-desktop
                            .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-nav-groups {
                                margin-inline: 0;
                                padding-inline: 1rem; /* inset kiri seragam utk semua ikon */
                            }

                            /* item biasa + trigger dropdown grup (jika grup berikon):
                               ikon rata kiri (bukan tengah) & tanpa padding ekstra */
                            .fi-body.fi-body-has-sidebar-collapsible-on-desktop
                            .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-item-btn,
                            .fi-body.fi-body-has-sidebar-collapsible-on-desktop
                            .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-group-dropdown-trigger-btn {
                                justify-content: flex-start;
                                padding-inline: 0;
                            }

                            /* jaga ukuran ikon normal & tidak menyusut saat rail sempit */
                            .fi-body.fi-body-has-sidebar-collapsible-on-desktop
                            .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-item-btn > .fi-icon,
                            .fi-body.fi-body-has-sidebar-collapsible-on-desktop
                            .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-group-dropdown-trigger-btn > .fi-icon {
                                flex-shrink: 0;
                            }
                        }

                        /* Pembatas / divider antar grup menu di sidebar. */
                        .fi-sidebar-nav-groups > .fi-sidebar-group + .fi-sidebar-group {
                            border-top: 1px solid rgb(100 116 139 / 0.18);
                            margin-top: 0.75rem;
                            padding-top: 0.75rem;
                        }

                        /* ============================================================
                           HEADER HALAMAN (breadcrumb + judul + aksi header).
                           Beri padding yang jelas + border-bottom tipis (#E5E7EB)
                           sebagai pemisah dengan konten di bawahnya. Background
                           dibiarkan putih (tidak ada warna solid), sesuai desain.
                           Berlaku untuk semua halaman panel -> desain konsisten.
                           ============================================================ */
                        .fi-page-header-main-ctn > .fi-header {
                            padding: 1rem 0 1.25rem;
                            border-bottom: 1px solid #e5e7eb;
                            margin-bottom: 1.5rem;
                        }

                        /* Dark mode: garis pemisah header memakai warna zinc-700
                           (senada dengan border sidebar gelap) — bukan #e5e7eb
                           yang tampak menyilaukan di latar gelap. */
                        html.dark .fi-page-header-main-ctn > .fi-header {
                            border-bottom-color: #3f3f46;
                        }

                        /* Rapatkan jarak antara judul halaman & Section pertama di
                           halaman Edit Expense (hilangkan gap kosong yang besar). */
                        .fi-resource-edit-record-page .fi-page-header-main-ctn {
                            gap: 0.25rem;
                        }
                        .fi-resource-edit-record-page .fi-page-header-main-ctn > .fi-header {
                            margin-bottom: 0.5rem;
                        }

                        /* Rapatkan jarak antara header (breadcrumb + judul + aksi) &
                           card pertama di halaman View Expense (sekitar 16-24px). */
                        .fi-resource-view-record-page .fi-page-header-main-ctn {
                            gap: 0.25rem;
                        }
                        .fi-resource-view-record-page .fi-page-header-main-ctn > .fi-header {
                            margin-bottom: 0.5rem;
                        }

                        /* ============================================================
                           BREADCRUMB NAVIGASI (berlaku di SELURUH panel: Expenses,
                           Categories, Budgets, dst). Filament v4 tidak punya opsi
                           warna breadcrumb via konfigurasi, jadi penyesuaian ini
                           dilakukan via panel theme (CSS yang di-inject lewat
                           render hook HEAD_END — mekanisme resmi Filament, sama
                           seperti kustomisasi sidebar/header di blok ini).
                           - Item non-aktif : abu-abu #6B7280
                           - Separator ">"  : abu terang + spacing lega (tidak mepet)
                           - Item terakhir (halaman aktif): lebih gelap & bold
                           ============================================================ */
                        .fi-breadcrumbs .fi-breadcrumbs-item {
                            color: #6B7280;
                        }

                        .fi-breadcrumbs .fi-breadcrumbs-item .fi-breadcrumbs-item-label {
                            color: #6B7280;
                        }

                        .fi-breadcrumbs .fi-breadcrumbs-item .fi-breadcrumbs-item-label:hover {
                            color: #111827;
                        }

                        /* Separator ">" antar item — abu terang & spacing yang lega. */
                        .fi-breadcrumbs .fi-breadcrumbs-item-separator {
                            color: #9CA3AF;
                            margin-inline: 0.15rem;
                        }

                        /* Item terakhir = halaman aktif (mis. "Edit" / "View"):
                           lebih gelap & bold untuk menandakan posisi saat ini. */
                        .fi-breadcrumbs .fi-breadcrumbs-item:last-child .fi-breadcrumbs-item-label {
                            color: #111827;
                            font-weight: 600;
                        }

                        /* Dark mode: breadcrumb memakai palet terang agar tetap
                           terbaca di latar gelap. Tanpa override ini, warna
                           #111827 (hampir hitam) di atas menjadi tak terlihat
                           saat toggle dark mode dipakai. */
                        html.dark .fi-breadcrumbs .fi-breadcrumbs-item,
                        html.dark .fi-breadcrumbs .fi-breadcrumbs-item .fi-breadcrumbs-item-label {
                            color: #9ca3af;
                        }

                        html.dark .fi-breadcrumbs .fi-breadcrumbs-item .fi-breadcrumbs-item-label:hover {
                            color: #f9fafb;
                        }

                        html.dark .fi-breadcrumbs .fi-breadcrumbs-item-separator {
                            color: #6b7280;
                        }

                        html.dark .fi-breadcrumbs .fi-breadcrumbs-item:last-child .fi-breadcrumbs-item-label {
                            color: #f9fafb;
                        }
                    </style>
                    HTML),
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
