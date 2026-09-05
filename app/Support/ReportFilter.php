<?php

namespace App\Support;

use App\Models\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Normalisasi & penerapan filter halaman Laporan.
 *
 * Kelas ini menjadi SATU sumber kebenaran untuk filter periode & kategori
 * yang dipakai bersama oleh halaman Laporan, widget ringkasan, widget
 * grafik, serta export PDF/Excel — sehingga semua bagian dijamin selalu
 * menampilkan angka yang konsisten untuk filter yang sama.
 *
 * Struktur array $filters (state form "filtersForm" di halaman Laporan):
 *   - period_mode  : 'range' (rentang tanggal) | 'month' (bulan + tahun)
 *   - date_from    : ?string Y-m-d   (mode range)
 *   - date_until   : ?string Y-m-d   (mode range)
 *   - month        : ?int 1-12       (mode month)
 *   - year         : ?int            (mode month)
 *   - category_ids : ?array<int>     (opsional; kosong = semua kategori)
 */
class ReportFilter
{
    public const MODE_RANGE = 'range';

    public const MODE_MONTH = 'month';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private readonly array $filters = []) {}

    /**
     * Mode periode yang dipilih; 'range' bila tidak valid/tidak diisi.
     */
    public function mode(): string
    {
        return ($this->filters['period_mode'] ?? null) === self::MODE_MONTH
            ? self::MODE_MONTH
            : self::MODE_RANGE;
    }

    public function isMonthMode(): bool
    {
        return $this->mode() === self::MODE_MONTH;
    }

    /**
     * Batas periode hasil filter: [dari, sampai] sebagai CarbonImmutable
     * (dari = 00:00:00, sampai = 23:59:59). Nilai null berarti tidak ada
     * batas di sisi tersebut (semua waktu). Mode 'month' selalu menghasilkan
     * kedua batas terisi.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function period(): array
    {
        if ($this->isMonthMode()) {
            $from = CarbonImmutable::create($this->filteredYear(), $this->filteredMonth(), 1)->startOfDay();

            return [$from, $from->endOfMonth()->endOfDay()];
        }

        $from = $this->parseDate($this->filters['date_from'] ?? null)?->startOfDay();
        $until = $this->parseDate($this->filters['date_until'] ?? null)?->endOfDay();

        // Tukar bila rentang terbalik (date picker sudah membatasi lewat UI,
        // ini pengaman agar laporan tetap valid bila nilainya dipaksa).
        if ($from !== null && $until !== null && $from->gt($until)) {
            [$from, $until] = [$until, $from];
        }

        return [$from, $until];
    }

    /**
     * Label periode yang ramah dibaca, dipakai di deskripsi widget & header
     * file export.
     */
    public function periodLabel(): string
    {
        [$from, $until] = $this->period();

        if ($this->isMonthMode()) {
            $monthName = Budget::monthOptions()[$this->filteredMonth()] ?? (string) $this->filteredMonth();

            return "{$monthName} {$this->filteredYear()}";
        }

        if ($from !== null && $until !== null) {
            return $from->format('d M Y').' – '.$until->format('d M Y');
        }

        if ($from !== null) {
            return 'sejak '.$from->format('d M Y');
        }

        if ($until !== null) {
            return 's.d. '.$until->format('d M Y');
        }

        return 'Semua periode';
    }

    /**
     * ID kategori terpilih yang sudah dinormalisasi (int, unik). Array kosong
     * berarti tanpa filter kategori (semua kategori ikut dihitung).
     *
     * @return array<int, int>
     */
    public function categoryIds(): array
    {
        return collect($this->filters['category_ids'] ?? [])
            ->filter(fn (mixed $id): bool => $id !== null && $id !== '')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Terapkan kondisi periode & kategori ini ke query Expense.
     *
     * Kepemilikan data (user_id) TIDAK diurus di sini — itu tugas global scope
     * OwnedByUserScope pada model Expense. Kolom di-qualify agar tetap aman
     * bila query pemanggil memakai join.
     */
    public function apply(Builder $query): Builder
    {
        [$from, $until] = $this->period();
        $model = $query->getModel();

        if ($from !== null) {
            $query->whereDate($model->qualifyColumn('date_shopping'), '>=', $from->toDateString());
        }

        if ($until !== null) {
            $query->whereDate($model->qualifyColumn('date_shopping'), '<=', $until->toDateString());
        }

        if ($categoryIds = $this->categoryIds()) {
            $query->whereIn($model->qualifyColumn('category_id'), $categoryIds);
        }

        return $query;
    }

    private function filteredMonth(): int
    {
        return (int) max(1, min(12, (int) ($this->filters['month'] ?? now()->format('n'))));
    }

    private function filteredYear(): int
    {
        $year = (int) ($this->filters['year'] ?? now()->format('Y'));

        // Batasi ke rentang tahun masuk akal agar Carbon tidak melempar
        // exception bila nilai tahun diformat tidak valid oleh user.
        if ($year < 1900 || $year > 2300) {
            $year = (int) now()->format('Y');
        }

        return $year;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
