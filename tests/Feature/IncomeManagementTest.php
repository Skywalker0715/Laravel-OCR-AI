<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Incomes\IncomeResource;
use App\Filament\Resources\Incomes\Pages\CreateIncome;
use App\Filament\Resources\Incomes\Pages\EditIncome;
use App\Filament\Resources\Incomes\Pages\ListIncomes;
use App\Filament\Resources\Incomes\Pages\ViewIncome;
use App\Filament\Widgets\CategoryChart;
use App\Filament\Widgets\DebtStatsOverview;
use App\Filament\Widgets\ExpenseLineChart;
use App\Filament\Widgets\IncomeStatsOverview;
use App\Filament\Widgets\PendingParsingJobsAlert;
use App\Filament\Widgets\StatsOverview;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Fitur Pemasukan (Income) — resource BARU yang terpisah total dari
 * Expenses (tidak ada satu pun file di app/Filament/Resources/Expenses/
 * yang disentuh). Cakupan test:
 *  - migrasi tabel incomes berjalan bersih (RefreshDatabase) + kolom yang diharapkan,
 *  - CRUD Filament (Create / List / View / Edit / Hapus),
 *  - validasi form (sumber, jumlah, tanggal wajib & nominal tidak negatif),
 *  - isolasi data antar user (OwnedByUserScope, mode UMKM multi-user),
 *  - menu sidebar grup "Keuangan" + judul record,
 *  - kartu statistik baru "Total Pemasukan" di Dashboard (additive).
 */

/**
 * Buat catatan pemasukan milik $user dengan nilai default yang wajar.
 *
 * @param  array<string, mixed>  $attributes
 */
function incomeFor(User $user, array $attributes = []): Income
{
    return Income::create(array_merge([
        'user_id' => $user->id,
        'source' => 'Gaji',
        'amount' => 5000000,
        'date_received' => '2026-09-01',
    ], $attributes));
}

/**
 * Panggil getStats() widget IncomeStatsOverview secara langsung (pola sama
 * dengan DebtStatsOverviewTest untuk DebtStatsOverview).
 *
 * @return array<int, \Filament\Widgets\StatsOverviewWidget\Stat>
 */
function incomeStats(): array
{
    return (new ReflectionMethod(IncomeStatsOverview::class, 'getStats'))->invoke(new IncomeStatsOverview);
}

/* -------------------------------------------------------------------------
 * 1. Migrasi
 * ---------------------------------------------------------------------- */

test('migration incomes berjalan bersih dengan kolom yang diharapkan', function () {
    expect(Schema::hasTable('incomes'))->toBeTrue();

    expect(Schema::hasColumns('incomes', [
        'id',
        'user_id',
        'source',
        'amount',
        'date_received',
        'notes',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    // Presisi amount harus decimal(15,2) — sama dengan expenses.amount.
    // Tipe dibaca via Schema::getColumns agar bekerja lintas driver DB:
    // PostgreSQL/MySQL melaporkan "decimal"/"numeric(15,2)", sedangkan
    // SQLite di test melaporkan "numeric" — keduanya diterima.
    $amountColumn = collect(Schema::getColumns('incomes'))->firstWhere('name', 'amount');
    $amountType = strtolower((string) ($amountColumn['type'] ?? ''));

    expect($amountColumn)->not->toBeNull()
        ->and($amountType)->toMatch('/decimal|numeric/')
        // Nominal wajib diisi (bukan nullable) — konsisten dengan form wajib.
        ->and($amountColumn['nullable'])->toBeFalse();
});

/* -------------------------------------------------------------------------
 * 2. CRUD
 * ---------------------------------------------------------------------- */

test('form Create menyimpan pemasukan baru milik user login', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateIncome::class)
        ->fillForm([
            'source' => 'Freelance',
            'amount' => 750000,
            'date_received' => '2026-09-20',
            'notes' => 'Proyek website klien',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $income = Income::query()->sole();

    expect($income->user_id)->toBe($user->id)
        ->and($income->source)->toBe('Freelance')
        ->and((float) $income->amount)->toBe(750000.0)
        ->and($income->date_received->toDateString())->toBe('2026-09-20')
        ->and($income->notes)->toBe('Proyek website klien');
});

test('form Create menolak sumber, jumlah, & tanggal yang kosong atau tidak valid', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateIncome::class)
        ->fillForm([
            // Sengaja dikosongkan: date_received punya default hari ini,
            // jadi harus di-null-kan agar aturan required benar-benar diuji.
            'source' => '',
            'amount' => -5000,
            'date_received' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['source', 'amount', 'date_received']);

    expect(Income::query()->count())->toBe(0);
});

test('catatan baru selalu memakai user yang sedang login (anti-spoofing)', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(CreateIncome::class)
        ->fillForm([
            'source' => 'Penjualan',
            'amount' => 250000,
            'date_received' => '2026-09-21',
        ])
        // user_id palsu dari sisi klien tidak akan dipakai.
        ->fillForm(['user_id' => $other->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Income::query()->sole()->user_id)->toBe($user->id);
});

test('halaman List & View dapat dibuka lewat HTTP dan menampilkan data pemasukan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $income = incomeFor($user, [
        'source' => 'Penjualan',
        'amount' => 1750000,
        'notes' => 'Hasil jualan pekan ini',
    ]);

    $list = $this->get('/admin/incomes');
    $list->assertOk();
    expect($list->getContent())
        ->toContain('Pemasukan')
        ->toContain('Penjualan');

    $view = $this->get('/admin/incomes/'.$income->getKey());
    $view->assertOk();
    expect($view->getContent())
        ->toContain('Nominal Pemasukan')
        ->toContain('Rp 1.750.000');
});

