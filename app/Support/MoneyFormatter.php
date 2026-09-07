<?php

namespace App\Support;

/**
 * Formatter tunggal angka Rupiah/umum standar Indonesia (ribuan "." dan desimal ",",
 * tanpa ",00" pada nilai bulat; 2 desimal hanya bila ada sen yang bermakna).
 * Dipakai konsisten di seluruh aplikasi & blade view; dikunci MoneyFormatterTest.
 */
class MoneyFormatter
{
    /** Format nominal menjadi "Rp 9.300" / "Rp 9.300,50", atau null bila kosong. */
    public static function format(int|float|string|null $value): ?string
    {
        $number = self::number($value);

        return $number === null ? null : 'Rp '.$number;
    }

    /** Format angka non-uang (mis. qty) dengan standar Indonesia: "9.300" / "9.300,50", atau null. */
    public static function number(int|float|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        // Nilai bulat → tanpa desimal ("9.300"); nilai pecahan → 2 desimal ("9.300,50").
        // fmod($value, 1) bernilai 0.0 hanya jika tidak ada sisa pecahan sama sekali.
        $decimalPlaces = fmod($value, 1) === 0.0 ? 0 : 2;

        // number_format($value, $decimalPlaces, $decimalSeparator, $thousandsSeparator)
        // dengan separator standar Indonesia: koma untuk desimal, titik untuk ribuan.
        return number_format($value, $decimalPlaces, ',', '.');
    }
}