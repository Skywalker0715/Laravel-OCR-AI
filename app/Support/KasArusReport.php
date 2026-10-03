<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Income;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Satu sumber kebenaran laporan "Kas Arus" — pemasukan dikurangi pengeluaran
 * per bulan untuk periode filter tertentu.
 *
 * Dipakai bersama oleh halaman KasArus, widget ringkasan/grafik, serta export
 * PDF & Excel sehingga angka di layar maupun di file selalu konsisten.
 *
 * Filter periode memakai ReportFilter (mode rentang tanggal / bulan tertentu),
 * hanya batas tanggalnya yang diterapkan manual per model karena kolom tanggal
 * berbeda: Income.memakai `date_received`, Expense.memakai `date_shopping` —
 * sementara ReportFilter::apply() meng-hardcode `date_shopping` sehingga tidak
 * bisa dipakai langsung untuk Income.
 *
 * Kepemilikan data TIDAK diurus di sini: global scope OwnedByUserScope pada
 * Income & Expense otomatis membatasi query ke user yang login.
 */
class KasArusReport
{
    /** Cache breakdown per instance — aman karena state filter tidak pernah berubah setelah konstruksi. */
    private ?Collection $breakdownCache = null;

    /**
     * Cache ReportFilter per instance. Tanpa ini, setiap pemanggilan
     * period()/periodLabel() membuat objek baru; objeknya memang ringan
     * (tidak query), tapi menghitung ulang state filter berulang-ulang
     * rawan jadi sumber ketidakkonsistenan bila filter nanti ikut berubah.
     */
    private ?ReportFilter $reportFilterCache = null;

    /**
     * @param  array<string, mixed>  $filters  State form filters halaman KasArus
     *                                          (period_mode, date_from, date_until, month, year).
     */
    public function __construct(private readonly array $filters = []) {}

    /** Filter halaman sebagai objek ReportFilter — logika periode selalu identik dengan halaman Laporan. */
    public function reportFilter(): ReportFilter
    {
        return $this->reportFilterCache ??= new ReportFilter($this->filters);
    }

    /** Label periode ramah-baca ("Juli 2026", "01 Jul 2026 – 31 Jul 2026", atau "Semua periode"). */
    public function periodLabel(): string
    {
        return $this->reportFilter()->periodLabel();
    }

    /**
     * Breakdown pemasukan vs pengeluaran per bulan, urut menaik dari bulan
     * terlama ke terbaru. Bulan tanpa transaksi tidak ditampilkan; periode yang
     * sama sekali kosong tetap menghasilkan satu baris nol (bulan filter bila
     * mode bulan, atau bulan berjalan) agar tabel & chart tidak blank total.
     *
     * @return Collection<int, array{key: string, label: string, income: float, expense: float, saldo: float}>
     */
    public function monthlyBreakdown(): Collection
    {
        if ($this->breakdownCache !== null) {
            return $this->breakdownCache;
        }

        [$from, $until] = $this->reportFilter()->period();

        $incomes = $this->amountsByMonth(Income::query(), 'date_received', $from, $until);
        $expenses = $this->amountsByMonth(
            Expense::query()->whereNotNull('amount'),
            'date_shopping',
            $from,
            $until,
        );

        // Gabungkan kunci bulan dari kedua model lalu urutkan menaik —
        // format 'Y-m' membuat pengurutan string = pengurutan kronologis.
        $keys = $incomes->keys()
            ->merge($expenses->keys())
            ->unique()
            ->sort()
            ->values();

        if ($keys->isEmpty()) {
            // Periode benar-benar kosong: tetap buat SATU baris nol agar tabel &
            // chart tidak blank total. Bulan yang dipakai adalah bulan AWAL
            // periode bila ada, kalau tidak bulan akhir (date_until), kalau
            // tidak (tanpa batas sama sekali) bulan berjalan.
            //
            // SEBELUMNYA baris ini memakai now() sebagai fallback — salah
            // kapok: dengan filter "s.d. 30 Juni 2026" saja (date_from null),
            // tabel menampilkan baris bulan BERJALAN yang justru di luar
            // periode filter, sehingga angka nolnya menyesatkan.
            $keys = collect([($from ?? $until ?? now())->format('Y-m')]);
        }

        return $this->breakdownCache = $keys->map(function (string $key) use ($incomes, $expenses): array {
            $income = (float) $incomes->get($key, 0);
            $expense = (float) $expenses->get($key, 0);

            return [
                'key' => $key,
                'label' => MonthExpression::monthLabel($key),
                'income' => $income,
                'expense' => $expense,
                'saldo' => $income - $expense,
            ];
        })->values();
    }

