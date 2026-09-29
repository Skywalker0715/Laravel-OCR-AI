<?php

namespace App\Support;

use App\Models\Budget;
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
     * @param  array<string, mixed>  $filters  State form filters halaman KasArus
     *                                          (period_mode, date_from, date_until, month, year).
     */
    public function __construct(private readonly array $filters = []) {}

    /** Filter halaman sebagai objek ReportFilter — logika periode selalu identik dengan halaman Laporan. */
    public function reportFilter(): ReportFilter
    {
        return new ReportFilter($this->filters);
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
            $keys = collect([($from ?? now())->format('Y-m')]);
        }

        return $this->breakdownCache = $keys->map(function (string $key) use ($incomes, $expenses): array {
            $income = (float) $incomes->get($key, 0);
            $expense = (float) $expenses->get($key, 0);

            return [
                'key' => $key,
                'label' => self::monthLabel($key),
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
     * periode. Pengelompokan dilakukan di PHP (bukan lewat fungsi SQL seperti
     * DATE_FORMAT) agar hasil identik lintas driver database: SQLite dipakai
     * saat test, PostgreSQL/MySQL di produksi.
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

        return $query
            ->whereNotNull($qualified)
            ->select([$qualified, 'amount'])
            ->get()
            ->groupBy(fn (Model $row): string => $row->{$dateColumn}->format('Y-m'))
            ->map(fn (Collection $rows): float => (float) $rows->sum('amount'));
    }

    /** Label bulan Indonesia ("Juli 2026") dari kunci 'Y-m'; memakai daftar bulan yang sama dengan filter Laporan. */
    private static function monthLabel(string $key): string
    {
        $year = substr($key, 0, 4);
        $month = (int) substr($key, 5, 2);

        return (Budget::monthOptions()[$month] ?? (string) $month).' '.$year;
    }
}
