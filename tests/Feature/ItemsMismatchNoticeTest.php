<?php

use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Verifikasi fitur flag `items_mismatch` + banner peringatan mismatch di
 * halaman View Expense:
 *  - SUM(subtotal item) yang TIDAK cocok dengan Total (selisih signifikan dan
 *    tidak dijelaskan diskon/PPN/biaya) → flag true + banner muncul, TERLEPAS
 *    dari jalur parsing (AI maupun fallback regex);
 *  - item yang cocok (termasuk struk dengan diskon/PPN sah) → flag tetap
 *    false, TIDAK ada false-positive.
 */

/** Struk laundry (kasus nyata expense id=34): OCR membaca dua baris item
 * identik (28.000 + 28.000) padahal salah satunya 18.000, sehingga
 * penjumlahan item 76.000 tidak cocok dengan TOTAL BAYAR 59.400 — meski
 * diskon member 10% (6.600) sah, sisa selisihnya tetap tidak terjelaskan. */
const LAUNDRY_MISMATCH_NOTE = <<<'TXT'
    CLEAN & FRESH LAUNDRY
    JL. Angkatan 45 No. 7, Palembang
    No. Nota : LN-2609-0184
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

test('struk dengan item tidak cocok total (jalur AI) → flag items_mismatch true dan banner peringatan muncul', function () {
    // AI Cohere berhasil (bukan fallback): item hasil AI tetap tidak cocok
    // dengan total — membuktikan banner muncul TERLEPAS dari used_fallback.
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => json_encode([
            'vendor' => 'CLEAN & FRESH LAUNDRY',
            'date' => '2026-09-16',
            'category' => 'Jasa',
            'items' => [
                ['name' => 'Cuci Setrika Reguler', 'qty' => 3.5, 'price' => 8000, 'subtotal' => 28000],
                ['name' => 'Cuci Express (1 hari)', 'qty' => 3.5, 'price' => 8000, 'subtotal' => 28000],
                ['name' => 'Selimut Tebal', 'qty' => 1, 'price' => 20000, 'subtotal' => 20000],
            ],
            'total' => 59400,
            'change' => 0,
        ]),
    ], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Laundry',
        'note' => LAUNDRY_MISMATCH_NOTE,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    // Total tersimpan 59.400, item berjumlah 76.000, selisih 16.600 tidak
    // dijelaskan penuh oleh diskon member 6.600 → mismatch.
    expect((float) $expense->amount)->toBe(59400.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(76000.0)
        ->and($expense->used_fallback)->toBeFalse()
        ->and($expense->items_mismatch)->toBeTrue();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertSee('Jumlah item tidak sama dengan Total — kemungkinan ada kesalahan baca OCR pada salah satu item, mohon periksa manual.')
        // Notice fallback TIDAK muncul karena struk ini lewat jalur AI —
        // banner mismatch berdiri sendiri.
        ->assertDontSee('Data ini diproses otomatis oleh sistem OCR');
});

test('struk dengan item tidak cocok total (jalur fallback regex) → flag true dan banner muncul bersama notice fallback', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Warung Mismatch',
        'note' => <<<'TXT'
            Warung Kopi Sederhana
            Kopi Susu
            1 x 18,000
            Roti Bakar
            1 x 10,000
            Total Rp 45.000
            TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeTrue()
        ->and($expense->items_mismatch)->toBeTrue();

    // Kedua banner muncul bersamaan: notice fallback (jalur parsing) DAN
    // peringatan mismatch (kualitas data) — keduanya saling independen.
    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertSee('Data ini diproses otomatis oleh sistem OCR')
        ->assertSee('Jumlah item tidak sama dengan Total');
});

test('item cocok dengan total (jalur AI) → flag tetap false dan tidak ada banner', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"Toko Maju","date":"2023-08-02","category":"Makanan & Minuman","items":[{"name":"Beras","qty":1,"price":15000,"subtotal":15000}],"total":15000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk AI Cocok',
        'note' => 'Toko Maju',
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse()
        ->and($expense->items_mismatch)->toBeFalse();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertDontSee('Jumlah item tidak sama dengan Total');
});


test('struk Pawoon dengan PPN (10%) sah → selisih dijelaskan PPN, flag tetap false (tidak false-positive)', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Regresi struk Resto Pawang (id=21): item 40.000+4.000+33.000 = 77.000,
    // PPN (10%) 7.700 menjelaskan selisih ke Total 84.700.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Resto Pawang',
        'note' => <<<'TXT'
            Pawoon Resto
            Martabak Original x2 40,000
            Es Teh Manis x1 4000
            Martabak Telur xi 33,000
            Subtotal 7,000
            PPN (10%) 7.700
            Total 84,700
            Tunai 100,000
            Kembali 15,300
            TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(84700.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(77000.0)
        ->and($expense->items_mismatch)->toBeFalse();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertDontSee('Jumlah item tidak sama dengan Total');
});

