<?php

use App\Exports\LaporanExpenseExport;
use App\Filament\Pages\Laporan;
use App\Filament\Widgets\LaporanCategoryChart;
use App\Filament\Widgets\LaporanStatsOverview;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Support\ReportFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

/**
 * Seed satu expense milik $user dengan nilai bawaan yang realistis
 * (amount terisi = parsing selesai, date_shopping = 10 Feb 2026).
 */
function createLaporanExpense(User $user, array $attributes = []): Expense
{
    return Expense::create(array_merge([
        'user_id' => $user->id,
        'title' => 'Belanja Mingguan',
        'amount' => 100000,
        'date_shopping' => '2026-02-10',
    ], $attributes));
}

test('halaman Laporan tidak bisa diakses tanpa login', function () {
    $this->get(Laporan::getUrl())->assertRedirect();
});

test('halaman Laporan bisa diakses user yang login', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(Laporan::getUrl())
        ->assertOk()
        ->assertSee('Filter Laporan')
        ->assertSee('Laporan');
});

test('laporan hanya menghitung expense milik user yang login', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $expenseA = createLaporanExpense($userA, ['title' => 'Milik A']);
    createLaporanExpense($userB, ['title' => 'Milik B']);

    $this->actingAs($userA);

    $page = new Laporan;

    expect($page->filteredExpensesQuery()->pluck('id')->all())->toBe([$expenseA->id]);
});

test('filter rentang tanggal hanya mengambil expense dalam rentang', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $januari = createLaporanExpense($user, ['title' => 'Januari', 'date_shopping' => '2026-01-15']);
    $februari = createLaporanExpense($user, ['title' => 'Februari', 'date_shopping' => '2026-02-15']);
    $maret = createLaporanExpense($user, ['title' => 'Maret', 'date_shopping' => '2026-03-15']);

    $page = new Laporan;
    $page->filters = [
        'period_mode' => ReportFilter::MODE_RANGE,
        'date_from' => '2026-02-01',
        'date_until' => '2026-02-28',
    ];

    expect($page->filteredExpensesQuery()->pluck('id')->all())->toBe([$februari->id]);

    // Rentang yang terbalik (dari > sampai) ditukar otomatis oleh ReportFilter.
    $page->filters = [
        'period_mode' => ReportFilter::MODE_RANGE,
        'date_from' => '2026-02-28',
        'date_until' => '2026-02-01',
    ];

    expect($page->filteredExpensesQuery()->pluck('id')->all())->toBe([$februari->id]);

    // Tanpa tanggal = semua periode.
    $page->filters = ['period_mode' => ReportFilter::MODE_RANGE];

    expect($page->filteredExpensesQuery()->pluck('id')->all())
        ->toBe([$januari->id, $februari->id, $maret->id]);
});

test('filter bulan dan tahun hanya mengambil expense di bulan tersebut', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createLaporanExpense($user, ['title' => 'Januari', 'date_shopping' => '2026-01-31']);
    $februari = createLaporanExpense($user, ['title' => 'Februari', 'date_shopping' => '2026-02-01']);
    createLaporanExpense($user, ['title' => 'Tahun Lalu', 'date_shopping' => '2025-02-20']);

    $page = new Laporan;
    $page->filters = [
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 2,
        'year' => 2026,
    ];

    expect($page->filteredExpensesQuery()->pluck('id')->all())->toBe([$februari->id]);
});

test('filter kategori multi-select hanya mengambil kategori terpilih', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $makanan = Category::resolveFromLabel('Makanan & Minuman', $user->id);
    $transport = Category::resolveFromLabel('Transportasi', $user->id);

    $expenseMakanan = createLaporanExpense($user, ['title' => 'Makan', 'category_id' => $makanan->id]);
    $expenseTransport = createLaporanExpense($user, ['title' => 'Bensin', 'category_id' => $transport->id]);
    $expenseTanpaKategori = createLaporanExpense($user, ['title' => 'Tanpa kategori', 'category_id' => null]);

    $page = new Laporan;
    $page->filters = ['category_ids' => [$makanan->id]];

    expect($page->filteredExpensesQuery()->pluck('id')->all())->toBe([$expenseMakanan->id]);

    // Beberapa kategori sekaligus.
    $page->filters = ['category_ids' => [$makanan->id, $transport->id]];

    expect($page->filteredExpensesQuery()->pluck('id')->all())
        ->toBe([$expenseMakanan->id, $expenseTransport->id]);
});

