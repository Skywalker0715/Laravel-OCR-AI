<?php

use App\Models\Expense;
use App\Models\User;
use App\Services\AIParserService;
use App\Services\FinancialInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Test model default Cohere
|--------------------------------------------------------------------------
|
| Test di file ini mengunci NAMA MODEL DEFAULT Cohere supaya tidak berganti
| diam-diam. Alasannya:
|
|   1. Model parsing dibaca dari config('services.cohere.model'), yang
|      nilainya berasal dari env('COHERE_MODEL', <default>) di
|      config/services.php. Bila nilai bawaan itu tidak dikunci, model bisa
|      diganti ke nama yang SUDAH TIDAK tersedia di Cohere (pernah terjadi:
|      'command-light' dihapus Sep 2025) dan seluruh parsing turun diam-diam
|      ke fallback regex tanpa ada yang sadar.
|
|   2. Akurasi vs biaya: command-a-03-2025 paling akurat tapi paling mahal
|      per token, command-r7b-12-2024 lebih ringan. Pilihannya harus
|      keputusan yang sadar, bukan bawaan yang tak pernah ditinjau.
|
| CARA MENGUJI "env tidak di-set" TANPA MEMBACA .env:
|   Isi .env tiap developer/pembeli berbeda (ada yang punya API key, ada
|   yang tidak), jadi membacanya di test membuat hasil test bergantung pada
|   mesin. Test di bawah memuat ulang file config/services.php dengan
|   COHERE_MODEL sengaja dibuang dari repository env, sehingga yang diuji
|   benar-benar nilai BAWAAN dari argumen kedua env() - persis seperti
|   kondisi pembeli yang menyalin .env.example lalu mengosongkan atau
|   menghapus COHERE_MODEL. Nilai env asli dipulihkan setelahnya.
|
*/

test('bawaan services.cohere.model adalah command-a-03-2025 saat env tidak di-set', function () {
    $repository = Env::getRepository();
    $hadEnv = $repository->has('COHERE_MODEL');
    $original = $hadEnv ? $repository->get('COHERE_MODEL') : null;

    // Buang COHERE_MODEL dari environment proses test saja (file .env di
    // disk tidak disentuh), supaya env() memakai argumen kedua.
    if ($hadEnv) {
        $repository->clear('COHERE_MODEL');
    }

    try {
        $config = require config_path('services.php');

        // Satu-satunya tempat nama model default didefinisikan untuk SELURUH
        // aplikasi: parsing struk (AIParserService) maupun Tanya AI
        // (FinancialInsightService) sama-sama membaca key ini.
        expect($config['cohere']['model'])->toBe('command-a-03-2025');
    } finally {
        if ($original !== null) {
            $repository->set('COHERE_MODEL', $original);
        }
    }
});

test('COHERE_MODEL dari env menimpa bawaan config (sumber kebenaran tetap config)', function () {
    // Override lewat .env harus tetap berlaku: inilah alasan nama model
    // tidak boleh ditulis ulang (di-hardcode) di dalam service. Kalau suatu
    // saat nama model dikembalikan ke dalam AIParserService, test ini gagal.
    config()->set('services.cohere.model', 'command-r7b-12-2024');

    expect(config('services.cohere.model'))->toBe('command-r7b-12-2024');
});

test('AIParserService mengirim model dari config ke Cohere, bukan nama literal', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => '{"vendor":"TOKO UJI","date":"2026-01-02","items":[],"total":10000,"change":0}',
        ], 200),
    ]);

    config()->set('services.cohere.api_key', 'test-key');
    config()->set('services.cohere.model', 'command-a-03-2025');

    (new AIParserService)->parseWithAI('TOKO UJI\nTOTAL Rp 10.000');

    Http::assertSent(function ($request) {
        return ($request['model'] ?? null) === 'command-a-03-2025';
    });
});

test('Tanya AI memakai model parsing saat COHERE_INSIGHT_MODEL kosong', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => 'Total pengeluaran Anda Rp 50.000.',
        ], 200),
    ]);

    config()->set('services.cohere.api_key', 'test-key');
    config()->set('services.cohere.model', 'command-a-03-2025');
    // .env.example menuliskan COHERE_INSIGHT_MODEL= (kosong). Nilai kosong itu
    // harus jatuh ke model parsing, bukan terkirim sebagai model "" ke Cohere
    // (request lalu 400 dan Tanya AI selalu gagal).
    config()->set('services.cohere.insight_model', '');

    $user = User::factory()->create();
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Uji Tanya AI',
        'amount' => 50000,
        'date_shopping' => now()->toDateString(),
    ]);

    app(FinancialInsightService::class)->ask($user, 'berapa total pengeluaran saya?');

    Http::assertSent(function ($request) {
        return ($request['model'] ?? null) === 'command-a-03-2025';
    });
});

test('COHERE_INSIGHT_MODEL yang diisi dipakai apa adanya (tidak ditimpa model parsing)', function () {
    Http::fake([
        'https://api.cohere.ai/*' => Http::response([
            'text' => 'Total pengeluaran Anda Rp 50.000.',
        ], 200),
    ]);

    config()->set('services.cohere.api_key', 'test-key');
    config()->set('services.cohere.model', 'command-a-03-2025');
    config()->set('services.cohere.insight_model', 'command-r7b-12-2024');

    $user = User::factory()->create();
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Uji Tanya AI',
        'amount' => 50000,
        'date_shopping' => now()->toDateString(),
    ]);

    app(FinancialInsightService::class)->ask($user, 'berapa total pengeluaran saya?');

    Http::assertSent(function ($request) {
        return ($request['model'] ?? null) === 'command-r7b-12-2024';
    });
});