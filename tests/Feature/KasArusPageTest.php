<?php

use App\Exports\KasArusExport;
use App\Filament\Pages\KasArus;
use App\Filament\Widgets\KasArusChart;
use App\Filament\Widgets\KasArusStatsOverview;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\ReportFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

/*
 * Fitur halaman Kas Arus (BARU & terpisah penuh dari Laporan): ringkasan
 * Total Pemasukan / Total Pengeluaran / Saldo, chart per bulan, tabel
 * breakdown, dan export PDF & Excel dengan class/template sendiri.
 * Cakupan test:
 *  - menu sidebar grup "Keuangan" + halaman hanya untuk user login,
 *  - hitungan saldo = pemasukan − pengeluaran (per bulan & total periode),
 *  - filter periode (rentang tanggal & bulan tertentu) bekerja,
 *  - export PDF & Excel menghasilkan file valid sesuai filter aktif,
 *  - format angka Excel '#,##0' (tanpa ",00") dengan nilai tetap numerik,
 *  - isolasi data antar user (OwnedByUserScope pada Income & Expense).
 */

/** Seed pemasukan milik $user dengan tanggal & nominal yang bisa dioverride. */
function createKasArusIncome(User $user, array $attributes = []): Income
{
    return Income::create(array_merge([
        'user_id' => $user->id,
        'source' => 'Penjualan',
        'amount' => 100000,
        'date_received' => '2026-07-10',
    ], $attributes));
}

/** Seed pengeluaran milik $user dengan tanggal & nominal yang bisa dioverride. */
function createKasArusExpense(User $user, array $attributes = []): Expense
{
    return Expense::create(array_merge([
        'user_id' => $user->id,
        'title' => 'Belanja Operasional',
        'amount' => 50000,
        'date_shopping' => '2026-07-15',
    ], $attributes));
}

/** Halaman KasArus dengan filter periode tertentu tanpa melalui HTTP (pola sama dengan LaporanPageTest). */
function kasArusPageWith(array $filters): KasArus
{
    $page = new KasArus;
    $page->filters = $filters;

    return $page;
}

/* -------------------------------------------------------------------------
 * 1. Akses & menu sidebar
 * ---------------------------------------------------------------------- */

test('menu Kas Arus muncul di sidebar grup Keuangan', function () {
    $this->actingAs(User::factory()->create());

    $content = $this->get('/admin')->assertOk()->getContent();

    expect($content)
        ->toContain('Kas Arus')
        ->toContain('Keuangan');
});

test('halaman Kas Arus tidak bisa diakses tanpa login', function () {
    $this->get(KasArus::getUrl())->assertRedirect();
});

test('halaman Kas Arus menampilkan filter, tabel breakdown, dan tombol export di header', function () {
    $this->actingAs(User::factory()->create());

    // Dua tombol export persis seperti Laporan: exportPdf (PDF) & exportExcel (XLSX).
    Livewire::test(KasArus::class)
        ->assertActionVisible('exportPdf')
        ->assertActionVisible('exportExcel');

    $this->get(KasArus::getUrl())
        ->assertOk()
        ->assertSee('Filter Kas Arus')
        ->assertSee('Breakdown per Bulan')
        ->assertSee('Export PDF')
        ->assertSee('Export Excel');
});

/* -------------------------------------------------------------------------
 * 2. Perhitungan saldo & breakdown per bulan
 * ---------------------------------------------------------------------- */

test('ringkasan dan breakdown menghitung saldo = pemasukan - pengeluaran', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 5000000, 'date_received' => '2026-07-10']);
    createKasArusIncome($user, ['amount' => 1000000, 'date_received' => '2026-08-05']);
    createKasArusExpense($user, ['amount' => 500000, 'date_shopping' => '2026-07-15']);
    createKasArusExpense($user, ['amount' => 200000, 'date_shopping' => '2026-08-20']);

    $page = kasArusPageWith(['period_mode' => ReportFilter::MODE_RANGE]);
    $rows = $page->monthlyBreakdown();

    // Urut menaik per bulan, label Indonesia.
    expect($rows->pluck('label')->all())->toBe(['Juli 2026', 'Agustus 2026']);

    expect($rows[0]['income'])->toBe(5000000.0)
        ->and($rows[0]['expense'])->toBe(500000.0)
        ->and($rows[0]['saldo'])->toBe(4500000.0)
        ->and($rows[1]['income'])->toBe(1000000.0)
        ->and($rows[1]['expense'])->toBe(200000.0)
        ->and($rows[1]['saldo'])->toBe(800000.0);

    $totals = $page->totals();

    expect($totals['income'])->toBe(6000000.0)
        ->and($totals['expense'])->toBe(700000.0)
        ->and($totals['saldo'])->toBe(5300000.0)
        // Invarian inti laporan: Saldo = Pemasukan − Pengeluaran.
        ->and($totals['saldo'])->toBe($totals['income'] - $totals['expense']);
});

