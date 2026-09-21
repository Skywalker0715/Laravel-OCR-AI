<?php

use App\Services\Parsing\AdjustmentLinesExplainer;

/**
 * Guard gap total vs Σitem kini harus mengenali KOMBINASI beberapa baris
 * penyesuai (PPN/pajak + diskon + biaya admin sekaligus) dengan tanda
 * plus/minus, bukan hanya satu baris seperti sebelumnya (penyebab false
 * positive "kemungkinan salah baca/typo OCR").
 */
test('gap dijelaskan SATU baris diskon literal (struk barbershop)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // subtotal 85.000 → total 80.750; gap -4.250 = "Member Diskon 5% -4.250".
    expect($explainer->isGapExplained(-4250, 85000, [
        'Member Diskon 5% -4.250',
        'TOTAL 80.750',
    ]))->toBeTrue();
});

test('gap dijelaskan SATU baris persentase tax tanpa nilai literal (PPN 11%)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // subtotal 97.500; gap +10.725 = PPN 11% x 97.500 (tanpa nilai tercetak).
    expect($explainer->isGapExplained(10725, 97500, [
        'Subtotal 97.500',
        'PPN 11%',
        'TOTAL 108.225',
    ]))->toBeTrue();
});

test('gap dijelaskan KOMBINASI PPN + DISKON sekaligus (kasus SUPERINDO id=46)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // subtotal 97.500 → PPN 11% (terhitung 10.725, meski OCR baris menulis
    // 19.725) + Diskon Promo -5.000 → TOTAL BAYAR 103.225. Gap +5.725.
    expect($explainer->isGapExplained(5725, 97500, [
        'Subtotal 97.500',
        'PPN 11% 19.725',
        'Diskon Promo -5.000',
        'TOTAL BAYAR 103.225',
    ], 0.01))->toBeTrue();
});

test('gap dijelaskan kombinasi memakai nilai literal negatif yang tertulis minus', function () {
    $explainer = new AdjustmentLinesExplainer;

    // 19.725 - 5.000 = 14.725 (kombinasi literal OCR, tanpa persen).
    expect($explainer->isGapExplained(14725, 97500, [
        'PPN 11% 19.725',
        'Diskon Promo -5.000',
    ]))->toBeTrue();
});

test('kombinasi yang TIDAK menghasilkan gap → tetap tidak dijelaskan (anti over-match)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // Tidak ada kombinasi 19.725/-5.000/10.725 yang menghasilkan 7.000.
    expect($explainer->isGapExplained(7000, 97500, [
        'PPN 11% 19.725',
        'Diskon Promo -5.000',
    ]))->toBeFalse();
});

test('keyword VAT (Inggris) dikenali sebagai baris pajak (kasus Lab Kopi id=40)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // subtotal 20.100 + VAT 10% (2.010) = total 22.110.
    expect($explainer->isGapExplained(2010, 20100, [
        'Subtotal Rp20.100',
        'VAT, 10% Rp2.010',
        'Total Rp22.110',
    ]))->toBeTrue();
});

test('biaya admin tunggal menjelaskan gap (struk PPOB)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // Harga Produk 50.000 + Biaya Admin 1.500 = TOTAL BAYAR 51.500.
    expect($explainer->isGapExplained(1500, 50000, [
        'Harga Produk 50.000',
        'Biaya Admin 1.500',
        'TOTAL BAYAR 51.500',
    ]))->toBeTrue();
});

test('lakuna dijelaskan diskon persen saat data konsisten (struk laundry 66 ribu)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // Jumlah 66.000 - diskon member 10% (6.600) = 59.400.
    expect($explainer->isGapExplained(-6600, 66000, [
        'Jumlah Rp 66.000',
        'Diskon Member 10% -Rp 6.600',
        'TOTAL BAYAR Rp 59.400',
    ]))->toBeTrue();
});

test('mismatch ASLI tetap terdeteksi: diskon tunggal tidak menutup gap sisa (laundry 76 ribu)', function () {
    $explainer = new AdjustmentLinesExplainer;

    // Item (76.000) vs total 59.400 → gap -16.600; diskon 10% (7.600)
    // tidak menutup seluruh gap → TIDAK dijelaskan.
    expect($explainer->isGapExplained(-16600, 76000, [
        'Jumlah Rp 76.000',
        'Diskon Member 10% -Rp 7.600',
        'TOTAL BAYAR Rp 59.400',
    ]))->toBeFalse();
});

test('kasus BNI (label eksplisit typo OCR) tetap TIDAK dijelaskan → overridden terhindar', function () {
    $explainer = new AdjustmentLinesExplainer;

    // Item TAG PLN 145.375 + ADMIN BANK 1.600 = 146.975; label OCR "148.975"
    // beda 2.000 tanpa baris penyesuai yang menjelaskan.
    expect($explainer->isGapExplained(2000, 146975, [
        'RPTAG PLN Rp 145.375',
        'ADMIN BANK Rp 11600',
        'TOTAL BAYAR 2 Rp 148.975',
    ]))->toBeFalse();
});

test('tanpa baris penyesuai → gap tidak pernah dijelaskan', function () {
    $explainer = new AdjustmentLinesExplainer;

    expect($explainer->isGapExplained(30000, 100000, [
        'TOTAL Rp 130.000',
    ]))->toBeFalse();
});

test('gap nol / di bawah ambang identik → dijelaskan langsung tanpa baris', function () {
    $explainer = new AdjustmentLinesExplainer;

    expect($explainer->isGapExplained(0.0, 100000, []))->toBeTrue();
});

test('baris pembulatan ("Dibulatkan") tidak salah memakai gap yang sama untuk menutup selisih lain', function () {
    $explainer = new AdjustmentLinesExplainer;

    // Diskon 5% -4.250 vs seluruh gap 6.200: pembulatan 80.750 harus diabaikan.
    expect($explainer->isGapExplained(6200, 85000, [
        'Member Diskon 5% -4.250',
        'Dibulatkan 80.750',
    ]))->toBeFalse();
});