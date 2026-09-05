<?php

use App\Support\MoneyFormatter;

it('memformat Rupiah bulat tanpa desimal ",00"', function (): void {
    expect(MoneyFormatter::format(300))->toBe('Rp 300')
        ->and(MoneyFormatter::format(9300))->toBe('Rp 9.300')
        ->and(MoneyFormatter::format(100000))->toBe('Rp 100.000')
        ->and(MoneyFormatter::format('300.00'))->toBe('Rp 300')
        ->and(MoneyFormatter::format(0))->toBe('Rp 0');
});

it('memakai pemisah ribuan titik dan desimal koma (standar Indonesia)', function (): void {
    expect(MoneyFormatter::format(9300.5))->toBe('Rp 9.300,50')
        ->and(MoneyFormatter::format('5.20'))->toBe('Rp 5,20');
});

it('mengembalikan null untuk nilai kosong', function (): void {
    expect(MoneyFormatter::format(null))->toBeNull()
        ->and(MoneyFormatter::format(''))->toBeNull();
});

it('memformat angka non-uang (qty) tanpa simbol Rp', function (): void {
    expect(MoneyFormatter::number(2))->toBe('2')
        ->and(MoneyFormatter::number(2.5))->toBe('2,50');
});