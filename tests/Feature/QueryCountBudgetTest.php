<?php

use App\Filament\Pages\Laporan;
use App\Filament\Resources\Budgets\Pages\ListBudgets;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Widgets\StatsOverview;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryAppearanceOverride;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Anti-N+1 query untuk halaman Categories, Budgets, dan Laporan (PDF).
 *
 * Cara membaca angka: setiap test membandingkan jumlah query pada 5 baris vs
 * 10 baris data. Kalau query-nya tetap sama, berarti perhitungan tidak N+1 —
 * menambah data TIDAK menambah query. Ini lebih tahan perubahan daripada
 * sekadar meng-hardcode angka absolut.
 */

/**
 * Jalankan $callback sambil menghitung query database; kembalikan
 * [hasil callback, jumlah query, daftar SQL].
 *
 * @param  Closure(): mixed  $callback
 * @return array{0: mixed, 1: int, 2: array<int, string>}
 */
function measureQueries(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $result = $callback();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
    }

    return [$result, count($log), array_map(fn (array $q): string => $q['query'], $log)];
}

/**
 * Seed $count kategori milik $user, masing-masing dengan override tampilan
 * supaya jalur displayColorFor()/appearanceOverrideFor() benar-benar dipakai.
 *
 * @return SupportCollection<int, Category>
 */
function seedCategoriesWithOverrides(User $user, int $count, string $prefix = 'Kategori'): SupportCollection
{
    return collect(range(1, $count))->map(function (int $i) use ($user, $prefix): Category {
        $category = Category::create([
            'name' => "{$prefix} {$i}",
            'icon' => 'o-tag',
            'color' => '#64748B',
            'user_id' => $user->id,
        ]);

        CategoryAppearanceOverride::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'icon' => 'o-gift',
            'color' => '#2563EB',
        ]);

        return $category;
    });
}

/**
 * Seed $count budget milik $user + expense pada periode yang sama supaya kolom
 * "Terpakai" benar-benar punya angka (bukan nol semua).
 *
 * Setiap baris memakai kombinasi kategori + bulan yang BERBEDA agar tidak
 * menabrak unique constraint kombinasi user + kategori + bulan + tahun yang
 * sudah ada di tabel budgets. Variasinya bulan dipakai juga supaya query
 * agregat diuji lintas beberapa periode sekaligus.
 *
 * @param  array<int, Category>  $categories
 * @return SupportCollection<int, Budget>
 */
function seedBudgetsWithExpenses(User $user, int $count, array $categories, int $offset = 0): SupportCollection
{
    return collect(range(1, $count))->map(function (int $i) use ($user, $categories, $offset): Budget {
        $seq = $offset + $i;
        $category = $categories[$seq % count($categories)] ?? null;
        $month = 1 + ($seq % 12);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'amount' => 500000,
            'month' => $month,
            'year' => 2026,
        ]);

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'title' => "Belanja {$seq}",
            'amount' => 10000 * $seq,
            'date_shopping' => sprintf('2026-%02d-15', $month),
        ]);

        return $budget;
    });
}

/* -------------------------------------------------------------------------
 * 1. Halaman Categories
 * ---------------------------------------------------------------------- */

test('halaman Categories: jumlah query tetap saat jumlah baris naik', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    seedCategoriesWithOverrides($user, 5, 'Lima');

    [, $queries5] = measureQueries(fn () => Livewire::test(ListCategories::class));

    seedCategoriesWithOverrides($user, 5, 'Tambah');

    [, $queries10] = measureQueries(fn () => Livewire::test(ListCategories::class));

    // 10 baris tidak boleh menambah query dibanding 5 baris (anti N+1).
    expect($queries10)->toBe($queries5);
});

/* -------------------------------------------------------------------------
 * 2. Halaman Budgets
 * ---------------------------------------------------------------------- */

test('halaman Budgets: jumlah query tetap saat jumlah baris naik', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $categories = seedCategoriesWithOverrides($user, 3)->all();
    seedBudgetsWithExpenses($user, 5, $categories);

    [, $queries5] = measureQueries(fn () => Livewire::test(ListBudgets::class));

    seedBudgetsWithExpenses($user, 5, $categories, offset: 5);

    [, $queries10] = measureQueries(fn () => Livewire::test(ListBudgets::class));

    expect($queries10)->toBe($queries5);
});