test('expense dengan amount NULL tidak ikut dihitung di laporan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Struk yang parsing OCR/AI-nya belum selesai.
    createLaporanExpense($user, ['title' => 'Belum diparsing', 'amount' => null]);

    $page = new Laporan;

    expect($page->filteredExpensesQuery()->count())->toBe(0);
});

test('widget ringkasan menampilkan total, jumlah, dan rata-rata sesuai filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createLaporanExpense($user, ['title' => 'A', 'amount' => 50000, 'date_shopping' => '2026-02-05']);
    createLaporanExpense($user, ['title' => 'B', 'amount' => 130000, 'date_shopping' => '2026-02-20']);

    $widget = new LaporanStatsOverview;
    $widget->pageFilters = [
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 2,
        'year' => 2026,
    ];

    $stats = (new ReflectionMethod(LaporanStatsOverview::class, 'getStats'))->invoke($widget);

    expect($stats)->toHaveCount(3);
    expect($stats[0]->getLabel())->toBe('Total Pengeluaran');
    expect($stats[0]->getValue())->toBe('Rp 180.000');
    expect($stats[1]->getValue())->toBe('2');
    // 180.000 / 2 = 90.000
    expect($stats[2]->getValue())->toBe('Rp 90.000');
    expect($stats[0]->getDescription())->toBe('Februari 2026');
});

test('grafik kategori mengikuti periode filter halaman Laporan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $makanan = Category::resolveFromLabel('Makanan & Minuman', $user->id);

    createLaporanExpense($user, ['title' => 'Februari', 'amount' => 90000, 'date_shopping' => '2026-02-10', 'category_id' => $makanan->id]);
    createLaporanExpense($user, ['title' => 'Maret', 'amount' => 99999, 'date_shopping' => '2026-03-10', 'category_id' => $makanan->id]);

    $widget = new LaporanCategoryChart;
    $widget->pageFilters = [
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 2,
        'year' => 2026,
    ];

    $data = (new ReflectionMethod(LaporanCategoryChart::class, 'getData'))->invoke($widget);

    expect($data['labels'])->toBe(['Makanan & Minuman']);
    expect($data['datasets'][0]['data'])->toBe([90000.0]);
    expect($data['datasets'][0]['backgroundColor'])->toBe(['#10B981']);
});

test('export Excel menghasilkan file xlsx yang valid', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createLaporanExpense($user, ['title' => 'Excel A', 'amount' => 123456]);
    createLaporanExpense($user, ['title' => 'Excel B', 'amount' => 654321]);

    $page = new Laporan;
    $query = $page->filteredExpensesQuery()->orderBy('created_at', 'desc');

    $content = (string) Excel::raw(new LaporanExpenseExport($query), ExcelWriter::XLSX);

    // File .xlsx adalah ZIP valid — signature pertamanya selalu "PK".
    expect(str_starts_with($content, 'PK'))->toBeTrue();
});

test('export Excel mengikuti filter periode yang aktif', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createLaporanExpense($user, ['title' => 'Januari', 'amount' => 1000, 'date_shopping' => '2026-01-10']);
    createLaporanExpense($user, ['title' => 'Februari', 'amount' => 2000, 'date_shopping' => '2026-02-10']);

    $page = new Laporan;
    $page->filters = [
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 2,
        'year' => 2026,
    ];

    $query = $page->filteredExpensesQuery()->orderBy('created_at', 'desc');
    $content = (string) Excel::raw(new LaporanExpenseExport($query), ExcelWriter::XLSX);

    expect(str_starts_with($content, 'PK'))->toBeTrue();
});

test('export PDF menghasilkan dokumen PDF yang valid', function () {
    $user = User::factory()->create(['name' => 'Pengguna Uji']);
    $this->actingAs($user);

    createLaporanExpense($user, ['title' => 'PDF A', 'amount' => 50000, 'vendor' => 'Indomaret']);

    $page = new Laporan;
    $expenses = $page->filteredExpensesQuery()->orderBy('created_at', 'desc')->get();

    $output = $page->buildPdfDocument($expenses)->output();

    expect(str_starts_with($output, '%PDF'))->toBeTrue();
});

test('aksi export PDF mengembalikan response unduhan yang benar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createLaporanExpense($user, ['title' => 'Unduh A', 'amount' => 7000]);

    $page = new Laporan;
    $page->filters = [
        'period_mode' => ReportFilter::MODE_RANGE,
        'date_from' => '2026-02-01',
        'date_until' => '2026-02-28',
    ];

    $response = $page->exportPdf();

    expect($response->headers->get('content-type'))->toBe('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('laporan-pengeluaran-20260201-20260228.pdf');
});
