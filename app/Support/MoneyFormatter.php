<?php

namespace App\Support;

/**
 * Formatter tunggal angka Rupiah/umum standar Indonesia (ribuan "." dan desimal ",",
 * tanpa ",00" pada nilai bulat; 2 desimal hanya bila ada sen yang bermakna).
 * Dipakai konsisten di seluruh aplikasi & blade view; dikunci MoneyFormatterTest.
 */
class MoneyFormatter
{
    /**
     * Batas atas nominal uang yang boleh diisi lewat form aplikasi.
     *
     * Kolom uang di database memakai decimal(15,2) (kapasitas ±9,99 triliun),
     * sedangkan batas input dibuat bulat 1 triliun supaya:
     *  - pesan validasi mudah dipahami user ("Maksimal Rp 1.000.000.000.000"),
     *  - masih menyisakan ruang aman di bawah kapasitas kolom sehingga tidak
     *    ada risiko pembulatan di tepi batas (SQLSTATE[22003] numeric overflow),
     *  - tetap jauh di atas kebutuhan nyata segmen personal maupun UMKM.
     *
     * Dipakai bersama oleh form Budget, Income, Debt, dan Expense agar aturan
     * nominal besar konsisten di seluruh aplikasi (satu sumber kebenaran).
     */
    public const MAX_INPUT_AMOUNT = 1_000_000_000_000;

    /** Whole-rupiah limit that fits in budgets.amount decimal(15,2). */
    public const MAX_BUDGET_AMOUNT = 9_999_999_999_999;

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

    /**
     * Pesan validasi standar saat input nominal melewati MAX_INPUT_AMOUNT.
     *
     * Dipakai sebagai `validationMessages(['max' => ...])` pada field nominal
     * (Budget/Income/Debt/Expense) supaya user mendapat pesan berbahasa
     * Indonesia, bukan kegagalan query "numeric field overflow" dari database.
     */
    public static function maxInputMessage(): string
    {
        return 'Nominal terlalu besar. Maksimal '.self::format(self::MAX_INPUT_AMOUNT).'.';
    }

    /** Validation message for the Budget column's database capacity. */
    public static function maxBudgetInputMessage(): string
    {
        return 'Nominal terlalu besar. Maksimal '.self::format(self::MAX_BUDGET_AMOUNT).'.';
    }
}
