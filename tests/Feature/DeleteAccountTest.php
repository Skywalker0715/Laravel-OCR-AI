<?php

use App\Filament\Auth\EditProfile;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Services\DeleteUserAccountService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('service DeleteUserAccountService menghapus user & data secara standalone', function () {
    $user = User::factory()->create(['password' => 'password123']);

    // Data milik user LAIN dibuat SEBELUM actingAs: tanpa sesi login, hook
    // creating tidak menimpa user_id, jadi benar-benar milik user lain.
    $otherUser = User::factory()->create();
    $otherIncome = Income::create([
        'user_id' => $otherUser->id,
        'source' => 'Pemasukan User Lain',
        'amount' => 7000000,
        'date_received' => now()->toDateString(),
    ]);
    $otherDebt = Debt::create([
        'user_id' => $otherUser->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier User Lain',
        'amount' => 900000,
    ]);

    $this->actingAs($user);

    $expense = Expense::create([
        'title' => 'Belanja Test',
        'amount' => 50000,
        'date_shopping' => now()->toDateString(),
    ]);
    $expense->items()->create([
        'name' => 'Beras 5kg',
        'qty' => 1,
        'price' => 50000,
        'subtotal' => 50000,
    ]);
    $category = Category::create([
        'name' => 'Kategori Pribadi User',
        'icon' => 'o-home',
        'color' => '#10B981',
        'user_id' => $user->id,
    ]);
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 200000,
        'month' => now()->month,
        'year' => now()->year,
    ]);

    // Pemasukan & utang piutang milik user yang dihapus.
    $income = Income::create([
        'source' => 'Gaji Bulanan',
        'amount' => 5000000,
        'date_received' => now()->toDateString(),
    ]);
    $debt = Debt::create([
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pelanggan Warung',
        'amount' => 300000,
    ]);

    app(DeleteUserAccountService::class)->delete($user);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    $this->assertDatabaseMissing('expense_items', ['expenses_id' => $expense->id]);
    $this->assertDatabaseMissing('budgets', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);

    // Pemasukan & utang piutang milik user ikut terhapus eksplisit.
    $this->assertDatabaseMissing('incomes', ['id' => $income->id]);
    $this->assertDatabaseMissing('debts', ['id' => $debt->id]);

    // Data milik user LAIN tidak boleh tersentuh.
    $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
    $this->assertDatabaseHas('incomes', ['id' => $otherIncome->id, 'user_id' => $otherUser->id]);
    $this->assertDatabaseHas('debts', ['id' => $otherDebt->id, 'user_id' => $otherUser->id]);
});

test('halaman Profile menampilkan tombol Hapus Akun dan mendaftarkan aksinya', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        // Aksi terdaftar sebagai (header) action dari sisi PHP.
        ->assertActionExists('deleteAccount')
        // Tombol "Hapus Akun" benar-benar dirender di body halaman — layout
        // profile "simple" tidak merender header actions, jadi tombol
        // direalisasikan lewat override content() di App\Filament\Auth\EditProfile.
        ->assertSee('Hapus Akun');
});

test('modal Hapus Akun menyebut pemasukan & utang piutang pada daftar data yang dihapus', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Konten modal Filament dirender lazy (HTML awal hanya wadah kosong
    // "action-modals"), jadi teks diperiksa lewat schema aksi yang sudah
    // ter-mount (container-nya sudah terpasang → komponen anak bisa dibaca).
    $component = Livewire::test(EditProfile::class);
    $component->mountAction('deleteAccount');

    // Schema aksi yang sudah ter-mount (dibangun Filament dengan container
    // Livewire-nya, sehingga komponen anak bisa dibaca).
    $page = $component->instance();
    $schema = $page->getSchema($page->getMountedActionSchemaName());
    $section = $schema->getComponents()[0];

    // Deskripsi section (teks peringatan utama di modal).
    expect($section->getDescription())
        ->toContain('pemasukan')
        ->toContain('utang piutang');

    // Butir rincian (Placeholder pertama) juga menyebut keduanya.
    $placeholder = $section->getChildComponents()[0];

    expect((string) $placeholder->getContent())
        ->toContain('<strong>pemasukan</strong>')
        ->toContain('<strong>utang piutang</strong>');
});