test('form Edit mengubah pemasukan milik sendiri', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $income = incomeFor($user, [
        'source' => 'Gaji',
        'amount' => 4000000,
        'notes' => 'Catatan lama',
    ]);

    Livewire::test(EditIncome::class, ['record' => $income->getKey()])
        ->fillForm([
            'source' => 'Freelance',
            'amount' => 1250000,
            'date_received' => '2026-09-25',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $income->refresh();

    expect($income->source)->toBe('Freelance')
        ->and((float) $income->amount)->toBe(1250000.0)
        ->and($income->date_received->toDateString())->toBe('2026-09-25')
        // Field yang tidak diubah form saat test tetap utuh.
        ->and($income->notes)->toBe('Catatan lama')
        ->and($income->user_id)->toBe($user->id);
});

test('aksi Hapus pada tabel menghapus catatan pemasukan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $income = incomeFor($user);

    Livewire::test(ListIncomes::class)
        ->callTableAction('delete', $income)
        ->assertHasNoTableActionErrors();

    expect(Income::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------
 * 3. Isolasi data per user (mode UMKM multi-user)
 * ---------------------------------------------------------------------- */

test('user hanya melihat pemasukan miliknya sendiri', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // Dibuat SEBELUM login: hook creating Income memaksa user_id = user
    // login saat terautentikasi (anti-spoofing), jadi pembuatan data milik
    // user lain harus dilakukan tanpa sesi login.
    $incomeA = Income::create([
        'user_id' => $userA->id,
        'source' => 'Gaji',
        'amount' => 5000000,
        'date_received' => '2026-09-01',
    ]);
    $incomeB = Income::create([
        'user_id' => $userB->id,
        'source' => 'Penjualan',
        'amount' => 900000,
        'date_received' => '2026-09-02',
    ]);

    $this->actingAs($userA);
    expect(Income::query()->pluck('id')->all())->toBe([$incomeA->id]);
    expect(IncomeResource::resolveRecordRouteBinding($incomeB->id))->toBeNull();
    Livewire::test(ListIncomes::class)
        ->assertSee('Gaji')
        ->assertDontSee('Penjualan');

    $this->actingAs($userB);
    expect(Income::query()->pluck('id')->all())->toBe([$incomeB->id]);
    expect(IncomeResource::resolveRecordRouteBinding($incomeA->id))->toBeNull();
    Livewire::test(ListIncomes::class)
        ->assertSee('Penjualan')
        ->assertDontSee('Gaji');
});

test('catatan milik user lain tidak bisa dibuka lewat halaman View', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $income = incomeFor($owner);

    $this->actingAs($intruder);

    expect(fn () => Livewire::test(ViewIncome::class, ['record' => $income->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

/* -------------------------------------------------------------------------
 * 4. Navigasi sidebar & judul record
 * ---------------------------------------------------------------------- */

test('menu sidebar memakai grup Keuangan, label Pemasukan, & badge jumlah pemasukan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    incomeFor($user);
    incomeFor($user, ['source' => 'Freelance', 'amount' => 750000]);

    expect(IncomeResource::getNavigationGroup())->toBe('Keuangan')
        ->and(IncomeResource::getNavigationLabel())->toBe('Pemasukan')
        ->and(IncomeResource::getModelLabel())->toBe('Pemasukan')
        ->and(IncomeResource::getNavigationBadge())->toBe('2');
});

test('judul record memakai pola "Pemasukan — sumber"', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(IncomeResource::hasRecordTitle())->toBeTrue();

    $income = incomeFor($user, ['source' => 'Penjualan']);

    expect(IncomeResource::getRecordTitle($income))->toBe('Pemasukan — Penjualan')
        ->and(IncomeResource::getRecordTitle(null))->toBe('Pemasukan');
});

/* -------------------------------------------------------------------------
 * 5. Kartu statistik baru di Dashboard (additive)
 * ---------------------------------------------------------------------- */

test('kartu Total Pemasukan menghitung seluruh pemasukan milik user login (all-time)', function () {
    $other = User::factory()->create();

    // Data milik user lain dibuat SEBELUM login (lihat catatan anti-spoofing).
    Income::create([
        'user_id' => $other->id,
        'source' => 'Penjualan Orang Lain',
        'amount' => 9999000,
        'date_received' => '2026-01-05',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Pemasukan tahun 2023 (lampau) & hari ini — keduanya tetap dihitung,
    // karena kartu ini all-time tanpa filter periode.
    Income::create([
        'user_id' => $user->id,
        'source' => 'Gaji',
        'amount' => 3500000,
        'date_received' => '2023-04-10',
    ]);
    Income::create([
        'user_id' => $user->id,
        'source' => 'Freelance',
        'amount' => 1500000,
        'date_received' => '2026-09-25',
    ]);

    $stats = incomeStats();

    expect($stats)->toHaveCount(1);
    expect($stats[0]->getLabel())->toBe('Total Pemasukan');
    // 3.500.000 + 1.500.000 = 5.000.000 (pemasukan user lain TIDAK ikut).
    expect($stats[0]->getValue())->toBe('Rp 5.000.000');
    expect($stats[0]->getColor())->toBe('success');
});

test('kartu Total Pemasukan menampilkan Rp 0 saat belum ada pemasukan', function () {
    $this->actingAs(User::factory()->create());

    expect(incomeStats()[0]->getValue())->toBe('Rp 0');
});

test('Dashboard mendaftarkan kartu Total Pemasukan tanpa mengubah widget lama', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create(['user_id' => $user->id, 'title' => 'Belanja', 'amount' => 25000]);

    Income::create([
        'user_id' => $user->id,
        'source' => 'Gaji',
        'amount' => 75000,
        'date_received' => '2026-09-25',
    ]);

    // Widget baru terdaftar di Dashboard berdampingan dengan widget lama
    // (widget Filament di-lazy-load, jadi daftarnya diperiksa dari halaman
    // Dashboard-nya langsung — pola sama dengan DebtStatsOverviewTest).
    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect($widgets)
        ->toContain(IncomeStatsOverview::class)
        ->toContain(PendingParsingJobsAlert::class)
        ->toContain(StatsOverview::class)
        ->toContain(DebtStatsOverview::class)
        ->toContain(ExpenseLineChart::class)
        ->toContain(CategoryChart::class);

    // Widget baru benar-benar merender kartu & nominalnya.
    Livewire::test(IncomeStatsOverview::class)
        ->assertSee('Total Pemasukan')
        ->assertSee('Rp 75.000');

    // Widget lama tetap merender angkanya seperti sebelum fitur ini.
    Livewire::test(StatsOverview::class)
        ->assertSee('Total Pengeluaran Keseluruhan')
        ->assertSee('Rp 25.000');

    // Halaman Dashboard tetap dapat dibuka tanpa error.
    $this->get('/admin')->assertOk();
});

test('menu Pemasukan muncul di sidebar Dashboard bersama menu Keuangan lain', function () {
    $this->actingAs(User::factory()->create());

    $content = $this->get('/admin')->assertOk()->getContent();

    expect($content)
        ->toContain('Pemasukan')
        // Grup sidebar "Keuangan" yang sudah ada tetap dipakai bersama
        // menu lain (Expenses/Categories/Budgets/Utang Piutang).
        ->toContain('Keuangan')
        ->toContain('Budgets');
});




