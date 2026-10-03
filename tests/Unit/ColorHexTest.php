<?php

use App\Support\ColorHex;

/*
 * Guard format warna kategori (TASK 7 butir 2).
 *
 * Kolom `categories.color` & `category_appearance_overrides.color` hanya
 * varchar(20) — panjang dibatasi database, ISI tidak. Nilai warna dipakai
 * ulang sebagai CSS `background-color`, termasuk di dalam atribut style pada
 * template PDF. Pola hex harus dianggap sah di dua tempat (validasi form +
 * sanitasi sebelum render), dan keduanya harus selalu sama.
 */

test('menerima kode hex 6 digit dalam huruf besar maupun kecil', function () {
    expect(ColorHex::isValid('#10B981'))->toBeTrue()
        ->and(ColorHex::isValid('#10b981'))->toBeTrue()
        ->and(ColorHex::isValid('#000000'))->toBeTrue()
        ->and(ColorHex::isValid('#FFFFFF'))->toBeTrue();
});

test('menolak nilai di luar format hex 6 digit', function (?string $value) {
    // Dipakai sebagai data provider: 3 digit (#FFF), 8 digit (alpha #RRGGBBAA),
    // tanpa "#", alias warna Filament ("red"), string kosong, dan CSS yang
    // attempting keluar dari nilai warna (mis. "red; background-image: ...").
    expect(ColorHex::isValid($value))->toBeFalse();
})->with([
    '#FFF',
    '#10B981FF',
    '10B981',
    'red',
    '',
    'red; background-image: url(http://contoh.test/a.png)',
    'javascript:alert(1)',
]);

test('menolak nilai non-string (mis. array rgba dari ColorPicker)', function () {
    expect(ColorHex::isValid(['r' => 16, 'g' => 185, 'b' => 129]))->toBeFalse()
        ->and(ColorHex::isValid(null))->toBeFalse()
        ->and(ColorHex::isValid(16974565))->toBeFalse();
});

test('safe() mengembalikan hex yang sah apa adanya, termasuk yang spasi berlebih', function () {
    expect(ColorHex::safe('#10B981'))->toBe('#10B981')
        ->and(ColorHex::safe('  #10b981  '))->toBe('#10b981');
});

test('safe() mengganti nilai rusak dengan warna netral, bukan string kosong', function () {
    // Fallback WAJIB berupa hex yang valid: kalau fallback-nya sendiri rusak,
    // satu baris data buruk akan membuat PDF gagal dirender.
    expect(ColorHex::safe(null))->toBe(ColorHex::FALLBACK)
        ->and(ColorHex::safe('bukan-warna'))->toBe(ColorHex::FALLBACK)
        ->and(ColorHex::safe('#GGG'))->toBe(ColorHex::FALLBACK);

    expect(ColorHex::isValid(ColorHex::FALLBACK))->toBeTrue(
        'Warna fallback harus lolos pola yang sama, kalau tidak sanitasi ini tidak berguna.'
    );
});

test('safe() menghormati fallback kustom dari pemanggil', function () {
    expect(ColorHex::safe('rgb(1,2,3)', '#64748B'))->toBe('#64748B');
});