<?php

use App\Services\Parsing\ItemHallucinationDetector;

/**
 * Unit test ItemHallucinationDetector — deteksi item kemungkinan halusinasi
 * (nama tidak ditemukan di OCR / nama yatim tanpa nominal) dengan fixture teks
 * OCR mentah asli dari database:
 *  - SECURE PARK (id 33): AI membuat item "Parkir Mobil" dari header tiket +
 *    baris tarif — nama sebenarnya KETEMU sebagai sub-string "TIKET PARKIR
 *    MOBIL", tapi tidak pernah berdampingan dengan nominalnya → HALUSINASI.
 *  - BNI (id 18): item "TAG PLN"/"ADMIN BANK" muncul bersama nominalnya di
 *    baris yang sama (termasuk digit-nyelip "11600" → harga 1600) → SAH.
 */

const HALLUCINATION_SECURE_PARK_NOTE = <<<'TXT'
SECURE PARK
Mall Palembang Square
TIKET PARKIR MOBIL
No. Plat: BG 1234 XYZ
Masuk : 17-09-2026 10:15
kKeluar 17-09-2026 13:47
Durasi : 3 Jam 32 Menit
Tarif Jam ke-1 Rp 5.000
Tarif Jam ke-2 dst x3 Rp 12.000
TOTAL BAYAR Rp 17.000
Simpan tiket sampai keluar area
Kehilangan tiket dikenakan denda
TXT;

const HALLUCINATION_BNI_NOTE = <<<'TXT'
®) rastpay SSBNI
SYARIAH
OKARI MULTIPAYMENT
STRUK PEMBAYARAN TAGIHAN LISTRIK
TANGGAL i 11-04-2012 69:17:56
RPTAG PLN Rp 145.375
‘ADMIN BANK Rp 11600
TOTAL BAYAR 2 Rp 148.975
INFORMASI PLN HUB: 123
TXT;

function hallucinationLinesOf(string $text): array
{
    return array_values(array_filter(array_map('trim', explode("\n", $text))));
}

test('SECURE PARK: item halusinasi (nama dari header tiket, nominal dari baris tarif) terdeteksi', function () {
    $detector = new ItemHallucinationDetector;

    $items = [
        ['name' => 'Parkir Mobil', 'qty' => 1, 'price' => 5000, 'subtotal' => 5000],
        ['name' => 'Parkir Mobil', 'qty' => 2, 'price' => 12000, 'subtotal' => 24000],
    ];

    expect($detector->itemsPossiblyHallucinated(
        $items,
        hallucinationLinesOf(HALLUCINATION_SECURE_PARK_NOTE)
    ))->toBeTrue();
});

test('BNI: item sah (nama + nominal di baris sama, toleransi digit-nyelip) TIDAK terdeteksi halusinasi', function () {
    $detector = new ItemHallucinationDetector;

    // persis seperti yang disimpan AI di DB id 18.
    $items = [
        ['name' => 'TAG PLN', 'qty' => 1, 'price' => 145375, 'subtotal' => 145375],
        ['name' => "'ADMIN BANK", 'qty' => 1, 'price' => 1600, 'subtotal' => 1600],
    ];

    expect($detector->itemsPossiblyHallucinated(
        $items,
        hallucinationLinesOf(HALLUCINATION_BNI_NOTE)
    ))->toBeFalse();
});

test('item format 2-baris "1. Nama" + harga di baris berikutnya (Karis Jaya id 10) tetap SAH', function () {
    // Format "1. Nama" lalu baris berikutnya berisi qty & harga (Karis Jaya id 10).
    $detector = new ItemHallucinationDetector;
    $lines = hallucinationLinesOf(<<<'TXT'
Karis Jaya Shop
1. Indomie Goreng
1 lusin x 36,000
2. Fruit Tea Apple
1 500 ml x 7,000 Rp 7.000
3. Belfood Sosis Bakar
1 x 27,000 Rp 27.000
Total Rp 70.000
TXT);

    $items = [
        ['name' => 'Indomie Goreng', 'qty' => 1, 'price' => 36000, 'subtotal' => 36000],
        ['name' => 'Fruit Tea Apple', 'qty' => 1, 'price' => 7000, 'subtotal' => 7000],
        ['name' => 'Belfood Sosis Bakar', 'qty' => 1, 'price' => 27000, 'subtotal' => 27000],
    ];

    expect($detector->itemsPossiblyHallucinated($items, $lines))->toBeFalse();
});

