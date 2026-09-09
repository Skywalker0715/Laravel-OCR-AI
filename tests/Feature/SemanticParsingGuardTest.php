<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Guard semantik parsing di AIParserJob::reprocess:
 *
 * MASALAH 1 — AI kadang memilih "Jumlah" (pra-diskon) sebagai total padahal
 * ada "TOTAL BAYAR" (pasca-diskon). Guard memakai baris total-final eksplisit
 * (TOTAL BAYAR / GRAND TOTAL / dst.) sebagai pembanding ber-confidence tinggi.
 *
 * MASALAH 2 — AI menebak "change" dari baris yang bukan kembalian (mis.
 * DP/Bayar, Uang Muka, Sisa Bayar) saat struk tidak punya baris "Kembalian".
 * Guard memaksa change = 0 bila teks OCR tidak memuat kata kunci kembalian.
 *
 * Struk laundry diskon + DP (pola nyata expense id 34 dalam bentuk ringkas):
 */
const LAUNDRY_DISCOUNT_RECEIPT = <<<'TXT'
CLEAN & FRESH LAUNDRY
JL. Angkatan 45 No. 7, Palembang
Tgl Terima : 16/09/2026
Cuci Setrika Reguler
3.5 kg x Rp 8.000/kg = Rp 28.000
Cuci Express (1 hari)
3.5 kg x Rp 8.000/kg = Rp 28.000
Selimut Tebal
1 pcs x Rp 20.000
Jumlah Rp 66.000
Diskon Member 10% -Rp 6.600
TOTAL BAYAR Rp 59.400
DP/Bayar Rp 30.000
SISA BAYAR Rp 29.400
TXT;

test('guard total & kembalian mengoreksi AI pada struk diskon + DP tanpa kembalian (jalur AI)', function () {
    // Simulasi respons AI yang SALAH: memilih "Jumlah" (pra-diskon 66.000)
    // sebagai total dan menebak "DP/Bayar" (30.000) sebagai change.
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"CLEAN & FRESH LAUNDRY","date":"2026-09-16","items":[{"name":"Cuci","qty":3.5,"price":8000,"subtotal":28000}],"total":66000,"change":30000}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Laundry',
        'note' => LAUNDRY_DISCOUNT_RECEIPT,
    ]);

    (new AIParserJob($expense))->handle();
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();
    // Guard total: baris eksplisit "TOTAL BAYAR 59.400" menang atas "Jumlah 66.000".
    expect((float) $expense->amount)->toBe(59400.0);
    // Guard kembalian: tidak ada kata "kembal" di teks → dipaksa 0, bukan 30.000 (DP/Bayar).
    expect((float) $expense->change)->toBe(0.0);
});

test('AI total tetap dipakai saat hanya ada satu kandidat total dan tidak ada baris bayar (regresi aman)', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => '{"vendor":"Toko Sari","date":"2026-09-16","items":[{"name":"Beras","qty":1,"price":66000,"subtotal":66000}],"total":66000,"change":0}',
        ], 200),
    ]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Satu Total',
        'note' => "Toko Sari\nJumlah Rp 66.000\n",
    ]);

    (new AIParserJob($expense))->handle();
    $expense->refresh();

    expect((float) $expense->amount)->toBe(66000.0);
    expect((float) $expense->change)->toBe(0.0);
});

test('guard tidak merusak struk yang punya baris kembalian asli (jalur AI)', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => '{"vendor":"Apotek Sehat","date":"2026-09-16","items":[{"name":"Obat","qty":1,"price":17500,"subtotal":17500}],"total":17500,"change":2500}',
        ], 200),
    ]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Apotek',
        'note' => "Apotek Sehat\nTotal Rp 17.500\nBayar Rp 20.000\nKembali Rp 2.500\n",
    ]);

    (new AIParserJob($expense))->handle();
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();
    expect((float) $expense->amount)->toBe(17500.0);
    expect((float) $expense->change)->toBe(2500.0);
});

test('guard juga berlaku pada jalur fallback regex (struk diskon + DP)', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response('error', 500),
    ]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Laundry Fallback',
        'note' => LAUNDRY_DISCOUNT_RECEIPT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeTrue();
    expect((float) $expense->amount)->toBe(59400.0);
    expect((float) $expense->change)->toBe(0.0);
});

test('guard TIDAK menimpa parsing yang konsisten dengan penjumlahan item saat label eksplisit kemungkinan typo OCR (kasus struk BNI)', function () {
    // Struk BNI: OCR salah baca "TOTAL BAYAR 2 Rp 148.975" (harusnya
    // 146.975). Kali ini AI justru menghitung benar: total = TAG PLN
    // 145.375 + ADMIN BANK 1.600 = 146.975. Selisih ke label eksplisit
    // (2.000) tidak dijelaskan baris diskon/biaya apapun → parsing
    // (146.975) dipertahankan, label kemungkinan typo OCR.
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SYARIAH","date":"2012-04-11","items":[{"name":"TAG PLN","qty":1,"price":145375,"subtotal":145375},{"name":"ADMIN BANK","qty":1,"price":1600,"subtotal":1600}],"total":146975,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk BNI',
        'note' => <<<'TXT'
        ®) rastpay SSBNI
        SYARIAH
        STRUK PEMBAYARAN TAGIHAN LISTRIK
        TANGGAL i 11-04-2012 69:17:56
        RPTAG PLN Rp 145.375
        ‘ADMIN BANK Rp 11600
        TOTAL BAYAR 2 Rp 148.975
        INFORMASI PLN HUB: 123
        TXT,
    ]);

    (new AIParserJob($expense))->handle();
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();
    // Label eksplisit "TOTAL BAYAR 148.975" (typo OCR) TIDAK menimpa 146.975.
    expect((float) $expense->amount)->toBe(146975.0);
    expect((float) $expense->change)->toBe(0.0);
});