test('kolom Terpakai memakai SATU query agregat untuk seluruh baris halaman', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $categories = seedCategoriesWithOverrides($user, 3)->all();
    seedBudgetsWithExpenses($user, 5, $categories);

    [, , $log] = measureQueries(fn () => Livewire::test(ListBudgets::class));

    $aggregateQueries = array_values(array_filter(
        $log,
        fn (string $sql): bool => str_contains($sql, '"expenses"')
            && str_contains(strtolower($sql), 'sum('),
    ));

    // Satu render tabel harus memakai tepat SATU query agregat expenses untuk
    // seluruh halaman — bukan satu query per baris.
    expect($aggregateQueries)->toHaveCount(1);
});

/* -------------------------------------------------------------------------
 * 3. Laporan — breakdown kategori di PDF
 * ---------------------------------------------------------------------- */

test('PDF Laporan: breakdown kategori tidak query per kategori', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $categories = seedCategoriesWithOverrides($user, 5)->all();

    foreach ($categories as $i => $category) {
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => "Belanja {$i}",
            'amount' => 10000,
            'date_shopping' => '2026-02-10',
        ]);
    }

    $page = new Laporan;

    [, , $log] = measureQueries(fn () => $page->buildPdfDocument(
        $page->filteredExpensesQuery()->get()
    ));

    $overrideQueries = array_values(array_filter(
        $log,
        fn (string $sql): bool => str_contains($sql, 'category_appearance_overrides'),
    ));

    // 5 kategori berbeda -> query category_appearance_overrides harus tetap 1
    // (satu query eager-load), bukan 5.
    expect($overrideQueries)->toHaveCount(1);
});

/* -------------------------------------------------------------------------
 * 4. Halaman Expenses
 * ---------------------------------------------------------------------- */

/**
 * Seed $count expense milik $user, tiap baris memakai kategori BERBEDA yang
 * sudah punya override tampilan. Kategori berbeda dipakai supaya jalur
 * displayColorFor() benar-benar diuji per baris (kalau semua baris memakai
 * kategori yang sama, N+1 bisa tertutupi cache relasi).
 *
 * @param  array<int, Category>  $categories
 */
function seedExpensesWithCategories(User $user, int $count, array $categories, int $offset = 0): SupportCollection
{
    return collect(range(1, $count))->map(function (int $i) use ($user, $categories, $offset): Expense {
        $category = $categories[($i + $offset) % count($categories)];

        return Expense::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Belanja '.($i + $offset),
            'amount' => 25000,
            'date_shopping' => '2026-03-'.sprintf('%02d', 1 + (($i + $offset) % 28)),
        ]);
    });
}

test('halaman List Expense: jumlah query tetap saat jumlah baris naik', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // 5 kategori berbeda → 5 expense memakai 5 kategori berbeda, masing-masing
    // dengan override tampilan milik user login.
    $categories = seedCategoriesWithOverrides($user, 5, 'Kategori')->all();

    seedExpensesWithCategories($user, 5, $categories);

    [, $queries5, $log5] = measureQueries(fn () => Livewire::test(ListExpenses::class));

    // Tambah 5 baris lagi memakai kategori yang bergeser (offset), supaya
    // eager-load tidak bisa diloloskan oleh cache satu kategori yang sama.
    seedExpensesWithCategories($user, 5, $categories, offset: 5);

    [, $queries10, $log10] = measureQueries(fn () => Livewire::test(ListExpenses::class));

    // 10 baris tidak boleh menambah query dibanding 5 baris (anti N+1).
    expect($queries10)->toBe($queries5);

    // Bukti langsung: query ke tabel kategori & override tetap SATU EACH,
    // bukan satu per baris.
    $categoryQueries = fn (array $log): array => array_values(array_filter(
        $log,
        fn (string $sql): bool => str_contains($sql, 'from "categories"'),
    ));

    $overrideQueries = fn (array $log): array => array_values(array_filter(
        $log,
        fn (string $sql): bool => str_contains($sql, 'category_appearance_overrides'),
    ));

    expect($categoryQueries($log5))->toHaveCount(1)
        ->and($overrideQueries($log5))->toHaveCount(1)
        ->and($categoryQueries($log10))->toHaveCount(1)
        ->and($overrideQueries($log10))->toHaveCount(1);
});

