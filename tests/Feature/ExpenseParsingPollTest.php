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

    // Expense yang baru dibuat: foto struk terpasang, note OCR sudah ada, tapi
    // hasil parsing (vendor/amount) belum terisi karena AIParserJob masih
    // berjalan async.
    //
    // receipt_image WAJIB diisi: since AIParserJob hanya di-dispatch bila ada
    // foto struk (lihat CreateExpense::afterCreate()), expense tanpa foto
    // tidak mungkin punya parsing yang tertunda.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Jaya (polling list)',
        'receipt_image' => 'receipts/struk-list.jpg',
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

    // receipt_image wajib terisi — parsing hanya berjalan untuk struk BERFOTO.
    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk Jaya (polling view)',
        'receipt_image' => 'receipts/struk-view.jpg',
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

test('expense TANPA foto struk (belanja manual) tidak memicu badge polling di List', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Belanja manual: tanpa foto struk, vendor & total sengaja DIISI user
    // sendiri. Syarat "vendor & amount kosong" saja sudah terpenuhi di sini —
    // tanpa syarat receipt_image, badge & wire:poll 3 detik akan menyala untuk
    // expense yang TIDAK PERNAH punya job parsing (AIParserJob hanya
    // di-dispatch bila receipt_image terisi), sehingga halaman list melakukan
    // query sia-sia terus-menerus tanpa hasil yang pernah datang.
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja manual (tanpa struk)',
        'vendor' => null,
        'amount' => null,
        'receipt_image' => null,
    ]);

    Livewire::test(ListExpenses::class)
        ->assertDontSee('Sedang diproses')
        ->assertViewHas('isWaitingForParsing', false);
});

test('expense dengan foto struk tapi parsing belum selesai tetap memicu polling', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Kebalikan dari test di atas: struk BERFOTO yang job parsing-nya belum
    // selesai — badge WAJIB tampil supaya user melihat hasil tanpa refresh.
    Expense::create([
        'user_id' => $user->id,
        'title' => 'Struk foto (parsing jalan)',
        'vendor' => null,
        'amount' => null,
        'receipt_image' => 'receipts/struk.jpg',
    ]);

    Livewire::test(ListExpenses::class)
        ->assertSee('Sedang diproses')
        ->assertViewHas('isWaitingForParsing', true);
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
        'receipt_image' => 'receipts/struk-gagal.jpg',
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