test('struk Indomaret OCR asli (diskon member sah) → flag tetap false (tidak false-positive)', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Regresi struk Indomaret (id=15): total belanja 90.700 vs penjumlahan
    // item yang menyisakan noise kecil (selisih < Rp1.000) — bukan mismatch.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Indomaret (OCR asli verbatim)',
        'note' => <<<'TXT'
            CV, ANUGERAH
            IDM KTG PLSTK 1M BSR 1-300 308
            SFTX CLN MENST M-L28. 2 23308 46,600
            Vo PT STX : (13,600)
            POCART SWEAT Se@ML 1 7988 7,900
            You C1886 DRK oRS140 7908 7,900
            MLKITA CNOY BTES 246 5200 5,200
            ULTRA SLIM COKLAT200 6600 6,600
            ROMA WFR CHO BLS97.6 9700 (9,700
            VETACIMIN STRIP 2'S 2300 4,600
            PISANG CAVENDISH WHL 658 24.=«15,500
            TOTAL BELANJA 90, 700
            TUNAE : 100,000
            KEMBALE : 9,300,
            ANDA HEMAT : 13,600
            PPN DPP= 73,333 PPN= 8,800
            HARGA JUAL : 95,500
            TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(90700.0)
        ->and($expense->items_mismatch)->toBeFalse();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertDontSee('Jumlah item tidak sama dengan Total');
});

test('struk dengan PPN sah plus noise item rupiah kecil → selisih dijelaskan PPN dengan toleransi residual, flag tetap false', function () {
    // Skenario struk Sumber Rejeki Mart (id=22): item berjumlah 33.000, PPN
    // sah 3.630, Total 36.700. Selisih item-vs-total 3.700 = PPN 3.630 +
    // noise pembacaan item Rp70 — dianggap terjelaskan oleh PPN, bukan
    // mismatch (tanpa toleransi residual, PPN sah akan terpicu false alarm).
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => json_encode([
            'vendor' => 'SUMBER REJEKI MART',
            'date' => '2026-03-15',
            'category' => 'Makanan & Minuman',
            'items' => [
                ['name' => 'Kopi Cap Jago', 'qty' => 1, 'price' => 10500, 'subtotal' => 10500],
                ['name' => 'Teh Kotak', 'qty' => 2, 'price' => 4000, 'subtotal' => 8000],
                ['name' => 'Roti Tawar', 'qty' => 1, 'price' => 14500, 'subtotal' => 14500],
            ],
            'total' => 36700,
            'change' => 3300,
        ]),
    ], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk PPN Residual',
        'note' => <<<'TXT'
            SUMBER REJEKI MART
            Kopi Cap Jago 10,500
            Teh Kotak 8,000
            Roti Tawar 14,500
            subtotal 33,070
            PPN 11% 3,630
            TOTAL 36,700
            TUNAL 40,000
            KEMBALI 3,300
            TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(36700.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(33000.0)
        ->and($expense->items_mismatch)->toBeFalse();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertDontSee('Jumlah item tidak sama dengan Total');
});

test('selisih kecil di bawah ambang (Rp1.000) dianggap noise pembulatan → flag tetap false', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        'text' => '{"vendor":"Toko Murah","date":"2023-08-02","category":"Makanan & Minuman","items":[{"name":"Gula","qty":1,"price":15400,"subtotal":15400}],"total":15000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Selisih Kecil',
        'note' => 'Toko Murah',
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    // Selisih 400 (< Rp1.000 dan < 5% dari total) = noise pembulatan OCR.
    expect((float) $expense->amount)->toBe(15000.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(15400.0)
        ->and($expense->items_mismatch)->toBeFalse();
});

test('sebagian item gagal terbaca OCR (tidak ada baris diskon/PPN) → flag true', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Regresi struk CV. Anugerah (id=7): OCR hanya menangkap 3 dari banyak
    // item, penjumlahan item 62.400 jauh dari TOTAL BELANJA 90.700 tanpa
    // penjelasan apapun → user harus diberi tahu untuk memeriksa manual.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk CV. Anugerah (item kurang)',
        'note' => <<<'TXT'
            eV, ANUGERAK
            IDM KTG PLSTK IW BSR 1-300 308
            SFTX CLN MENST N-L28. 2 23308 46,600
            PISANG CAVENDISH WHL 658 _24.=«15,500
            TOTAL BELANJA 90, 700
            TUNAE : 100,000
            KEMBALE : 9,300
            TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect((float) $expense->amount)->toBe(90700.0)
        ->and((float) $expense->items()->sum('subtotal'))->toBe(62400.0)
        ->and($expense->items_mismatch)->toBeTrue();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertSee('Jumlah item tidak sama dengan Total');
});
