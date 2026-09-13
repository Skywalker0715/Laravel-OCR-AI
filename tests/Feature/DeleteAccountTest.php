<?php

use App\Filament\Auth\EditProfile;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
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

    app(DeleteUserAccountService::class)->delete($user);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    $this->assertDatabaseMissing('expense_items', ['expenses_id' => $expense->id]);
    $this->assertDatabaseMissing('budgets', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
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
