<?php

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Widgets\CategoryChart;
use App\Filament\Widgets\ExpenseLineChart;
use App\Filament\Widgets\StatsOverview;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Membuat 5 expense milik $user dengan date_shopping DAN created_at lama
 * (2023-2025), jauh di luar bulan/tahun berjalan. Dipakai untuk memastikan
 * widget Dashboard & badge menghitung SELURUH riwayat, bukan hanya periode
 * berjalan (regresi: sebelumnya semua difilter created_at >= awal bulan).
 *
 * @return array<int, array{amount: int, date: string}>
 */
function seedLegacyExpensesFor(User $user): array
{
    $records = [
        ['amount' => 200000, 'date' => '2023-01-10'],
        ['amount' => 70000, 'date' => '2023-08-02'],
        ['amount' => 9400, 'date' => '2024-11-19'],
        ['amount' => 48000, 'date' => '2025-03-04'],
        ['amount' => 90700, 'date' => '2025-05-10'],
    ];

    foreach ($records as $i => $record) {
        $expense = Expense::create([
            'user_id' => $user->id,
            'title' => 'Belanja '.($i + 1),
            'amount' => $record['amount'],
            'date_shopping' => $record['date'],
        ]);

        // created_at sengaja dibuat tanggal lama juga, supaya query yang masih
        // memfilter "created_at >= awal bulan" pasti menghasilkan angka 0.
        $expense->forceFill(['created_at' => $record['date'].' 10:00:00'])->save();
    }

    return $records;
}

test('badge navigasi Expenses menghitung semua expense tanpa filter periode', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    seedLegacyExpensesFor($user);

    expect(ExpenseResource::getNavigationBadge())->toBe('5');
    expect(Expense::query()->count())->toBe(5);
});

test('widget statistik Dashboard dihitung dari seluruh expense (semua waktu)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    seedLegacyExpensesFor($user);

    $stats = (new ReflectionMethod(StatsOverview::class, 'getStats'))->invoke(new StatsOverview());

    expect($stats)->toHaveCount(3);

    expect($stats[0]->getLabel())->toBe('Total Pengeluaran Keseluruhan');
    expect($stats[0]->getValue())->toBe('Rp 418.100');

    expect($stats[1]->getLabel())->toBe('Jumlah Transaksi Keseluruhan');
    expect($stats[1]->getValue())->toBe('5');

    expect($stats[2]->getLabel())->toBe('Rata-rata per Transaksi');
    // 418.100 / 5 = 83.620
    expect($stats[2]->getValue())->toBe('Rp 83.620');
});

test('grafik garis menampilkan seluruh riwayat berdasarkan date_shopping', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    seedLegacyExpensesFor($user);

    $data = (new ReflectionMethod(ExpenseLineChart::class, 'getData'))->invoke(new ExpenseLineChart());

    expect($data['labels'])->toHaveCount(5);
    expect($data['datasets'][0]['data'])->toBe([200000.0, 70000.0, 9400.0, 48000.0, 90700.0]);
});

test('grafik kategori menghitung dari seluruh expense tanpa filter bulan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $food = Category::resolveFromLabel('Makanan & Minuman', $user->id);
    $transport = Category::resolveFromLabel('Transportasi', $user->id);

    $records = [
        ['amount' => 200000, 'date' => '2023-01-10', 'category_id' => $food->id],
        ['amount' => 70000, 'date' => '2023-08-02', 'category_id' => $transport->id],
        ['amount' => 9400, 'date' => '2024-11-19', 'category_id' => $food->id],
        ['amount' => 48000, 'date' => '2025-03-04', 'category_id' => null],
        ['amount' => 90700, 'date' => '2025-05-10', 'category_id' => $food->id],
    ];

    foreach ($records as $i => $record) {
        $expense = Expense::create([
            'user_id' => $user->id,
            'title' => 'Belanja '.($i + 1),
            'amount' => $record['amount'],
            'date_shopping' => $record['date'],
            'category_id' => $record['category_id'],
        ]);
        $expense->forceFill(['created_at' => $record['date'].' 10:00:00'])->save();
    }

    $data = (new ReflectionMethod(CategoryChart::class, 'getData'))->invoke(new CategoryChart());

    expect($data['labels'])->toContain('Makanan & Minuman');
    expect($data['labels'])->toContain('Transportasi');
    expect($data['labels'])->toContain('Tanpa Kategori');

    // Semua irisan dijumlahkan = total seluruh expense (semua waktu).
    expect(array_sum($data['datasets'][0]['data']))->toBe(418100.0);
});
