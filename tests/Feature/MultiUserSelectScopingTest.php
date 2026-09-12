<?php

use App\Filament\Resources\Budgets\Pages\CreateBudget;
use App\Filament\Resources\Budgets\Pages\EditBudget;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Pages\Laporan;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Support\ReportFilter;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createPrivateCategoryForUser(User $user, string $prefix): Category
{
    return Category::create([
        'name' => "{$prefix} Pribadi",
        'icon' => 'o-film',
        'color' => '#8B5CF6',
        'user_id' => $user->id,
    ]);
}

function createDefaultCategory(string $name): Category
{
    return Category::create([
        'name' => $name,
        'icon' => 'o-film',
        'color' => '#8B5CF6',
    ]);
}

/**
 * Periksa opsi Select untuk memastikan komponen hanya berisi kategori
 * yang diizinkan (default sistem/own) dan TIDAK berisi kategori privat
 * milik user lain (kebocoran scope).
 */
function assertSelectOptions(
    $livewire,
    string $field,
    array $mustContain,
    array $mustNotContain
): void {
    // Temukan komponen Select berdasarkan nama field di seluruh komponen flat
    // schema (key-nya ber-namespace per Section, mis. informasi-belanja::data::section.category_id).
    $schemaName = $livewire->instance()->getDefaultTestingSchemaName();
    $schema = $livewire->instance()->{$schemaName};

    $options = null;

    foreach ($schema->getFlatComponents(withHidden: true) as $candidate) {
        if ($candidate instanceof Select && $candidate->getName() === $field) {
            $options = $candidate->getOptions();
            break;
        }
    }

    expect($options)->not->toBeNull("Select [{$field}] tidak ditemukan pada schema [{$schemaName}].");

    foreach ($mustContain as $id) {
        expect($options)->toHaveKey($id);
    }

    foreach ($mustNotContain as $id) {
        expect($options)->not->toHaveKey($id);
    }
}

test('ExpenseForm tidak menampilkan kategori pribadi milik user lain', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $kategoriPrivatB = createPrivateCategoryForUser($userB, 'B');
    $kategoriDefault = createDefaultCategory('Kategori Sistem');

    $this->actingAs($userA);

    // User A: kategori B tidak boleh muncul, kategori default sistem boleh.
    assertSelectOptions(
        Livewire::test(CreateExpense::class),
        'category_id',
        mustContain: [$kategoriDefault->id],
        mustNotContain: [$kategoriPrivatB->id],
    );

    $kategoriPrivatA = createPrivateCategoryForUser($userA, 'A');

    $this->actingAs($userB);

    // User B: kategori A tidak boleh muncul; kategori sendiri & default boleh.
    assertSelectOptions(
        Livewire::test(CreateExpense::class),
        'category_id',
        mustContain: [$kategoriDefault->id, $kategoriPrivatB->id],
        mustNotContain: [$kategoriPrivatA->id],
    );
});

test('ExpenseForm halaman Edit juga tidak menampilkan kategori milik user lain', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $expense = Expense::create([
        'user_id' => $userA->id,
        'title' => 'Belanja A',
    ]);

    $kategoriPrivatB = createPrivateCategoryForUser($userB, 'B');
    $kategoriDefault = createDefaultCategory('Kategori Sistem');

    $this->actingAs($userA);

    assertSelectOptions(
        Livewire::test(EditExpense::class, ['record' => $expense->getKey()]),
        'category_id',
        mustContain: [$kategoriDefault->id],
        mustNotContain: [$kategoriPrivatB->id],
    );
});

test('BudgetForm tidak menampilkan kategori pribadi milik user lain', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $kategoriPrivatB = createPrivateCategoryForUser($userB, 'B');
    $kategoriDefault = createDefaultCategory('Kategori Sistem');

    $this->actingAs($userA);

    assertSelectOptions(
        Livewire::test(CreateBudget::class),
        'category_id',
        mustContain: [$kategoriDefault->id],
        mustNotContain: [$kategoriPrivatB->id],
    );

    $kategoriPrivatA = createPrivateCategoryForUser($userA, 'A');

    $this->actingAs($userB);

    assertSelectOptions(
        Livewire::test(CreateBudget::class),
        'category_id',
        mustContain: [$kategoriDefault->id, $kategoriPrivatB->id],
        mustNotContain: [$kategoriPrivatA->id],
    );
});
test('BudgetForm halaman Edit tidak menampilkan kategori milik user lain', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $budget = Budget::create([
        'user_id' => $userA->id,
        'category_id' => null,
        'amount' => 100000,
        'month' => 1,
        'year' => 2026,
    ]);

    $kategoriPrivatB = createPrivateCategoryForUser($userB, 'B');
    $kategoriDefault = createDefaultCategory('Kategori Sistem');

    $this->actingAs($userA);

    assertSelectOptions(
        Livewire::test(EditBudget::class, ['record' => $budget->getKey()]),
        'category_id',
        mustContain: [$kategoriDefault->id],
        mustNotContain: [$kategoriPrivatB->id],
    );
});

test('Laporan filter kategori tidak menampilkan kategori pribadi milik user lain', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $kategoriPrivatB = createPrivateCategoryForUser($userB, 'B');
    $kategoriDefault = createDefaultCategory('Kategori Sistem');

    $this->actingAs($userA);

    $page = new Laporan;
    $page->mount();

    $reflection = new ReflectionMethod(Laporan::class, 'categoryOptions');
    $reflection->setAccessible(true);
    $categoryOptions = $reflection->invoke($page);

    expect($categoryOptions)->not->toHaveKey($kategoriPrivatB->id);
    expect($categoryOptions)->toHaveKey($kategoriDefault->id);

    $kategoriPrivatA = createPrivateCategoryForUser($userA, 'A');

    $this->actingAs($userB);

    $pageB = new Laporan;
    $pageB->mount();

    $reflectionB = new ReflectionMethod(Laporan::class, 'categoryOptions');
    $reflectionB->setAccessible(true);
    $optionsB = $reflectionB->invoke($pageB);

    expect($optionsB)->not->toHaveKey($kategoriPrivatA->id);
    expect($optionsB)->toHaveKey($kategoriDefault->id);
    expect($optionsB)->toHaveKey($kategoriPrivatB->id);
});

test('Laporan filter hanya menghitung expense milik user login', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $expenseA = Expense::create([
        'user_id' => $userA->id,
        'title' => 'Milik A',
        'amount' => 100000,
        'date_shopping' => '2026-02-10',
    ]);

    $expenseB = Expense::create([
        'user_id' => $userB->id,
        'title' => 'Milik B',
        'amount' => 50000,
        'date_shopping' => '2026-02-12',
    ]);

    $this->actingAs($userA);

    $page = new Laporan;
    $page->filters = [
        'period_mode' => ReportFilter::MODE_RANGE,
        'date_from' => '2026-02-01',
        'date_until' => '2026-02-28',
    ];

    expect($page->filteredExpensesQuery()->pluck('id')->all())
        ->toBe([$expenseA->id]);
});

test('expense milik user lain TIDAK muncul di query user A', function () {
    $userA = User::factory()->create(['name' => 'User A']);
    $userB = User::factory()->create(['name' => 'User B']);

    $expenseA = Expense::create([
        'user_id' => $userA->id,
        'title' => 'Milik A',
    ]);

    $expenseB = Expense::create([
        'user_id' => $userB->id,
        'title' => 'Milik B',
    ]);

    $this->actingAs($userA);

    expect(Expense::query()->pluck('id')->all())->toBe([$expenseA->id]);

    expect(ExpenseResource::resolveRecordRouteBinding($expenseB->id))->toBeNull();
});