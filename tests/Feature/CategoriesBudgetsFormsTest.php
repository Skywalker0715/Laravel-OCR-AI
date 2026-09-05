<?php

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Filament\Resources\Budgets\Pages\EditBudget;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Verifikasi perapian form Create/Edit Categories & Budgets agar konsisten
 * dengan gaya visual Expenses:
 *  - Section "Informasi Kategori" / "Informasi Anggaran" tampil di halaman
 *    Create & Edit (Section ber-icon, pola sama dengan ExpenseForm);
 *  - Select ikon kategori memakai value STRING enum Heroicon sehingga render
 *    maupun penyimpanan ke kolom `categories.icon` bebas error tipe data;
 *  - Budget menolak duplikat kombinasi user + kategori + bulan + tahun.
 */

test('halaman Create Category & Create Budget menampilkan Section konsisten dengan Expenses', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateCategory::class)
        ->assertSee('Informasi Kategori');

    Livewire::test(CreateBudget::class)
        ->assertSee('Informasi Anggaran');
});

test('iconOptions hanya berisi value string Heroicon yang valid (bebas error tipe data)', function () {
    $options = CategoryResource::iconOptions();

    // Jumlah opsi mengikuti jumlah entri ICON_CHOICES (daftar ikon sengaja
    // diperluas untuk utilitas rumah tangga & UMKM), bukan angka hardcoded,
    // agar menambah ikon baru tidak membuat test gagal.
    //
    // getConstant() bertipe mixed — anotasi @var di bawah mendokumentasikan
    // bentuk pasti isinya (baris [enum Heroicon, label]) sehingga analyzer
    // tahu $icon adalah object Heroicon, bukan string, saat mengakses
    // ->value. Tanpa ini IDE menandai "Expected type 'object'. Found 'string'".
    /** @var array<int, array{0: Heroicon, 1: string}> $choices */
    $choices = (new \ReflectionClass(CategoryResource::class))->getConstant('ICON_CHOICES');
    expect($options)->toHaveCount(count($choices));

    foreach ($options as $value => $label) {
        expect($value)->toBeString();
        expect($label)->toBeString();
        // Pratinjau ikon dirender sebagai SVG inline di dalam label.
        expect($label)->toContain('<svg');
        // Kunci wajib value enum Heroicon yang sah — inilah yang tersimpan
        // ke kolom categories.icon.
        expect(Heroicon::tryFrom($value))->not->toBeNull();
    }

    // Setiap entri ICON_CHOICES muncul sebagai opsi dengan labelnya utuh.
    foreach ($choices as [$icon, $choiceLabel]) {
        expect($options)->toHaveKey($icon->value);
        expect($options[$icon->value])->toContain($choiceLabel);
    }
});

test('pilihan ikon mencakup kategori utilitas rumah tangga yang sering dicari', function () {
    $options = CategoryResource::iconOptions();

    // Kontrak minimal: ikon untuk kebutuhan rumah tangga & UMKM berikut
    // wajib tersedia supaya pencarian (mis. "listrik", "air", "wifi")
    // tidak lagi berujung "No options match your search".
    foreach ([
        'o-bolt',                // Listrik
        'o-beaker',              // Air & PDAM
        'o-fire',                // Gas & LPG
        'o-wifi',                // Internet & Wifi
        'o-signal',              // Kuota & Sinyal
        'o-phone',               // Telepon & Pulsa
        'o-device-phone-mobile', // Pulsa & Paket Data
        'o-shield-check',        // Iuran & Keamanan
        'o-home',                // Rumah & Sewa
        'o-tv',                  // TV & Langganan
        'o-building-storefront', // Toko & UMKM
        'o-receipt-percent',     // Pajak & Retribusi
        'o-cake',                // Pesta & Ulang Tahun
    ] as $value) {
        expect($options)->toHaveKey($value);
    }
});

test('form Create Category menyimpan ikon sebagai string tanpa error tipe data', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Kategori Uji',
            'icon' => Heroicon::OutlinedShoppingCart->value,
            'color' => '#10B981',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $category = Category::query()->where('name', 'Kategori Uji')->first();

    expect($category)->not->toBeNull();
    expect($category->icon)->toBe('o-shopping-cart');
    expect($category->user_id)->toBe(auth()->id());
});

