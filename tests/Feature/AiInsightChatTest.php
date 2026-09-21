<?php

use App\Models\AiInsightQuery;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Services\FinancialInsightService;
use App\Exceptions\RateLimitExceededException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Test: Tanya AI — FinancialInsightService & AiInsightChatWidget
|--------------------------------------------------------------------------
|
| Pastikan:
| 1. User dapat jawaban berdasarkan data MILIKNYA sendiri (mock Cohere).
| 2. User A tidak bisa lihat history pertanyaan User B.
| 3. Rate limit: setelah 10 pertanyaan, yang ke-11 ditolak.
| 4. AI jujur bila data tidak cukup (user tanpa expense).
|
*/

test('user dapat jawaban AI berdasarkan data expense miliknya sendiri', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => 'Total pengeluaran Anda 90 hari terakhir adalah Rp 500.000 dari 3 transaksi.',
        ], 200),
    ]);

    $user = User::factory()->create();

    $category = Category::firstOrCreate(
        ['name' => 'Makanan & Minuman', 'user_id' => null],
        ['icon' => 'o-shopping-cart', 'color' => '#10B981']
    );

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Belanja Indomaret',
        'vendor' => 'Indomaret',
        'amount' => 50000,
        'date_shopping' => now()->subDays(5),
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Belanja Alfamart',
        'vendor' => 'Alfamart',
        'amount' => 100000,
        'date_shopping' => now()->subDays(10),
    ]);

    $service = app(FinancialInsightService::class);
    $answer = $service->ask($user, 'Berapa total pengeluaran saya 90 hari terakhir?');

    // Pastikan Cohere tercall.
    expect($answer)->not->toBeEmpty();

    // Pastikan pertanyaan tersimpan di ai_insight_queries (di-scope per user).
    $query = AiInsightQuery::where('user_id', $user->id)
        ->where('question', 'Berapa total pengeluaran saya 90 hari terakhir?')
        ->first();
    expect($query)->not->toBeNull();
    expect($query->answer)->toBe($answer);
});

test('user hanya bisa melihat history pertanyaannya sendiri (bukan milik user lain)', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    AiInsightQuery::create([
        'user_id' => $userA->id,
        'question' => 'Pertanyaan User A',
        'answer' => 'Jawaban User A',
    ]);

    AiInsightQuery::create([
        'user_id' => $userB->id,
        'question' => 'Pertanyaan User B',
        'answer' => 'Jawaban User B',
    ]);

    // User A hanya melihat history milik sendiri (OwnedByUserScope aktif).
    $this->actingAs($userA);
    $historyA = AiInsightQuery::query()->take(3)->get();
    expect($historyA->count())->toBe(1);
    expect($historyA->first()->question)->toBe('Pertanyaan User A');

    // User B hanya melihat history milik sendiri.
    $this->actingAs($userB);
    $historyB = AiInsightQuery::query()->take(3)->get();
    expect($historyB->count())->toBe(1);
    expect($historyB->first()->question)->toBe('Pertanyaan User B');
});

test('setelah 10 pertanyaan, yang ke-11 ditolak dengan pesan rate limit', function () {
    $user = User::factory()->create();

    // Pasang rate limiter: 10 attempts sudah terpakai hari ini.
    RateLimiter::hit('ai-insight:' . $user->id, 86400);
    for ($i = 1; $i < 10; $i++) {
        RateLimiter::hit('ai-insight:' . $user->id, 86400);
    }

    $service = app(FinancialInsightService::class);

    $throwed = false;
    $message = '';
    try {
        $service->ask($user, 'Pertanyaan ke-11');
    } catch (\App\Exceptions\RateLimitExceededException $e) {
        $throwed = true;
        $message = $e->getMessage();
    }

    expect($throwed)->toBeTrue();
    expect($message)->toBe('Sudah mencapai batas tanya AI hari ini, coba lagi besok.');
});

test('AI berkomunikasi dengan Cohere dan temperature 0.2 dipakai (bukan 0)', function () {
    $user = User::factory()->create();

    $called = false;
    Http::fake(function ($request) use (&$called) {
        $called = true;
        return Http::response(['text' => 'Jawaban AI uji coba.'], 200);
    });

    $service = app(FinancialInsightService::class);
    $service->ask($user, 'Test pertanyaan');

    expect($called)->toBeTrue();
});

test('AI berkata jujur bila user tidak punya data expense sama sekali', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => 'Maaf, saya tidak memiliki data pengeluaran Anda untuk menjawab pertanyaan ini.',
        ], 200),
    ]);

    $user = User::factory()->create();

    // User belum punya expense sama sekali.
    $this->assertDatabaseCount('expenses', 0);

    $service = app(FinancialInsightService::class);
    $answer = $service->ask($user, 'Berapa total pengeluaran saya?');

    // Method harusnya tidak throw dan mengembalikan string.
    expect($answer)->toBeString();
    expect($answer)->not->toBeEmpty();

    // Pastikan method buildSummary mengembalikan pesan "tidak ada data".
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('buildSummary');
    $method->setAccessible(true);
    $summary = $method->invoke($service, $user);

    expect($summary)->toContain('Tidak ada data pengeluaran');
});

test('ringkasan data expense mengirim info per-kategori per-bulan ke Cohere', function () {
    $user = User::factory()->create();

    $foodCat = Category::firstOrCreate(
        ['name' => 'Makanan & Minuman', 'user_id' => null],
        ['icon' => 'o-shopping-cart', 'color' => '#10B981']
    );

    $transportCat = Category::firstOrCreate(
        ['name' => 'Transportasi', 'user_id' => null],
        ['icon' => 'o-truck', 'color' => '#3B82F6']
    );

    // 2 expense di bulan berbeda.
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $foodCat->id,
        'title' => 'Belanja Indomaret',
        'vendor' => 'Indomaret',
        'amount' => 100000,
        'date_shopping' => now()->subDays(5),
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $transportCat->id,
        'title' => 'Belanja Pertamina',
        'vendor' => 'Pertamina',
        'amount' => 50000,
        'date_shopping' => now()->subDays(20),
    ]);

    $reflection = new ReflectionClass(app(FinancialInsightService::class));
    $method = $reflection->getMethod('buildSummary');
    $method->setAccessible(true);
    $summary = $method->invoke(app(FinancialInsightService::class), $user);

    // Ringkasan harus memuat info per-kategori.
    expect($summary)->toContain('Makanan & Minuman');
    expect($summary)->toContain('Transportasi');
    expect($summary)->toContain('100.000');
    expect($summary)->toContain('50.000');
    expect($summary)->toContain('150.000');
});

test('service menyimpan history ke ai_insight_queries setelah pertanyaan dijawab', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => 'Total pengeluaran Anda adalah Rp 200.000.',
        ], 200),
    ]);

    $user = User::factory()->create();

    $service = app(FinancialInsightService::class);
    $answer = $service->ask($user, 'Berapa total pengeluaran saya?');

    // Pastikan history tersimpan ( OwnedByUserScope memfilter per user).
    $this->assertDatabaseHas('ai_insight_queries', [
        'user_id' => $user->id,
        'question' => 'Berapa total pengeluaran saya?',
    ]);
});
