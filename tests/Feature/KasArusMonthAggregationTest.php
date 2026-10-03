<?php

use App\Filament\Pages\KasArus;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\KasArusReport;
use App\Support\MonthExpression;
use App\Support\ReportFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Pengujian agregasi per bulan KasArusReport (TASK 4B).
 *
 * amountsByMonth() punya DUA jalur:
 *  - jalur SQL : GROUP BY ekspresi bulan, SUM dihitung database (dipakai
 *                driver produksi PostgreSQL),
 *  - jalur PHP : ambil baris tanggal+nominal lalu groupBy di memori (fallback).
 *
 * Test di bawah menjalankan KEDUA jalur pada data yang sama, lalu membandingkan
 * hasilnya. Ini mengunci invarian terpenting dari perubahan ini: hasil kedua
 * jalur harus identik, karena angka di layar, PDF, dan Excel semuanya
 * diturunkan dari nilai yang sama.
 *
 * Test berjalan di SQLite, sedangkan produksi memakai PostgreSQL. Jalur SQL di
 * sini memakai ekspresi strftime() SQLite (satu-satunya padanan yang terverifikasi
 * di driver test) â€” bentuknya sama dengan to_char()/DATE_FORMAT() di driver
 * lain: sama-sama mengubah kolom tanggal menjadi teks 'Y-m'.
 */

/** Panggil amountsByMonthInSql() (private) lewat reflection. */
function invokeAmountsByMonthSql(KasArusReport $report, Builder $query, string $expression): Collection
{
    return (new ReflectionMethod($report, 'amountsByMonthInSql'))
        ->invoke($report, $query, $expression);
}

/** Panggil amountsByMonthInPhp() (private) lewat reflection. */
function invokeAmountsByMonthPhp(KasArusReport $report, Builder $query, string $dateColumn): Collection
{
    return (new ReflectionMethod($report, 'amountsByMonthInPhp'))
        ->invoke($report, $query, $dateColumn);
}

/**
 * Query dasar dengan pembatas periode yang sama persis seperti yang dipakai
 * amountsByMonth() sebelum memilih jalur SQL atau PHP.
 */
function kasArusQuery(Builder $query, string $dateColumn, ?string $from, ?string $until): Builder
{
    $qualified = $query->getModel()->qualifyColumn($dateColumn);

    if ($from !== null) {
        $query->whereDate($qualified, '>=', $from);
    }

    if ($until !== null) {
        $query->whereDate($qualified, '<=', $until);
    }

    return $query->whereNotNull($qualified);
}

/**
 * Jalankan kedua jalur agregasi atas query incomes/expenses yang sama.
 *
 * @return array{0: Collection<string, float>, 1: Collection<string, float>}  [viaSql, viaPhp]
 */
function aggregateBothWays(Builder $query, string $dateColumn, string $table, ?string $from = null, ?string $until = null): array
{
    $report = new KasArusReport(['period_mode' => 'range']);
    $expression = MonthExpression::for('sqlite', $table.'.'.$dateColumn);

    return [
        invokeAmountsByMonthSql($report, kasArusQuery(clone $query, $dateColumn, $from, $until), $expression),
        invokeAmountsByMonthPhp($report, kasArusQuery(clone $query, $dateColumn, $from, $until), $dateColumn),
    ];
}