test('item yang nama dan nominalnya terpisah 2 baris (PPOB "Produk ..." / "Harga Produk ...") tetap SAH', function () {
    $detector = new ItemHallucinationDetector;
    $lines = hallucinationLinesOf(<<<'TXT'
PPOB SEJAHTERA
STRUK TOP UP PULSA
Produk : Pulsa Reguler 50rb
No. Trx : T5250920110544
Harga Produk 50.000
Biaya Admin 1.500
TOTAL BAYAR 51.500
TXT);

    $items = [
        ['name' => 'Pulsa Reguler 50rb', 'qty' => 1, 'price' => 50000, 'subtotal' => 50000],
    ];

    expect($detector->itemsPossiblyHallucinated($items, $lines))->toBeFalse();
});

test('semua nama item TIDAK ADA di teks OCR sama sekali → halusinasi', function () {
    $detector = new ItemHallucinationDetector;

    $items = [
        ['name' => 'Indomie Goreng', 'qty' => 1, 'price' => 36000, 'subtotal' => 36000],
        ['name' => 'Tiket Konser Dangdut', 'qty' => 1, 'price' => 120000, 'subtotal' => 120000],
    ];

    expect($detector->itemsPossiblyHallucinated(
        $items,
        hallucinationLinesOf("Toko ABC\nBeras 5kg 62.000\nTotal 62.000")
    ))->toBeTrue();
});

test('struk tanpa item / item kosong → bukan halusinasi', function () {
    $detector = new ItemHallucinationDetector;

    expect($detector->itemsPossiblyHallucinated(
        [],
        hallucinationLinesOf(HALLUCINATION_SECURE_PARK_NOTE)
    ))->toBeFalse();
});

test('struk tagihan tanpa item (hanya label) → bukan halusinasi', function () {
    $detector = new ItemHallucinationDetector;

    // Tidak ada parsed item sama sekali (struk PLN/PDAM) — tidak dinilai.
    expect($detector->itemsPossiblyHallucinated(
        [],
        hallucinationLinesOf(HALLUCINATION_BNI_NOTE)
    ))->toBeFalse();
});

test('minoritas item yang di-invent tidak menandai seluruh struk (butuh mayoritas > 50%)', function () {
    $detector = new ItemHallucinationDetector;
    $lines = hallucinationLinesOf(<<<'TXT'
TOKO MAJU
Beras 5kg 62.000
Minyak Goreng 2L 36.500
Gula Pasir 1kg 16.000
Telur Ayam 1kg 28.000
Total 142.500
TXT);

    // 4 item SAH + 1 item invent ("Tiket Dangdut") → hanya 1/5 (20%), bukan mayoritas.
    $items = [
        ['name' => 'Beras 5kg', 'qty' => 1, 'price' => 62000, 'subtotal' => 62000],
        ['name' => 'Minyak Goreng 2L', 'qty' => 1, 'price' => 36500, 'subtotal' => 36500],
        ['name' => 'Gula Pasir 1kg', 'qty' => 1, 'price' => 16000, 'subtotal' => 16000],
        ['name' => 'Telur Ayam 1kg', 'qty' => 1, 'price' => 28000, 'subtotal' => 28000],
        ['name' => 'Tiket Dangdut', 'qty' => 1, 'price' => 120000, 'subtotal' => 120000],
    ];

    expect($detector->itemsPossiblyHallucinated($items, $lines))->toBeFalse();
});