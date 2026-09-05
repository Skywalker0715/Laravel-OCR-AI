{{--
    Override tampilan layout "simple" Filament Panels (v4) untuk panel admin.

    Sumber asli: vendor/filament/filament/resources/views/components/layout/simple.blade.php
    (rilis yang dipakai project ini). File ini di-publish agar kustomisasi
    tidak menyentuh folder vendor langsung — Blade view finder selalu
    memprioritaskan resources/views/vendor/filament-panels/ dibanding vendor.

    CATATAN UPGRADE: jika versi Filament diperbarui dan struktur layout
    sederhana berubah, bandingkan ulang file ini dengan versi vendor-nya,
    lalu terapkan kembali blok <style> & layer dekorasi .cb-decor di bawah
    ke salinan baru.

    Kustomisasi yang dilakukan: background halaman auth (Login, Register,
    Forgot Password, Reset Password) & halaman Profile diberi dekorasi hijau
    brand #10B981 — dua blob lingkaran sangat samar (opacity 0.06) di pojok
    kiri-atas & kanan-bawah viewport, plus ilustrasi SVG (struk dengan lipatan
    robek & centang hijau, tas belanja, dan koin "Rp") yang di-render dalam
    satu layer .cb-decor DI BELAKANG card form. Dekorasi memakai SVG inline
    (tanpa file gambar) dan statis (tanpa animasi) agar tetap ringan; di
    layar mobile (< 640px) ilustrasi disembunyikan sehingga card form tetap
    menjadi fokus utama.
--}}
@php
    use Filament\Support\Enums\Width;

    $livewire ??= null;

    $renderHookScopes = $livewire?->getRenderHookScopes();
    $maxContentWidth ??= (filament()->getSimplePageMaxContentWidth() ?? Width::Large);

    if (is_string($maxContentWidth)) {
        $maxContentWidth = Width::tryFrom($maxContentWidth) ?? $maxContentWidth;
    }
@endphp

{{-- CSS background dimuat ke <head> via stack 'styles' (didefinisikan di
     components/layout/base.blade.php). Karena @push dieksekusi sebelum
     komponen base layout dirender, blok ini pasti masuk ke <head> — BUKAN
     sebelum <!DOCTYPE> (yang bisa memicu quirks mode bila dicetak langsung). --}}
@push('styles')
    <style>
        /*
          Background halaman simple-layout (auth pages & profile).

          - Dasar: off-white hangat #FAFAF9 (bukan putih pekat) agar card form
            putih (#FFFFFF) di tengah tetap terlihat menonjol.
          - Dekorasi: dua blob lingkaran hijau #10B981 sangat samar (opacity
            0.06) di pojok kiri-atas & kanan-bawah viewport, ditambah ilustrasi
            SVG (struk, tas belanja, koin "Rp") — semuanya di-render dalam satu
            layer .cb-decor (lihat markup di bawah) di belakang card form.
          - Static sepenuhnya: tanpa animation/transition dan tanpa file gambar
            (SVG inline) agar tetap ringan di semua ukuran layar.
        */
        .fi-simple-layout {
            background-color: #FAFAF9;
            /* Membentuk stacking context sendiri sehingga layer dekorasi
               (.cb-decor dengan z-index: -1) tetap tampil DI ATAS background
               halaman ini, tapi DI BAWAH seluruh konten (termasuk card form
               main.fi-simple-main) tanpa perlu mengubah markup bawaan
               Filament. */
            isolation: isolate;
        }

        /* Dark mode: wrapper dibuat transparan agar background gelap
           (gray-950) dari elemen body milik Filament yang terlihat —
           konsisten dengan tema panel (perilaku existing dipertahankan). */
        html.dark .fi-simple-layout {
            background-color: transparent;
        }

        /*
          Layer dekorasi: satu div fixed yang memenuhi viewport dan memuat
          seluruh SVG dekorasi.
          - z-index: -1      → tepat di atas background halaman, namun di
                               belakang card form & seluruh konten.
          - overflow: hidden → memotong SVG yang sengaja menonjol melewati
                               tepi viewport (offset negatif) supaya tidak
                               memicu scroll horizontal.
          - pointer-events: none → dekorasi (beserta seluruh turunannya)
                               tidak bisa diklik, jadi tidak mengganggu
                               interaksi form sama sekali.
        */
        .cb-decor {
            position: fixed;
            inset: 0;
            z-index: -1;
            overflow: hidden;
            pointer-events: none;
        }

        /* Blob pojok: lingkaran hijau radius 300px, sangat samar. Posisi
           digeser -320px agar hanya seperempat lingkaran yang "muncul"
           dari pojok viewport. */
        .cb-decor-blob {
            position: absolute;
            opacity: 0.06;
        }

        .cb-decor-blob-top {
            top: -320px;
            left: -320px;
        }

        .cb-decor-blob-bottom {
            bottom: -320px;
            right: -320px;
        }

        /* Dark mode: hijau di atas latar gelap sedikit kurang terlihat,
           jadi opacity blob DINAIKKAN agar tetap samar namun terlihat. */
        html.dark .cb-decor-blob {
            opacity: 0.1;
        }

        /*
          Dark mode untuk ilustrasi: badan struk/tas/koin ber-fill putih yang
          di light mode nyaris tak terlihat (menyatu dengan background
          off-white), tapi akan menyilaukan di atas gray-950. Opacity
          keseluruhan ilustrasi diturunkan via html.dark (tanpa mengubah
          desain yang sudah di-approve) — garis hijau #10B981 tetap terbaca
          karena kontras hijau-di-atas-gelap memang tinggi. !important
          diperlukan karena opacity dasar terpasang sebagai inline style
          pada SVG-nya.
        */
        html.dark .cb-decor-struk {
            opacity: 0.5 !important;
        }

        html.dark .cb-decor-tas {
            opacity: 0.5 !important;
        }

        html.dark .cb-decor-koin {
            /* 0.65 di root SVG x 0.75 (opacity <g> bawaan di dalam SVG yang
               di-approve) = efektif ~0.5, selaras struk & tas. */
            opacity: 0.65;
        }

        /* Mobile (< 640px, selaras breakpoint sm Tailwind): sembunyikan
           seluruh ilustrasi (struk, tas, koin) karena layar terlalu sempit —
           dekorasi berisiko menutupi/mendesek form. Yang tersisa hanya
           2 blob samar dari background dasar. */
        @media (max-width: 39.99rem) {
            .cb-decor-illustration {
                display: none;
            }
        }
    </style>
