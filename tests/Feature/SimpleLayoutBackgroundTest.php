<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Regresi untuk dekorasi background halaman simple-layout Filament (auth
 * pages & profile) yang di-override di:
 * resources/views/vendor/filament-panels/components/layout/simple.blade.php
 *
 * Konsep visual: dasar off-white #FAFAF9 (light) / gray-950 bawaan Filament
 * (dark), dengan layer dekorasi .cb-decor di belakang card form yang berisi
 * 2 blob lingkaran hijau #10B981 sangat samar di pojok viewport + ilustrasi
 * SVG (struk belanja, tas belanja, koin "Rp"). Semua dekorasi adalah SVG
 * inline (tanpa file gambar) dan statis (tanpa animasi).
 */

test('halaman auth menampilkan layer dekorasi blob & ilustrasi SVG di atas off-white', function (string $url) {
    $html = $this->get($url)->assertOk()->getContent();

    // Dasar off-white (light mode) tetap dipertahankan.
    expect($html)->toContain('background-color: #FAFAF9;')
        // Layer dekorasi benar-benar dirender di dalam markup halaman.
        ->and($html)->toContain('<div class="cb-decor" aria-hidden="true">')
        // 2 blob lingkaran hijau radius besar (pojok kiri-atas & kanan-bawah).
        ->and(substr_count($html, '<circle cx="320" cy="320" r="300" fill="#10B981"/>'))->toBe(2)
        // Ilustrasi struk: rotasi khas, tepi bawah robek (zigzag), centang hijau.
        ->and($html)->toContain('rotate(-8 130 170)')
        ->and($html)->toContain('M30 10 H210 V280 L200 290')
        ->and($html)->toContain('M175 255 L182 262 L196 246')
        // Ilustrasi tas belanja: rotasi khas + badan & pegangan tas.
        ->and($html)->toContain('rotate(10 90 100)')
        ->and($html)->toContain('M40 60 H140 L130 180 H50 Z')
        ->and($html)->toContain('M60 60 V40 A30 30 0 0 1 120 40 V60')
        // Koin "Rp" bertuliskan teks (2 koin) + 1 koin polos.
        ->and(substr_count($html, '>Rp</text>'))->toBe(2)
        // Setiap SVG ilustrasi membawa pointer-events: none inline (WAJIB,
        // supaya dekorasi tidak mengganggu klik ke elemen lain).
        ->and(substr_count($html, 'pointer-events:none;'))->toBe(3);
})->with([
    'login' => 'admin/login',
    'register' => 'admin/register',
    'forgot password' => 'admin/password-reset/request',
]);

test('halaman profile juga memakai layout simple dengan layer dekorasi yang sama', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get('admin/profile')->assertOk()->getContent();

    expect($html)->toContain('background-color: #FAFAF9;')
        ->and($html)->toContain('<div class="cb-decor" aria-hidden="true">')
        ->and($html)->toContain('rotate(-8 130 170)')
        ->and($html)->toContain('rotate(10 90 100)')
        ->and($html)->toContain('>Rp</text>');
});

test('dekorasi aman: tanpa file gambar & animasi, di belakang card, serta tersembunyi di mobile', function () {
    $html = $this->get('admin/login')->assertOk()->getContent();

    // Blok <style> milik layout simple ditandai komentar khasnya, lalu
    // komentar CSS & whitespace dibuang agar assertion tidak tergantung
    // pada format penulisan (indentasi/line-break).
    $marker = strpos($html, 'Background halaman simple-layout');
    expect($marker)->not->toBeFalse();

    $blockStart = strrpos(substr($html, 0, (int) $marker), '<style>');
    $blockEnd = strpos($html, '</style>', $marker);
    $rules = preg_replace('/\s+/', '', preg_replace('/\/\*.*?\*\//s', '', substr($html, (int) $blockStart, (int) $blockEnd - (int) $blockStart)));

    expect($rules)
        // Dekorasi SVG inline murni: tanpa referensi file gambar/font
        // eksternal, dan tanpa animasi/transisi.
        ->not->toContain('url(')
        ->not->toContain('animation')
        ->not->toContain('transition')
        // Layer dekorasi: fixed memenuhi viewport, DI BELAKANG card form
        // (z-index: -1), tidak bisa diklik, dan tanpa risiko scroll
        // horizontal berkat overflow: hidden.
        ->toContain('.cb-decor{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none;}')
        // Dasar halaman tetap #FAFAF9; isolation: isolate menjaga layer
        // dekorasi tetap di atas background tapi di bawah konten.
        ->toContain('.fi-simple-layout{background-color:#FAFAF9;isolation:isolate;}')
        // Dark mode: background tetap transparan mengikuti gray-950 Filament.
        ->toContain('html.dark.fi-simple-layout{background-color:transparent;}')
        // Blob samar: opacity 0.06 (light), dinaikkan sedikit di dark mode
        // agar hijau tetap terlihat samar di atas latar gelap.
        ->toContain('.cb-decor-blob{position:absolute;opacity:0.06;}')
        ->toContain('.cb-decor-blob-top{top:-320px;left:-320px;}')
        ->toContain('.cb-decor-blob-bottom{bottom:-320px;right:-320px;}')
        ->toContain('html.dark.cb-decor-blob{opacity:0.1;}')
        // Dark mode: opacity ilustrasi disesuaikan via html.dark tanpa
        // mengubah desain SVG yang sudah di-approve.
        ->toContain('html.dark.cb-decor-struk{opacity:0.5!important;}')
        ->toContain('html.dark.cb-decor-tas{opacity:0.5!important;}')
        ->toContain('html.dark.cb-decor-koin{opacity:0.65;}')
        // Mobile < 640px (≈ breakpoint sm Tailwind): ilustrasi disembunyikan,
        // hanya 2 blob samar yang tersisa.
        ->toContain('@media(max-width:39.99rem){.cb-decor-illustration{display:none;}}');
});
