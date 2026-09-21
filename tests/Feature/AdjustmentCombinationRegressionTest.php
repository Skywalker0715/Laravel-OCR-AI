<?php

use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Regresi guard total & mismatch item — kombinasi penyesuai (PPN + diskon + biaya).
 */

const SUPERINDO_COMBO_NOTE = <<<'TXT'
    SUPERINDO EXPRESS
    JL. Diponegoro No. 8
    19-09-2026 19:22
    Susu UHT IL 2 x 18.000 = 36.000
    Roti Tawar 1 x 15.500 = 15.500
    Sabun Mandi 3 x 8.000 = 24.000
    Kopi Sachet 1 x 22.000 = 22.000
    subtotal 97.500
    PPN 11% 19.725
    Diskon Promo -5.000
    TOTAL BAYAR 103.225
    TUNATI 105.000
    KEMBALIAN 1.775
    TXT;

test('AI subtotal pra-pajak padahal ada TOTAL BAYAR -> guard timpa kombinasi PPN+diskon tanpa warning', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SUPERINDO EXPRESS","date":"2026-09-19","items":[{"name":"Susu UHT IL","qty":2,"price":18000,"subtotal":36000},{"name":"Roti Tawar","qty":1,"price":15500,"subtotal":15500},{"name":"Sabun Mandi","qty":3,"price":8000,"subtotal":24000},{"name":"Kopi","qty":1,"price":22000,"subtotal":22000}],"total":97500,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Superindo Combo AI',
        'note' => SUPERINDO_COMBO_NOTE,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(103225.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(97500.0)
        ->and($expense->items_mismatch)->toBeFalse();
});

test('AI total benar -> bersih tanpa warning', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"SUPERINDO EXPRESS","date":"2026-09-19","items":[{"name":"Susu UHT IL","qty":2,"price":18000,"subtotal":36000},{"name":"Roti Tawar","qty":1,"price":15500,"subtotal":15500},{"name":"Sabun Mandi","qty":3,"price":8000,"subtotal":24000},{"name":"Kopi","qty":1,"price":22000,"subtotal":22000}],"total":103225,"change":1775}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Superindo Benar',
        'note' => SUPERINDO_COMBO_NOTE,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(103225.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(97500.0)
        ->and($expense->items_mismatch)->toBeFalse();
});

test('DP + biaya admin + PPN (AI) -> kombinasi semua penyesuai tanpa warning', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"Toko ABC","date":"2026-09-19","items":[{"name":"Item A","qty":1,"price":10000,"subtotal":10000},{"name":"Item B","qty":1,"price":20000,"subtotal":20000}],"total":35000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'DP admin PPN',
        'note' => "Toko ABC\nItem A 10.000\nItem B 20.000\nSubtotal 30.000\nPPN 10% 3.000\nBiaya Admin 2.000\nTotal Bayar 35.000\nDP 35.000\n",
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(35000.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(30000.0)
                ->and($expense->items_mismatch)->toBeFalse();
});

test('AI: PPN+diskon retail -> bersih (Alfamart)', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"ALFAMART","date":"2026-09-19","items":[{"name":"Indomie Goreng","qty":1,"price":3500,"subtotal":3500},{"name":"Telur","qty":1,"price":2500,"subtotal":2500}],"total":6050,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Alfamart',
        'note' => "ALFAMART\nIndomie Goreng 3.500\nTelur 2.500\nSubtotal 6.000\nDISKON INSTANT -500\nPPN 11% 550\nTOTAL 6.050\nBAYAR 10.000\nKEMBALIAN 3.950\n",
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    // 6.000 + 550 (PPN) - 500 (diskon) = 6.050.
    expect((float) $expense->amount)->toBe(6050.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(6000.0)
        ->and($expense->items_mismatch)->toBeFalse();
});

test('kasus BNI typo OCR -> tetap ke-flag (regresi negatif)', function () {
    // AI mengembalikan total 146.000 jelas typo (bukan 41.800), tidak ada
    // kombinasi penyesuai yang bisa menghasilkan gap 108.200 -> mismatch asli.
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"BNI SYARIAH","date":"2026-09-19","items":[{"name":"Nasi Goreng","qty":1,"price":18000,"subtotal":18000},{"name":"Mie Ayam","qty":1,"price":20000,"subtotal":20000}],"total":146000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'BNI Typo',
        'note' => "BNI SYARIAH\nNasi Goreng 18.000\nMie Ayam 20.000\nSubtotal 38.000\nPPN 10% 3.800\nTotal Bayar 146000\nDibayar 50.000\nKembalian 8.200\n",
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->items_mismatch)->toBeTrue();
});

test('laundry item ganda (76.000 vs 59.400) tetap ke-flag', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"CLEAN & FRESH LAUNDRY","date":"2026-09-16","items":[{"name":"Cuci Setrika Reguler","qty":1,"price":28000,"subtotal":28000},{"name":"Cuci Express","qty":1,"price":28000,"subtotal":28000},{"name":"Selimut Tebal","qty":1,"price":20000,"subtotal":20000}],"total":59400,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Laundry mismatch',
        'note' => "CLEAN & FRESH LAUNDRY\nCuci Setrika Reguler  28.000\nCuci Express 3.5kg    28.000\nSelimut Tebal         20.000\nJumlah Rp 76.000\nDiskon Member 10% -Rp 7.600\nTOTAL BAYAR Rp 59.400\n",
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(59400.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(76000.0)
        ->and($expense->items_mismatch)->toBeTrue();
});

test('keyword VAT dikenali sebagai pajak -> tidak ada false positive', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"Lab Kopi","date":"2024-01-10","items":[{"name":"Teh","qty":2,"price":5000,"subtotal":10000},{"name":"Lemon","qty":1,"price":100,"subtotal":100},{"name":"Muscat cookie","qty":1,"price":10000,"subtotal":10000}],"total":22110,"change":2890}',
    ], 200)]);

    $user = User::factory()->create();
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Lab Kopi VAT',
        'note' => "Lab Kopi\nTeh x2 @Rp5.000 10.000\nLemon 100\nMuscat cookie 10.000\nSubtotal 20.100\nVAT 10% 2.010\nTOTAL BAYAR 22.110\nBAYAR 25.000\nKEMBALIAN 2.890\n",
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(22110.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(20100.0)
        ->and($expense->items_mismatch)->toBeFalse();
});
