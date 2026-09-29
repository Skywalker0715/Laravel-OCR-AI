<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CategoryChart;
use App\Filament\Widgets\DebtStatsOverview;
use App\Filament\Widgets\ExpenseLineChart;
use App\Filament\Widgets\PendingParsingJobsAlert;
use App\Filament\Widgets\StatsOverview;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Kartu statistik Dashboard "Total Utang Aktif" & "Total Piutang Aktif":
 * widget BARU (DebtStatsOverview) yang dihitung dari SISA tagihan catatan
 * yang belum lunas, hanya untuk user yang sedang login.
 */

/**
 * Panggil getStats() widget secara langsung (pola sama dengan
 * DashboardAllTimeStatsTest untuk StatsOverview).
 *
 * @return array<int, \Filament\Widgets\StatsOverviewWidget\Stat>
 */
function debtStats(): array
{
    return (new ReflectionMethod(DebtStatsOverview::class, 'getStats'))->invoke(new DebtStatsOverview);
}

test('kartu statistik menghitung sisa utang & piutang aktif milik user login', function () {
    $other = User::factory()->create();

    // Data milik user lain dibuat SEBELUM login, karena hook creating Debt
    // memaksa user_id = user login saat terautentikasi (anti-spoofing).
    Debt::create([
        'user_id' => $other->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Milik Orang Lain',
        'amount' => 7777000,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Utang aktif: 500.000 - 200.000 = 300.000
    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Supplier Beras',
        'amount' => 500000,
        'paid_amount' => 200000,
    ]);

    // Piutang aktif: 150.000 (belum dibayar sama sekali)
    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pelanggan Warung',
        'amount' => 150000,
    ]);

    // Sudah lunas → tidak ikut dihitung.
    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_UTANG,
        'counterparty_name' => 'Lunas',
        'amount' => 900000,
        'paid_amount' => 900000,
    ]);

    $stats = debtStats();

    expect($stats)->toHaveCount(2);

    expect($stats[0]->getLabel())->toBe('Total Utang Aktif');
    expect($stats[0]->getValue())->toBe('Rp 300.000');
    expect($stats[0]->getColor())->toBe('danger');

    expect($stats[1]->getLabel())->toBe('Total Piutang Aktif');
    expect($stats[1]->getValue())->toBe('Rp 150.000');
    expect($stats[1]->getColor())->toBe('success');
});

test('kartu statistik menampilkan Rp 0 saat tidak ada catatan aktif', function () {
    $this->actingAs(User::factory()->create());

    $stats = debtStats();

    expect($stats[0]->getValue())->toBe('Rp 0')
        ->and($stats[1]->getValue())->toBe('Rp 0');
});

test('Dashboard mendaftarkan kartu Utang Piutang tanpa menghilangkan widget lama', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create(['user_id' => $user->id, 'title' => 'Belanja', 'amount' => 25000]);

    Debt::create([
        'user_id' => $user->id,
        'type' => Debt::TYPE_PIUTANG,
        'counterparty_name' => 'Pelanggan Warung',
        'amount' => 40000,
    ]);

    // Widget baru terdaftar di Dashboard berdampingan dengan widget lama.
    // (Widget Filament di-lazy-load, jadi daftarnya diperiksa dari halaman
    // Dashboard-nya langsung — pola sama dengan DashboardAllTimeStatsTest.)
    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect($widgets)
        ->toContain(DebtStatsOverview::class)
        ->toContain(StatsOverview::class)
        ->toContain(ExpenseLineChart::class)
        ->toContain(CategoryChart::class)
        ->toContain(PendingParsingJobsAlert::class);

    // Widget baru benar-benar merender kedua kartu & nominalnya.
    Livewire::test(DebtStatsOverview::class)
        ->assertSee('Total Utang Aktif')
        ->assertSee('Total Piutang Aktif')
        ->assertSee('Rp 40.000');

    // Widget lama tetap merender angkanya seperti sebelum fitur ini.
    Livewire::test(StatsOverview::class)
        ->assertSee('Total Pengeluaran Keseluruhan')
        ->assertSee('Rp 25.000');

    // Halaman Dashboard tetap dapat dibuka tanpa error.
    $this->get('/admin')->assertOk();
});

test('menu Utang Piutang muncul di sidebar Dashboard bersama menu Keuangan lain', function () {
    $this->actingAs(User::factory()->create());

    $content = $this->get('/admin')->assertOk()->getContent();

    expect($content)
        ->toContain('Utang Piutang')
        // Grup sidebar "Keuangan" sudah ada sebelumnya dan tetap dipakai
        // bersama menu lain (Expenses/Categories/Budgets).
        ->toContain('Keuangan')
        ->toContain('Budgets');
});