/* -------------------------------------------------------------------------
 * 3. Filter periode
 * ---------------------------------------------------------------------- */

test('filter bulan tertentu hanya menghitung transaksi bulan tersebut', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 5000000, 'date_received' => '2026-07-10']);
    createKasArusIncome($user, ['amount' => 1000000, 'date_received' => '2026-08-05']);
    createKasArusExpense($user, ['amount' => 500000, 'date_shopping' => '2026-07-15']);
    createKasArusExpense($user, ['amount' => 200000, 'date_shopping' => '2026-08-20']);

    $page = kasArusPageWith([
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 7,
        'year' => 2026,
    ]);

    $rows = $page->monthlyBreakdown();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Juli 2026')
        ->and($rows[0]['income'])->toBe(5000000.0)
        ->and($rows[0]['expense'])->toBe(500000.0)
        ->and($rows[0]['saldo'])->toBe(4500000.0);

    expect($page->totals())->toBe([
        'income' => 5000000.0,
        'expense' => 500000.0,
        'saldo' => 4500000.0,
    ]);
});

test('filter rentang tanggal hanya menghitung transaksi dalam rentang', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 400000, 'date_received' => '2026-06-15']);
    createKasArusIncome($user, ['amount' => 700000, 'date_received' => '2026-07-10']);
    createKasArusIncome($user, ['amount' => 900000, 'date_received' => '2026-09-10']);
    createKasArusExpense($user, ['amount' => 300000, 'date_shopping' => '2026-07-20']);

    $page = kasArusPageWith([
        'period_mode' => ReportFilter::MODE_RANGE,
        'date_from' => '2026-07-01',
        'date_until' => '2026-07-31',
    ]);

    $rows = $page->monthlyBreakdown();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['label'])->toBe('Juli 2026')
        ->and($rows[0]['income'])->toBe(700000.0)
        ->and($rows[0]['expense'])->toBe(300000.0);

    expect($page->totals()['saldo'])->toBe(400000.0);
});

/* -------------------------------------------------------------------------
 * 4. Isolasi data antar user (OwnedByUserScope)
 * ---------------------------------------------------------------------- */

test('laporan Kas Arus terisolasi antar user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // Data milik user lain dibuat SEBELUM login (lihat catatan anti-spoofing
    // pada hook creating model — saat belum login, user_id eksplisit dipertahankan).
    createKasArusIncome($userB, ['amount' => 9999000, 'date_received' => '2026-07-05']);
    createKasArusExpense($userB, ['amount' => 8888000, 'date_shopping' => '2026-07-06']);

    $this->actingAs($userA);

    createKasArusIncome($userA, ['amount' => 500000, 'date_received' => '2026-07-10']);
    createKasArusExpense($userA, ['amount' => 200000, 'date_shopping' => '2026-07-11']);

    $page = kasArusPageWith(['period_mode' => ReportFilter::MODE_RANGE]);
    $totals = $page->totals();

    // Hanya data user A yang masuk — OwnedByUserScope aktif di Income & Expense.
    expect($totals['income'])->toBe(500000.0)
        ->and($totals['expense'])->toBe(200000.0)
        ->and($totals['saldo'])->toBe(300000.0);
});

/* -------------------------------------------------------------------------
 * 5. Widget chart & ringkasan
 * ---------------------------------------------------------------------- */

test('chart Kas Arus membandingkan pemasukan vs pengeluaran per bulan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 5000000, 'date_received' => '2026-07-10']);
    createKasArusIncome($user, ['amount' => 1000000, 'date_received' => '2026-08-05']);
    createKasArusExpense($user, ['amount' => 500000, 'date_shopping' => '2026-07-15']);
    createKasArusExpense($user, ['amount' => 200000, 'date_shopping' => '2026-08-20']);

    $widget = new KasArusChart;
    $widget->pageFilters = ['period_mode' => ReportFilter::MODE_RANGE];

    $data = (new ReflectionMethod(KasArusChart::class, 'getData'))->invoke($widget);

    expect($data['labels'])->toBe(['Juli 2026', 'Agustus 2026']);
    expect($data['datasets'][0]['label'])->toBe('Pemasukan')
        ->and($data['datasets'][0]['data'])->toBe([5000000.0, 1000000.0]);
    expect($data['datasets'][1]['label'])->toBe('Pengeluaran')
        ->and($data['datasets'][1]['data'])->toBe([500000.0, 200000.0]);
});