test('form Edit Category menyimpan perubahan di dalam Section Informasi Kategori', function () {
    $this->actingAs(User::factory()->create());

    // Kategori milik user (BUKAN default sistem): guard temuan audit #1
    // membuat kategori default (user_id NULL) read-only, jadi test form Edit
    // ini memakai kategori milik user yang sedang login.
    $category = Category::create([
        'name' => 'Kategori Lama',
        'icon' => 'o-film',
        'color' => '#8B5CF6',
        'user_id' => auth()->id(),
    ]);

    Livewire::test(EditCategory::class, ['record' => $category->getKey()])
        ->assertSee('Informasi Kategori')
        ->fillForm([
            'name' => 'Kategori Baru',
            'icon' => Heroicon::OutlinedGift->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $category->refresh();

    expect($category->name)->toBe('Kategori Baru');
    expect($category->icon)->toBe('o-gift');
});

test('Create Budget menolak duplikat kategori + periode milik user yang sama', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $category = Category::create(['name' => 'Makanan Uji', 'icon' => 'o-shopping-cart', 'color' => '#10B981']);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 8,
        'year' => 2026,
    ]);

    // Kombinasi kategori + bulan + tahun sama → ditolak (error di field Bulan).
    Livewire::test(CreateBudget::class)
        ->fillForm([
            'category_id' => (string) $category->id,
            'amount' => '150000',
            'month' => '8',
            'year' => '2026',
        ])
        ->call('create')
        ->assertHasFormErrors(['month']);

    // Bulan berbeda → boleh.
    Livewire::test(CreateBudget::class)
        ->fillForm([
            'category_id' => (string) $category->id,
            'amount' => '150000',
            'month' => '9',
            'year' => '2026',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Budget::withoutGlobalScopes()->count())->toBe(2);
});

test('dua anggaran umum (tanpa kategori) pada periode sama juga ditolak', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => 500000,
        'month' => 1,
        'year' => 2026,
    ]);

    Livewire::test(CreateBudget::class)
        ->fillForm([
            'amount' => '750000',
            'month' => '1',
            'year' => '2026',
        ])
        ->call('create')
        ->assertHasFormErrors(['month']);
});

test('budget user lain dengan kategori & periode sama tidak dianggap duplikat', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $category = Category::create(['name' => 'Transport Uji', 'icon' => 'o-truck', 'color' => '#3B82F6']);

    Budget::create([
        'user_id' => $userA->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 3,
        'year' => 2026,
    ]);

    $this->actingAs($userB);

    Livewire::test(CreateBudget::class)
        ->fillForm([
            'category_id' => (string) $category->id,
            'amount' => '200000',
            'month' => '3',
            'year' => '2026',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Budget::withoutGlobalScopes()->count())->toBe(2);
});

test('Edit Budget tidak menandai record-nya sendiri sebagai duplikat', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $category = Category::create(['name' => 'Belanja Uji', 'icon' => 'o-shopping-bag', 'color' => '#F59E0B']);

    $budget = Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 5,
        'year' => 2026,
    ]);

    Livewire::test(EditBudget::class, ['record' => $budget->getKey()])
        ->fillForm(['amount' => '120000'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $budget->refresh()->amount)->toBe(120000.0);
});

test('Edit Budget menolak perubahan periode yang menabrak budget lain', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $category = Category::create(['name' => 'Hiburan Uji', 'icon' => 'o-film', 'color' => '#8B5CF6']);

    $maret = Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 3,
        'year' => 2026,
    ]);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 4,
        'year' => 2026,
    ]);

    Livewire::test(EditBudget::class, ['record' => $maret->getKey()])
        ->fillForm(['month' => '4'])
        ->call('save')
        ->assertHasFormErrors(['month']);
});

test('judul record Budget informatif untuk breadcrumb & judul halaman (konsisten dengan Expenses)', function () {
    $user = User::factory()->create();

    $category = Category::create(['name' => 'Transportasi Uji', 'icon' => 'o-truck', 'color' => '#3B82F6']);

    $budget = Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => 1,
        'year' => 2026,
    ]);

    expect(BudgetResource::hasRecordTitle())->toBeTrue();
    expect(BudgetResource::getRecordTitle($budget))->toBe('Anggaran Transportasi Uji — Januari 2026');

    $umum = Budget::create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => 50000,
        'month' => 2,
        'year' => 2026,
    ]);

    expect(BudgetResource::getRecordTitle($umum))->toBe('Anggaran Semua Kategori — Februari 2026');
});

/*
 * Guard kategori default sistem (temuan audit #1): kategori dengan user_id
 * NULL dipakai bersama semua user sehingga HARUS read-only — tidak bisa
 * diedit/dihapus oleh siapa pun, termasuk user lain yang sedang login.
 */

test('kategori default sistem tidak bisa diedit maupun dihapus oleh user manapun', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Dibuat tanpa user_id di bawah konteks auth → default sistem.
    $default = Category::create(['name' => 'Default Uji', 'icon' => 'o-film', 'color' => '#8B5CF6']);
    expect($default->user_id)->toBeNull();

    expect(CategoryResource::canEdit($default))->toBeFalse();
    expect(CategoryResource::canDelete($default))->toBeFalse();

    // Halaman Edit menolak akses URL langsung: EditRecord::mount →
    // authorizeAccess() → abort 403. Diuji lewat request HTTP penuh agar
    // meniru persis skenario penyalahgunaan link/ID dari notifikasi.
    $this->get("/admin/categories/{$default->getKey()}/edit")
        ->assertForbidden();
});

test('kategori milik user tetap bisa diedit & dihapus', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $own = Category::create([
        'name' => 'Milik Saya',
        'icon' => 'o-film',
        'color' => '#8B5CF6',
        'user_id' => $user->id,
    ]);

    expect(CategoryResource::canEdit($own))->toBeTrue();
    expect(CategoryResource::canDelete($own))->toBeTrue();
});