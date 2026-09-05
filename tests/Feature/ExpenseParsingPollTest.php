<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Jobs\AIParserJob;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Verifikasi Livewire polling pada halaman View & List Expense:
 *  - selama parsing OCR/AI masih berjalan (field vendor/amount kosong),
 *    badge "Sedang diproses..." tampil dengan wire:poll.3s aktif;
 *  - begitu job selesai (vendor/amount terisi), polling berhenti (badge
 *    hilang) dan data terbaru tampil TANPA refresh manual dari user.
 */
test('halaman List Expense menampilkan badge polling lalu menghentikannya dan menampilkan data terbaru saat parsing selesai', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Expense yang baru dibuat: note OCR sudah ada, tapi hasil parsing
    // (vendor/amount) belum terisi karena AIParserJob masih berjalan async.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Jaya (polling list)',
        'note' => <<<'TXT'
        Karis Jaya Shop
        Jl. Diponegoro 1, Sby
        2023-08-02 08:46:36
        1. Indomie Goreng
        1 lusin x 36,000
        2. Fruit Tea Apple
        1 500 ml x 7,000 Rp 7.000
        Total Rp 70.000
        Kembali Rp 0
        TXT,
    ]);

    $component = Livewire::test(ListExpenses::class)
        ->assertSee('Sedang diproses');

    // Tick polling pertama: parsing masih berjalan → badge tetap tampil.
    $component->call('checkParsingStatus')->assertSee('Sedang diproses');

    // Queue worker selesai memproses (di test, QUEUE_CONNECTION=sync sehingga
    // reprocess berjalan langsung — perilaku identik dengan job di worker).
    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();
    expect($expense->vendor)->toBe('Karis Jaya Shop');

    // Tick polling berikutnya: hasil sudah tersedia → polling berhenti
    // (badge hilang) dan tabel di-refresh otomatis.
    $component->call('checkParsingStatus')
        ->assertDontSee('Sedang diproses')
        ->assertViewHas('isWaitingForParsing', false);
});

test('halaman View Expense menampilkan badge polling lalu berhenti dan langsung menampilkan hasil parsing', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Jaya (polling view)',
        'note' => <<<'TXT'
        Karis Jaya Shop
        Jl. Diponegoro 1, Sby
        2023-08-02 08:46:36
        1. Indomie Goreng
        1 lusin x 36,000
        Total Rp 36.000
        Kembali Rp 0
        TXT,
    ]);

    $component = Livewire::test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertSee('Sedang diproses');

    // Tick polling pertama: masih pending → badge tetap tampil.
    $component->call('checkParsingStatus')->assertSee('Sedang diproses');

    // Parsing selesai di queue.
    (new AIParserJob($expense))->reprocess($expense);
    $expense->refresh();
    expect($expense->vendor)->toBe('Karis Jaya Shop');

    // Tick polling berikutnya: badge hilang & hasil parsing langsung tampil
    // di infolist (vendor) tanpa reload halaman.
    $component->call('checkParsingStatus')
        ->assertDontSee('Sedang diproses')
        ->assertSee('Karis Jaya Shop')
        ->assertViewHas('isWaitingForParsing', false);
});

test('badge polling tidak tampil sama sekali bila semua expense sudah ter-parse', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk sudah ter-parse',
        'vendor' => 'Karis Jaya Shop',
        'amount' => 70000,
    ]);

    Livewire::test(ListExpenses::class)
        ->assertDontSee('Sedang diproses')
        ->assertViewHas('isWaitingForParsing', false);
});

test('polling berhenti sendiri setelah melewati batas attempt agar tidak membebani server selamanya', function () {
    Http::fake(['https://api.cohere.ai/*' => Http::response('error', 500)]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Parsing gagal permanen: job sudah jalan tapi tidak ada data yang bisa
    // diekstrak (note kosong) → field tetap kosong selamanya.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk gagal parse permanen',
        'note' => '===',
    ]);

    $component = Livewire::test(ListExpenses::class)
        ->assertSee('Sedang diproses');

    // Simulasikan polling hingga batas maksimum (100 attempt).
    $maxAttempts = (new ReflectionClass(ListExpenses::class))
        ->getConstant('MAX_PARSING_POLL_ATTEMPTS');
    expect($maxAttempts)->toBeInt();

    for ($i = 0; $i < $maxAttempts; $i++) {
        $component->call('checkParsingStatus');
    }

    // Setelah batas tercapai: polling berhenti (badge hilang) tanpa error.
    $component->call('checkParsingStatus')
        ->assertDontSee('Sedang diproses')
        ->assertViewHas('isWaitingForParsing', false);
});