test('widget ringkasan Kas Arus menampilkan Total Pemasukan, Total Pengeluaran, dan Saldo', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 5000000, 'date_received' => '2026-07-10']);
    createKasArusIncome($user, ['amount' => 1000000, 'date_received' => '2026-08-05']);
    createKasArusExpense($user, ['amount' => 500000, 'date_shopping' => '2026-07-15']);
    createKasArusExpense($user, ['amount' => 200000, 'date_shopping' => '2026-08-20']);

    $widget = new KasArusStatsOverview;
    $widget->pageFilters = ['period_mode' => ReportFilter::MODE_RANGE];

    $stats = (new ReflectionMethod(KasArusStatsOverview::class, 'getStats'))->invoke($widget);

    expect($stats)->toHaveCount(3);

    expect($stats[0]->getLabel())->toBe('Total Pemasukan')
        ->and($stats[0]->getValue())->toBe('Rp 6.000.000');
    expect($stats[1]->getLabel())->toBe('Total Pengeluaran')
        ->and($stats[1]->getValue())->toBe('Rp 700.000');
    expect($stats[2]->getLabel())->toBe('Saldo')
        ->and($stats[2]->getValue())->toBe('Rp 5.300.000')
        ->and($stats[2]->getColor())->toBe('success');
});

test('kartu Saldo berwarna merah saat defisit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 100000, 'date_received' => '2026-07-10']);
    createKasArusExpense($user, ['amount' => 300000, 'date_shopping' => '2026-07-15']);

    $widget = new KasArusStatsOverview;
    $widget->pageFilters = ['period_mode' => ReportFilter::MODE_RANGE];

    $stats = (new ReflectionMethod(KasArusStatsOverview::class, 'getStats'))->invoke($widget);

    expect($stats[2]->getValue())->toBe('Rp -200.000')
        ->and($stats[2]->getColor())->toBe('danger');
});

/* -------------------------------------------------------------------------
 * 6. Export PDF & Excel
 * ---------------------------------------------------------------------- */

test('export PDF Kas Arus menghasilkan dokumen PDF yang valid', function () {
    $user = User::factory()->create(['name' => 'Pemilik Toko']);
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 2500000, 'date_received' => '2026-07-10']);
    createKasArusExpense($user, ['amount' => 750000, 'date_shopping' => '2026-07-20']);

    $page = kasArusPageWith(['period_mode' => ReportFilter::MODE_RANGE]);
    $output = $page->buildPdfDocument()->output();

    expect(str_starts_with($output, '%PDF'))->toBeTrue();
});

test('aksi export PDF Kas Arus mengembalikan response unduhan sesuai filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 2500000, 'date_received' => '2026-07-10']);
    createKasArusIncome($user, ['amount' => 999000, 'date_received' => '2026-08-10']);

    $page = kasArusPageWith([
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 7,
        'year' => 2026,
    ]);

    $response = $page->exportPdf();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('kas-arus-20260701-20260731.pdf');
});

test('export Excel Kas Arus menghasilkan file xlsx valid yang mengikuti filter aktif', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    createKasArusIncome($user, ['amount' => 2500000, 'date_received' => '2026-07-10']);
    createKasArusIncome($user, ['amount' => 999000, 'date_received' => '2026-08-10']);
    createKasArusExpense($user, ['amount' => 750000, 'date_shopping' => '2026-07-20']);

    $page = kasArusPageWith([
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 7,
        'year' => 2026,
    ]);

    $report = $page->kasArusReport();
    $export = new KasArusExport($report->monthlyBreakdown(), $report->totals());

    // File .xlsx adalah ZIP valid — signature pertamanya selalu "PK".
    $content = (string) Excel::raw($export, ExcelWriter::XLSX);
    expect(str_starts_with($content, 'PK'))->toBeTrue();

    // Isi file mengikuti filter: hanya bulan Juli 2026, bukan Agustus.
    $rows = $export->collection();
    expect($rows->pluck(0)->all())->toContain('Juli 2026')
        ->and($rows->pluck(0)->all())->not->toContain('Agustus 2026');

    // Blok ringkasan seluruh periode yang di-export ikut tertulis.
    expect($rows->slice(-3)->pluck(0)->all())
        ->toBe(['Total Pemasukan', 'Total Pengeluaran', 'Saldo Akhir']);

    // Angka Saldo Akhir pada baris terakhir = totals halaman (2.500.000 − 750.000).
    expect($rows->last()[3])->toBe(1750000.0);

    $response = $page->exportExcel();

    expect($response->headers->get('content-type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('content-disposition'))->toContain('kas-arus-20260701-20260731.xlsx');
});

test('format angka Excel Kas Arus memakai #,##0 tanpa ",00" dan nilai tetap numerik', function () {
    $export = new KasArusExport(
        collect([['Juli 2026', 5000000.0, 500000.0, 4500000.0]]),
        ['income' => 5000000.0, 'expense' => 500000.0, 'saldo' => 4500000.0],
    );

    expect($export->columnFormats())->toBe([
        'B' => '#,##0',
        'C' => '#,##0',
        'D' => '#,##0',
    ]);

    $rows = $export->collection();

    // Nilai uang berupa float (numerik), bukan string "Rp ..." berformat —
    // sehingga Excel menampilkannya dengan format #,##0 tanpa ekor ",00".
    expect(is_float($rows[0][1]))->toBeTrue()
        ->and(is_float($rows[0][3]))->toBeTrue()
        ->and(is_float($rows->last()[3]))->toBeTrue();
});



