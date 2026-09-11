<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression: halaman Profile (layout "simple") tetap merender tombol
 * "Hapus Akun" di tubuh halaman via override content() — karena layout
 * simple tidak merender header actions.
 *
 * Ini adalah uji setara "buka /admin/profile di browser" pada level HTTP:
 * full-page GET termasuk layout simple + dekorasi blob, lalu pastikan label
 * aksi destruktif terlihat sebagai text. Interaksi modal (field konfirmasi,
 * validasi email/password, dan alur penghapusan + redirect) diuji secara
 * terpisah lewat Livewire::test di DeleteAccountTest.php, karena Filament v4
 * me-render modal konfirmasi secara dinamis lewat Alpine sehingga label/field
 * tidak muncul sebagai text statis pada snapshot HTML.
 */
test('halaman Profile men-render tombol Hapus Akun di tubuh halaman lewat HTTP GET (browser-fidelity)', function () {
    $user = User::factory()->create(['password' => 'password']);
    $this->actingAs($user);

    $response = $this->get('/admin/profile');

    $response->assertOk();
    // Tombol aksi "Hapus Akun" muncul sebagai text yang terlihat di body
    // (section header + action button).
    expect($response->getContent())->toContain('Hapus Akun');
    // Decorasi layout "simple" (blob/illustrasi SVG) juga tetap terlihat,
    // membuktikan rendering penuh layout simple tidak rusak.
    expect($response->getContent())->toContain('cb-decor-tas');
});