test('jalur SQL dan jalur PHP menghasilkan angka per bulan yang identik', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Beberapa bulan, beberapa transaksi per bulan, plus baris tanpa tanggal
    // (harus diabaikan kedua jalur).
    foreach ([
        ['2026-01-05', 100000.25],
        ['2026-01-20', 50000.75],
        ['2026-02-10', 250000.00],
        ['2026-02-28', 1.50],
        ['2026-03-01', 99999.99],
        ['2026-12-31', 12345.67],
    ] as [$date, $amount]) {
        Income::create([
            'user_id' => $user->id,
            'source' => 'Penjualan',
            'amount' => $amount,
            'date_received' => $date,
        ]);
    }

    // Catatan: incomes.date_received NOT NULL, jadi kasus "tanpa tanggal" hanya
    // bisa diuji lewat expenses.date_shopping (yang nullable) â€” lihat test
    // "kedua jalur sama-sama mengabaikan baris tanpa tanggal" di bawah.
    [$viaSql, $viaPhp] = aggregateBothWays(Income::query(), 'date_received', 'incomes');

    // Kunci bulan sama, dan nilainya identik sampai presisi 2 desimal (kolom
    // uang di database memakai decimal(15,2)).
    expect($viaSql->keys()->all())->toBe($viaPhp->keys()->all());

    foreach ($viaPhp as $monthKey => $total) {
        expect(round($viaSql->get($monthKey), 2))->toBe(round($total, 2));
    }

    // Sanity: benar-benar teragregasi per bulan, bukan per transaksi.
    expect($viaSql->get('2026-01'))->toBe(150001.0)
        ->and($viaSql->get('2026-02'))->toBe(250001.5)
        ->and($viaSql)->toHaveCount(4);
});

test('kedua jalur sama-sama mengabaikan baris tanpa tanggal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Expense::create(['user_id' => $user->id, 'title' => 'Bertanggal', 'amount' => 50000, 'date_shopping' => '2026-05-10']);
    Expense::create(['user_id' => $user->id, 'title' => 'Tanpa Tanggal', 'amount' => 999000, 'date_shopping' => null]);

    [$viaSql, $viaPhp] = aggregateBothWays(Expense::query(), 'date_shopping', 'expenses');

    expect($viaSql->all())->toBe(['2026-05' => 50000.0])
        ->and($viaPhp->all())->toBe($viaSql->all());
});

test('kedua jalur menghormati batas periode yang sama', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-01-10', '2026-02-10', '2026-03-10'] as $date) {
        Income::create([
            'user_id' => $user->id,
            'source' => 'Penjualan',
            'amount' => 10000,
            'date_received' => $date,
        ]);
    }

    [$viaSql, $viaPhp] = aggregateBothWays(Income::query(), 'date_received', 'incomes', '2026-02-01', '2026-02-28');

    expect($viaSql->all())->toBe(['2026-02' => 10000.0])
        ->and($viaPhp->all())->toBe($viaSql->all());
});
test('hanya menampilkan bulan yang ada transaksinya, tanpa bulan kosong di antaranya', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Januari & Juni â€” Februari s.d. Mei sengaja kosong.
    foreach (['2026-01-10', '2026-06-10'] as $date) {
        Income::create([
            'user_id' => $user->id,
            'source' => 'Penjualan',
            'amount' => 10000,
            'date_received' => $date,
        ]);
    }

    $rows = (new KasArusReport(['period_mode' => 'range']))->monthlyBreakdown();

    expect($rows->pluck('key')->all())->toBe(['2026-01', '2026-06']);
});

test('periode kosong dengan hanya date_until memakai bulan AKHIR, bukan bulan berjalan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Tidak ada transaksi sama sekali; filter hanya punya batas atas.
    $rows = (new KasArusReport([
        'period_mode' => 'range',
        'date_from' => null,
        'date_until' => '2026-06-30',
    ]))->monthlyBreakdown();

    // REGRESI: sebelumnya memakai now() sebagai fallback, sehingga baris nolnya
    // muncul di bulan BERJALAN â€” jelas di luar periode "s.d. 30 Juni 2026".
    expect($rows)->toHaveCount(1);
    expect($rows[0]['key'])->toBe('2026-06')
        ->and($rows[0]['label'])->toBe('Juni 2026')
        ->and($rows[0]['income'])->toBe(0.0)
        ->and($rows[0]['expense'])->toBe(0.0)
        ->and($rows[0]['saldo'])->toBe(0.0);
});

test('periode kosong tanpa batas tanggal memakai bulan berjalan', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $rows = (new KasArusReport(['period_mode' => 'range']))->monthlyBreakdown();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['key'])->toBe(now()->format('Y-m'));
});

