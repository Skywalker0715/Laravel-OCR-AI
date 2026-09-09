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
 * Verifikasi notice "diproses otomatis" di halaman View Expense:
 *  - hanya tampil saat expense diproses lewat jalur fallback regex
 *    (flag used_fallback = true), bukan saat berhasil diproses AI Cohere;
 *  - ditulis oleh AIParserJob ke kolom used_fallback agar tidak perlu
 *    memanggil API eksternal lagi ketika halaman di-render.
 */
test('halaman View Expense menampilkan notice ketika struk diproses lewat fallback regex', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk fallback',
        'note' => <<<'TXT'
        Karis Jaya Shop
        2023-08-02
        1. Indomie Goreng
        1 x 36,000
        Total Rp 36.000
        Kembali Rp 0
        TXT,
    ]);

    // AI sengaja gagal (HTTP 500) → parser otomatis jatuh ke fallback regex.
    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeTrue();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertSee('Data ini diproses otomatis oleh sistem OCR')
        ->assertSee('Mohon cek sekilas kelengkapannya, dan koreksi lewat tombol Edit jika ada yang kurang pas.');
});

test('halaman View Expense tidak menampilkan notice ketika struk diproses oleh AI Cohere', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response([
        // Bentuk v1 asli dari /v1/chat: teks hasil di key top-level "text" (bukan
        // {"messages":[...]} versi v2 — bentuk v2 itu yang dulu membuat mock
        // "lulus" padahal ekstraksi kode di production rusak).
        'text' => '{"vendor":"Toko Maju","date":"2023-08-02","category":"Makanan & Minuman","items":[{"name":"Beras","qty":1,"price":15000,"subtotal":15000}],"total":15000,"change":0}',
    ], 200)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk AI',
        'note' => 'Toko Maju',
    ]);

    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();

    expect($expense->used_fallback)->toBeFalse();

    Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertDontSee('Data ini diproses otomatis oleh sistem OCR');
});

test('AIParserJob ikut menyimpan flag used_fallback ke database saat menyimpan hasil parsing', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk biasa',
        'note' => <<<'TXT'
        Karis Jaya Shop
        2023-08-02
        Total Rp 70.000
        TXT,
    ]);

    (new AIParserJob($expense))->reprocess($expense);

    expect($expense->fresh()->used_fallback)->toBeTrue();
});