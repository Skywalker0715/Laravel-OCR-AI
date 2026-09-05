<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Pages\ViewCategory;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Halaman View (Detail) Category & perbaikan kolom Warna di tabel list:
 *  - Statistik (total pengeluaran, jumlah transaksi, 5 transaksi terakhir)
 *    HARUS hanya menghitung expense milik user yang login — kategori default
 *    sistem dipakai lintas user sehingga tanpa scoping angka tercampur
 *    (penting untuk mode UMKM multi-user).
 *  - Section Budget menampilkan budget TERBARU kategori (periode apa pun,
 *    ORDER BY year DESC, month DESC — tidak dibatasi bulan berjalan);
 *    progress pemakaian ditampilkan; tanpa budget sama sekali -> pesan
 *    informatif.
 *  - Kolom Warna dirender ColorColumn (swatch) + kode hex sebagai teks.
 */

test('halaman View Category menampilkan statistik milik user yang login saja', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $category = Category::create([
        'name' => 'Kopi Uji',
        'icon' => 'o-shopping-cart',
        'color' => '#10B981',
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Ngopi Senin',
        'amount' => 25000,
        'date_shopping' => '2026-08-30',
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Ngopi Jumat',
        'amount' => 15000,
        'date_shopping' => '2026-08-28',
    ]);

    // Expense milik user lain pada kategori yang sama TIDAK boleh ikut
    // terhitung maupun muncul di daftar transaksi terakhir.
    Expense::create([
        'user_id' => $other->id,
        'category_id' => $category->id,
        'title' => 'Milik Orang Lain',
        'amount' => 999000,
        'date_shopping' => '2026-08-29',
    ]);

    $this->actingAs($user);

    Livewire::test(ViewCategory::class, ['record' => $category->getKey()])
        ->assertSee('Ringkasan Pengeluaran')
        ->assertSee('Rp 40.000')
        ->assertSee('5 Transaksi Terakhir')
        ->assertSee('Ngopi Senin')
        ->assertSee('Ngopi Jumat')
        ->assertDontSee('Milik Orang Lain');
});

test('halaman View Category menampilkan progress budget terbaru kategori', function () {
    $user = User::factory()->create();

    $category = Category::create([
        'name' => 'Belanja Uji',
        'icon' => 'o-shopping-bag',
        'color' => '#F59E0B',
    ]);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100000,
        'month' => now()->month,
        'year' => now()->year,
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Belanja Mingguan',
        'amount' => 45000,
        'date_shopping' => now()->toDateString(),
    ]);

    $this->actingAs($user);

    Livewire::test(ViewCategory::class, ['record' => $category->getKey()])
        ->assertSee('Budget Aktif')
        // 45.000 dari 100.000 = 45%
        ->assertSee('45%')
        ->assertSee('Rp 45.000')
        ->assertSee('Rp 100.000');
});

test('halaman View Category menampilkan budget periode lampau bila tidak ada budget terbaru lain', function () {
    $user = User::factory()->create();

    $category = Category::create([
        'name' => 'Belanja Uji',
        'icon' => 'o-shopping-bag',
        'color' => '#F59E0B',
    ]);

    // Budget Mei 2025 — TIDAK ada budget pada bulan berjalan. Section Budget
    // tetap harus menampilkan budget terakhir kategori ini (bukan kosong),
    // lengkap dengan periode dan progress pemakaiannya.
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 500000,
        'month' => 5,
        'year' => 2025,
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Belanja Mei',
        'amount' => 90000,
        'date_shopping' => '2025-05-20',
    ]);

    $this->actingAs($user);

    Livewire::test(ViewCategory::class, ['record' => $category->getKey()])
        ->assertSee('Budget Aktif')
        ->assertSee('Mei 2025')
        // 90.000 dari 500.000 = 18%
        ->assertSee('18%')
        ->assertSee('Rp 90.000')
        ->assertSee('Rp 500.000');
});

test('halaman View Category menampilkan pesan bila kategori belum punya budget sama sekali', function () {
    $user = User::factory()->create();

    $category = Category::create([
        'name' => 'Hiburan Uji',
        'icon' => 'o-film',
        'color' => '#8B5CF6',
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Langganan Streaming',
        'amount' => 54000,
        'date_shopping' => now()->toDateString(),
    ]);

    $this->actingAs($user);

    Livewire::test(ViewCategory::class, ['record' => $category->getKey()])
        ->assertSee('Belum ada budget untuk kategori ini');
});

test('kolom Warna di tabel list dirender sebagai swatch ColorColumn + kode hex', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Category::create([
        'name' => 'Warna Uji',
        'icon' => 'o-gift',
        'color' => '#F59E0B',
    ]);

    Livewire::test(ListCategories::class)
        // Kelas CSS khas ColorColumn membuktikan swatch dirender (bukan lagi
        // TextColumn badge seperti sebelumnya).
        ->assertSee('fi-ta-color')
        // Kode hex tetap tampil sebagai teks di sebelah swatch.
        ->assertSee('#F59E0B');
});

test('user tidak bisa membuka View kategori privat milik user lain', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $private = Category::create([
        'name' => 'Kategori Privat',
        'icon' => 'o-gift',
        'color' => '#8B5CF6',
        'user_id' => $owner->id,
    ]);

    $this->actingAs($intruder);

    // getEloquentQuery() membatasi kategori ke default sistem + milik sendiri,
    // sehingga route binding halaman View untuk kategori privat orang lain
    // gagal ditemukan (ModelNotFoundException -> 404 di request nyata).
    //
    // Sengaja memakai Livewire::test() (bukan HTTP GET penuh): middleware
    // Filament\Authenticate meng-abort 403 untuk semua request panel di env
    // non-local ketika User tidak mengimplementasikan kontrak FilamentUser,
    // sehingga test HTTP penuh tidak bisa membedakan skenario otorisasi.
    expect(fn () => Livewire::test(ViewCategory::class, ['record' => $private->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});