/* -------------------------------------------------------------------------
 * Bukti query count halaman Kas Arus (TASK 4B butir 1)
 *
 * kasArusReport() di-instance ulang di setiap panggilan, sehingga cache
 * breakdown di dalam KasArusReport tidak pernah terpakai: satu render
 * halaman (blade memanggil monthlyBreakdown() + totals()) jatuh ke 4 query.
 * Setelah di-memoize, instance yang sama dipakai -> hanya 2 query.
 * ---------------------------------------------------------------------- */

/** Hitung query database yang dijalankan $callback. */
function countQueriesDuring(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
    }

    return $count;
}

/** Halaman KasArus dengan filter tertentu, tanpa lewat HTTP. */
function kasArusPageUsing(array $filters): KasArus
{
    $page = new KasArus;
    $page->filters = $filters;

    return $page;
}

test('satu render halaman Kas Arus memakai 2 query, bukan 4 (memoize instance)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-06-10', '2026-07-10'] as $date) {
        Income::create(['user_id' => $user->id, 'source' => 'Penjualan', 'amount' => 100000, 'date_received' => $date]);
        Expense::create(['user_id' => $user->id, 'title' => 'Belanja', 'amount' => 50000, 'date_shopping' => $date]);
    }

    $page = kasArusPageUsing(['period_mode' => ReportFilter::MODE_RANGE]);

    // Yang dilakukan blade view pada satu render.
    $queries = countQueriesDuring(function () use ($page): void {
        $page->monthlyBreakdown();
        $page->totals();
    });

    // Breakdown = 1 query Income + 1 query Expense. totals() membaca cache
    // breakdown yang sama, jadi tidak menambah query.
    expect($queries)->toBe(2);
});

test('instance Kas Arus & filter dipakai ulang, dan di-refresh saat filter berubah', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Income::create(['user_id' => $user->id, 'source' => 'Penjualan', 'amount' => 100000, 'date_received' => '2026-07-10']);

    $page = kasArusPageUsing(['period_mode' => ReportFilter::MODE_RANGE]);

    $report = $page->kasArusReport();

    // Semua panggilan memakai instance yang sama (cache breakdown terpakai).
    expect($page->kasArusReport())->toBe($report)
        ->and($page->reportFilter())->toBe($report->reportFilter());

    // Ganti filter -> instance baru; angkanya mengikuti filter terbaru, bukan
    // angka basi dari cache.
    $page->filters = [
        'period_mode' => ReportFilter::MODE_MONTH,
        'month' => 1,
        'year' => 2026,
    ];

    expect($page->kasArusReport())->not->toBe($report)
        ->and($page->totals()['income'])->toBe(0.0);
});

test('bukti sebelum/sesudah: instance baru tiap panggilan berbiaya 4 query, di-memoize jadi 2', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-06-10', '2026-07-10'] as $date) {
        Income::create(['user_id' => $user->id, 'source' => 'Penjualan', 'amount' => 100000, 'date_received' => $date]);
        Expense::create(['user_id' => $user->id, 'title' => 'Belanja', 'amount' => 50000, 'date_shopping' => $date]);
    }

    $filters = ['period_mode' => ReportFilter::MODE_RANGE];

    // PERILAKU LAMA (yang diperbaiki): setiap panggilan membuat instance baru,
    // sehingga cache breakdown di KasArusReport tidak pernah dipakai ->
    // monthlyBreakdown() + totals() = 4 query.
    $before = countQueriesDuring(function () use ($filters): void {
        (new KasArusReport($filters))->monthlyBreakdown();
        (new KasArusReport($filters))->totals();
    });

    // PERILAKU BARU: halaman memakai satu instance yang sama -> 2 query.
    $page = kasArusPageUsing($filters);

    $after = countQueriesDuring(function () use ($page): void {
        $page->monthlyBreakdown();
        $page->totals();
    });

    expect($before)->toBe(4)
        ->and($after)->toBe(2);
});