@endpush

<x-filament-panels::layout.base :livewire="$livewire">
    @props([
        'after' => null,
        'heading' => null,
        'subheading' => null,
    ])

    <div class="fi-simple-layout">
        {{-- Layer dekorasi: satu container fixed yang memuat semua SVG di
             bawah ini. Posisinya di belakang card form (z-index: -1), tidak
             bisa diklik (pointer-events: none), dan dipotong di tepi viewport
             (overflow: hidden) — semuanya diatur via CSS .cb-decor di atas.
             aria-hidden agar tidak dibaca screen reader. --}}
        <div class="cb-decor" aria-hidden="true">
            {{-- Blob hijau sangat samar — pojok kiri-atas viewport --}}
            <svg class="cb-decor-blob cb-decor-blob-top" width="640" height="640" viewBox="0 0 640 640">
                <circle cx="320" cy="320" r="300" fill="#10B981"/>
            </svg>

            {{-- Blob hijau sangat samar — pojok kanan-bawah viewport --}}
            <svg class="cb-decor-blob cb-decor-blob-bottom" width="640" height="640" viewBox="0 0 640 640">
                <circle cx="320" cy="320" r="300" fill="#10B981"/>
            </svg>

            <!-- ILUSTRASI STRUK - pojok kiri bawah -->
            <svg class="cb-decor-illustration cb-decor-struk" width="260" height="340" viewBox="0 0 260 340" style="position:absolute; bottom:-40px; left:-30px; opacity:0.9; pointer-events:none;">
                <g transform="rotate(-8 130 170)">
                    <path d="M30 10 H210 V280 L200 290 L190 280 L180 290 L170 280 L160 290 L150 280 L140 290 L130 280 L120 290 L110 280 L100 290 L90 280 L80 290 L70 280 L60 290 L50 280 L40 290 L30 280 Z"
                          fill="#FFFFFF" stroke="#10B981" stroke-width="3" stroke-opacity="0.5"/>
                    <rect x="55" y="40" width="130" height="10" rx="5" fill="#10B981" opacity="0.35"/>
                    <rect x="55" y="65" width="90" height="7" rx="3.5" fill="#10B981" opacity="0.2"/>
                    <rect x="55" y="90" width="150" height="7" rx="3.5" fill="#10B981" opacity="0.2"/>
                    <rect x="55" y="105" width="120" height="7" rx="3.5" fill="#10B981" opacity="0.2"/>
                    <rect x="55" y="130" width="150" height="1.5" fill="#10B981" opacity="0.25"/>
                    <rect x="55" y="150" width="80" height="7" rx="3.5" fill="#10B981" opacity="0.2"/>
                    <rect x="150" y="150" width="55" height="7" rx="3.5" fill="#10B981" opacity="0.2"/>
                    <rect x="55" y="170" width="80" height="7" rx="3.5" fill="#10B981" opacity="0.2"/>
                    <rect x="150" y="170" width="55" height="7" rx="3.5" fill="#10B981" opacity="0.2"/>
                    <rect x="55" y="195" width="150" height="1.5" fill="#10B981" opacity="0.25"/>
                    <rect x="55" y="215" width="60" height="10" rx="5" fill="#10B981" opacity="0.4"/>
                    <rect x="140" y="215" width="65" height="10" rx="5" fill="#10B981" opacity="0.45"/>
                    <circle cx="185" cy="255" r="22" fill="#10B981" opacity="0.9"/>
                    <path d="M175 255 L182 262 L196 246" stroke="#FFFFFF" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
            </svg>

            <!-- TAS BELANJA - pojok kanan atas -->
            <svg class="cb-decor-illustration cb-decor-tas" width="180" height="200" viewBox="0 0 180 200" style="position:absolute; top:-20px; right:0px; opacity:0.85; pointer-events:none;">
                <g transform="rotate(10 90 100)">
                    <path d="M40 60 H140 L130 180 H50 Z" fill="#FFFFFF" stroke="#10B981" stroke-width="3" stroke-opacity="0.5"/>
                    <path d="M60 60 V40 A30 30 0 0 1 120 40 V60" fill="none" stroke="#10B981" stroke-width="5" stroke-opacity="0.6" stroke-linecap="round"/>
                    <rect x="65" y="90" width="50" height="6" rx="3" fill="#10B981" opacity="0.25"/>
                    <rect x="65" y="105" width="35" height="6" rx="3" fill="#10B981" opacity="0.25"/>
                </g>
            </svg>

            {{-- KOIN MELAYANG — bentuk koin & posisi antar-koin dipertahankan
                 persis dari desain yang di-approve; satu-satunya penyesuaian
                 adalah "jendela" viewBox yang dipotong ke area gambar koin
                 (koordinat asli 620-795 × 108-248, skala 1:1) supaya koin
                 bisa ditempel tepat di area kanan-atas dekat tas belanja pada
                 semua lebar layar desktop — bukan melayang di tengah layar
                 seperti saat kanvas asli 100% x 100% dipakai di monitor lebar. --}}
            <svg class="cb-decor-illustration cb-decor-koin" width="175" height="140" viewBox="620 108 175 140" style="position:absolute; top:70px; right:200px; pointer-events:none;">
                <g opacity="0.75">
                    <circle cx="700" cy="140" r="26" fill="#FFFFFF" stroke="#10B981" stroke-width="3" stroke-opacity="0.55"/>
                    <text x="700" y="148" font-family="sans-serif" font-size="18" fill="#10B981" fill-opacity="0.7" text-anchor="middle" font-weight="bold">Rp</text>
                    <circle cx="770" cy="220" r="18" fill="#FFFFFF" stroke="#10B981" stroke-width="2.5" stroke-opacity="0.5"/>
                    <text x="770" y="226" font-family="sans-serif" font-size="13" fill="#10B981" fill-opacity="0.65" text-anchor="middle" font-weight="bold">Rp</text>
                    <circle cx="640" cy="230" r="14" fill="#10B981" fill-opacity="0.5"/>
                </g>
            </svg>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_LAYOUT_START, scopes: $renderHookScopes) }}

        @if (($hasTopbar ?? true) && filament()->auth()->check())
            <div class="fi-simple-layout-header">
                @if (filament()->hasDatabaseNotifications())
                    @livewire(Filament\Livewire\DatabaseNotifications::class, [
                        'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                    ])
                @endif

                @if (filament()->hasUserMenu())
                    @livewire(Filament\Livewire\SimpleUserMenu::class)
                @endif
            </div>
        @endif

        <div class="fi-simple-main-ctn">
            <main
                @class([
                    'fi-simple-main',
                    ($maxContentWidth instanceof Width) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                ])
            >
                {{ $slot }}
            </main>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_LAYOUT_END, scopes: $renderHookScopes) }}
    </div>
</x-filament-panels::layout.base>