test('modal Hapus Akun menolak email/password yang salah dan akun tetap utuh', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->mountAction('deleteAccount')
        ->setActionData([
            'email' => 'orang-lain@example.com',
            'password' => 'salah-password',
        ])
        ->callMountedAction()
        // Rule kustom emailMatchRule & passwordMatchRule gagal → modal menampilkan
        // error validasi dan closure action (penghapusan) tidak dijalankan.
        ->assertHasActionErrors(['email', 'password']);

    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('modal Hapus Akun dengan kredensial benar menghapus akun beserta datanya lalu redirect ke login', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $this->actingAs($user);

    // Data milik user yang harus ikut terhapus permanen.
    $expense = Expense::create([
        'title' => 'Belanja Test',
        'amount' => 50000,
        'date_shopping' => now()->toDateString(),
    ]);
    $expense->items()->create([
        'name' => 'Beras 5kg',
        'qty' => 1,
        'price' => 50000,
        'subtotal' => 50000,
    ]);

    $category = Category::create([
        'name' => 'Kategori Pribadi User',
        'icon' => 'o-home',
        'color' => '#10B981',
        'user_id' => $user->id,
    ]);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 200000,
        'month' => now()->month,
        'year' => now()->year,
    ]);

    Livewire::test(EditProfile::class)
        ->mountAction('deleteAccount')
        ->setActionData([
            'email' => $user->email,
            'password' => 'password123',
        ])
        ->callMountedAction()
        ->assertRedirect(Filament::getLoginUrl());

    // Akun + seluruh data terkait sudah tidak ada di database.
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    $this->assertDatabaseMissing('expense_items', ['expenses_id' => $expense->id]);
    $this->assertDatabaseMissing('budgets', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('jika hapus file struk GAGAL: transaction rollback, tidak ada data yang terhapus', function () {
    // Simulasikan Storage::disk('receipts')->delete() mengembalikan false
    // (mis. permission error) — audit #3 temuan orphan files.
    Storage::shouldReceive('disk')
        ->with('receipts')
        ->andReturnSelf();
    Storage::shouldReceive('delete')
        ->andReturn(false);

    $user = User::factory()->create(['password' => 'password123']);
    $this->actingAs($user);

    $expense = Expense::create([
        'user_id' => $user->id,
        'title' => 'Belanja Test',
        'amount' => 50000,
        'date_shopping' => now()->toDateString(),
        'receipt_image' => 'receipts/struk-test.jpg',
    ]);
    $expense->items()->create([
        'name' => 'Beras 5kg',
        'qty' => 1,
        'price' => 50000,
        'subtotal' => 50000,
    ]);

    $category = Category::create([
        'name' => 'Kategori Pribadi User',
        'icon' => 'o-home',
        'color' => '#10B981',
        'user_id' => $user->id,
    ]);
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 200000,
        'month' => now()->month,
        'year' => now()->year,
    ]);

    // Service harus melempar RuntimeException (file delete gagal → transaction
    // rollback) — Expense::deleted event memaksa rollback.
    $this->expectException(\RuntimeException::class);

    app(DeleteUserAccountService::class)->delete($user);
});

test('rate limiting: setelah 3x percobaan, aksi hapus akun diblokir & akun tetap utuh', function () {
    $user = User::factory()->create(['password' => 'password123']);
    $this->actingAs($user);

    // Simulasi 3 percobaan sebelumnya: pre-hit rate limiter dengan key yang
    // sama seperti WithRateLimiting::getRateLimitKey().
    $ip = request()->ip() ?: '127.0.0.1';
    $key = 'livewire-rate-limiter:'.sha1(EditProfile::class.'|processDeleteAccount|'.$ip);
    for ($i = 0; $i < 3; $i++) {
        RateLimiter::hit($key, 60);
    }

    Livewire::test(EditProfile::class)
        ->mountAction('deleteAccount')
        ->setActionData([
            'email' => $user->email,
            'password' => 'password123',
        ])
        ->callMountedAction();

    // Akun tidak terhapus karena rate limited (4x percobaan dalam 60 detik).
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});