test('eager-load override kategori di halaman List Expense terfilter ke user login', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $this->actingAs($userA);

    // Satu kategori dipakai user A dan B, masing-masing dengan override
    // tampilannya sendiri. Override milik B TIDAK boleh terbawa ke query
    // user A (privasi + benar warna yang tampil).
    $category = Category::create([
        'name' => 'Sembako',
        'icon' => 'o-tag',
        'color' => '#64748B',
        'user_id' => null,
    ]);

    CategoryAppearanceOverride::create([
        'user_id' => $userA->id,
        'category_id' => $category->id,
        'icon' => 'o-gift',
        'color' => '#2563EB',
    ]);

    CategoryAppearanceOverride::create([
        'user_id' => $userB->id,
        'category_id' => $category->id,
        'icon' => 'o-star',
        'color' => '#DC2626',
    ]);

    Expense::create([
        'user_id' => $userA->id,
        'category_id' => $category->id,
        'title' => 'Belanja A',
        'amount' => 10000,
        'date_shopping' => '2026-03-01',
    ]);

    [, , $log] = measureQueries(fn () => Livewire::test(ListExpenses::class));

    $overrideSql = implode(' ', array_filter(
        $log,
        fn (string $sql): bool => str_contains($sql, 'category_appearance_overrides'),
    ));

    // Query override memuat user_id milik user A saja, bukan seluruh override
    // kategori tersebut (termasuk milik user B).
    expect($overrideSql)->toContain('user_id')
        ->and($overrideSql)->toContain((string) $userA->id)
        ->and($overrideSql)->not->toContain((string) $userB->id);
});

/* -------------------------------------------------------------------------
 * 5. Dashboard StatsOverview — amount NULL tidak boleh menggeser rata-rata
 * ---------------------------------------------------------------------- */

test('widget Dashboard menghitung rata-rata hanya dari expense ber-amount', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create(['user_id' => $user->id, 'title' => 'A', 'amount' => 100000, 'date_shopping' => '2026-01-10']);
    Expense::create(['user_id' => $user->id, 'title' => 'B', 'amount' => 300000, 'date_shopping' => '2026-01-11']);

    // Struk yang parsing-nya belum selesai / gagal total: amount NULL.
    // SEBELUM diperbaiki, expense ini ikut dihitung ke COUNT sehingga
    // rata-rata jadi 400.000 / 3 = 133.333 (padahal tidak ada nilainya).
    Expense::create(['user_id' => $user->id, 'title' => 'C (gagal parse)', 'amount' => null, 'date_shopping' => '2026-01-12']);

    $stats = (new ReflectionMethod(StatsOverview::class, 'getStats'))->invoke(new StatsOverview);

    expect($stats[0]->getValue())->toBe('Rp 400.000')
        ->and($stats[1]->getValue())->toBe('2')
        // 400.000 / 2 = 200.000 — bukan 133.333.
        ->and($stats[2]->getValue())->toBe('Rp 200.000');
});

test('kolom Terpakai menghasilkan angka yang sama dengan spentAmount() per baris', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $categories = seedCategoriesWithOverrides($user, 2)->all();

    // Budget kategori + budget umum (category_id NULL) agar kedua cabang
    // query (difilter kategori vs tidak difilter) ikut ter-cover.
    $budgetKategori = Budget::create([
        'user_id' => $user->id,
        'category_id' => $categories[0]->id,
        'amount' => 500000,
        'month' => 6,
        'year' => 2026,
    ]);
    $budgetUmum = Budget::create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => 900000,
        'month' => 6,
        'year' => 2026,
    ]);

    // Expense kategori 1 (masuk hitungan budget kategori), kategori 2 dan
    // tanpa kategori (hanya budget umum), amount NULL, serta expense di luar
    // periode (harus diabaikan semuanya).
    Expense::create(['user_id' => $user->id, 'category_id' => $categories[0]->id, 'title' => 'K1', 'amount' => 75000, 'date_shopping' => '2026-06-02']);
    Expense::create(['user_id' => $user->id, 'category_id' => $categories[1]->id, 'title' => 'K2', 'amount' => 25000, 'date_shopping' => '2026-06-03']);
    Expense::create(['user_id' => $user->id, 'category_id' => null, 'title' => 'Tanpa Kat', 'amount' => 10000, 'date_shopping' => '2026-06-04']);
    Expense::create(['user_id' => $user->id, 'category_id' => $categories[0]->id, 'title' => 'NULL amount', 'amount' => null, 'date_shopping' => '2026-06-05']);
    Expense::create(['user_id' => $user->id, 'category_id' => $categories[0]->id, 'title' => 'Luar Periode', 'amount' => 999000, 'date_shopping' => '2026-07-01']);

    $spent = Budget::query()
        ->get()
        ->mapWithKeys(fn (Budget $budget): array => [$budget->getKey() => $budget->spentAmount()]);

    expect($spent[$budgetKategori->getKey()])->toBe(75000.0)
        ->and($spent[$budgetUmum->getKey()])->toBe(110000.0);
});