    /**
     * Total seluruh periode filter: Total Pemasukan, Total Pengeluaran, dan
     * Saldo (pemasukan − pengeluaran). Sengaja dijumlahkan DARI baris
     * breakdown agar angka ringkasan di layar/PDF/Excel dijamin persis sama
     * dengan jumlah tabel.
     *
     * @return array{income: float, expense: float, saldo: float}
     */
    public function totals(): array
    {
        $income = (float) $this->monthlyBreakdown()->sum('income');
        $expense = (float) $this->monthlyBreakdown()->sum('expense');

        return [
            'income' => $income,
            'expense' => $expense,
            'saldo' => $income - $expense,
        ];
    }

    /**
     * Seri data chart: N bulan TERAKHIR dari breakdown (urut menaik) agar
     * grafik tetap terbaca walau periode filter mencakup banyak bulan.
     *
     * @return array{labels: array<int, string>, income: array<int, float>, expense: array<int, float>}
     */
    public function chartSeries(int $months = 12): array
    {
        // take(-N) mengambil N elemen terakhir (urut tetap menaik).
        $rows = $this->monthlyBreakdown()->take(-$months);

        return [
            'labels' => $rows->pluck('label')->all(),
            'income' => $rows->pluck('income')->map(fn (mixed $value): float => (float) $value)->all(),
            'expense' => $rows->pluck('expense')->map(fn (mixed $value): float => (float) $value)->all(),
        ];
    }

    /**
     * Jumlahkan `amount` per bulan ('Y-m') dari query yang sudah dibatasi
     * periode, memakai SQL GROUP BY supaya database hanya mengembalikan satu
     * baris per bulan (bukan satu baris per transaksi). Ini yang membuat
     * laporan tetap ringan pada data besar: ribuan expense per bulan tidak
     * lagi ikut dibawa ke memori PHP.
     *
     * Fallback agregasi PHP (get -> groupBy) dipakai bila driver tidak punya
     * ekspresi bulan native yang terverifikasi — SQLite termasuk, karena
     * environment test berjalan di SQLite sementara produksi memakai
     * PostgreSQL. Kedua jalur menghasilkan angka yang sama persis, hal ini
     * dikunci oleh KasArusMonthAggregationTest.
     *
     * @return Collection<string, float>
     */
    private function amountsByMonth(Builder $query, string $dateColumn, ?CarbonImmutable $from, ?CarbonImmutable $until): Collection
    {
        $qualified = $query->getModel()->qualifyColumn($dateColumn);

        if ($from !== null) {
            $query->whereDate($qualified, '>=', $from->toDateString());
        }

        if ($until !== null) {
            $query->whereDate($qualified, '<=', $until->toDateString());
        }

        $query->whereNotNull($qualified);

        $expression = $this->monthExpressionFor($query, $qualified);

        if ($expression === null) {
            return $this->amountsByMonthInPhp($query, $dateColumn);
        }

        return $this->amountsByMonthInSql($query, $expression);
    }

    /**
     * Ekspresi SQL kunci bulan untuk query ini, atau null bila driver-nya
     * memakai jalur fallback PHP.
     *
     * SQLite SENGAJA dikembalikan null: driver produksi (PostgreSQL) memakai
     * GROUP BY di database, sedangkan test berjalan di SQLite sehingga jalur
     * fallback PHP tetap terus teruji di setiap kali test. Perbandingan
     * hasil kedua jalur dikunci KasArusMonthAggregationTest.
     */
    private function monthExpressionFor(Builder $query, string $qualifiedColumn): ?string
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return null;
        }

        return MonthExpression::for($driver, $qualifiedColumn);
    }

    /**
     * Jalur SQL: GROUP BY ekspresi bulan, SUM dihitung di database.
     *
     * COALESCE di SUM menjaga agar bulan yang seluruh nominalnya NULL tetap
     * menghasilkan 0.0, bukan null — supaya identik dengan jalur PHP yang
     * selalu mengembalikan float.
     *
     * @return Collection<string, float>
     */
    private function amountsByMonthInSql(Builder $query, string $expression): Collection
    {
        $amount = $query->getModel()->qualifyColumn('amount');

        return $query
            ->selectRaw("{$expression} AS month_key, COALESCE(SUM({$amount}), 0) AS month_total")
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get()
            ->mapWithKeys(fn (Model $row): array => [
                (string) $row->getAttribute('month_key') => (float) $row->getAttribute('month_total'),
            ]);
    }

    /**
     * Jalur fallback PHP: ambil baris tanggal+nominal lalu kelompokkan di
     * memori. Dipakai hanya bila driver tidak mendukung ekspresi bulan SQL.
     *
     * @return Collection<string, float>
     */
    private function amountsByMonthInPhp(Builder $query, string $dateColumn): Collection
    {
        return $query
            ->select([$query->getModel()->qualifyColumn($dateColumn), $query->getModel()->qualifyColumn('amount')])
            ->get()
            ->groupBy(fn (Model $row): string => $row->{$dateColumn}->format(MonthExpression::format()))
            ->map(fn (Collection $rows): float => (float) $rows->sum('amount'));
    }
}
