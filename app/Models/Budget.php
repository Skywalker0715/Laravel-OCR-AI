<?php

namespace App\Models;

use App\Models\Scopes\OwnedByUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Anggaran (budget) belanja untuk satu periode bulan + tahun; bisa umum
 * (category_id NULL = semua kategori) atau khusus satu kategori. Isolasi
 * data per-user lewat global scope OwnedByUserScope (aman personal & UMKM).
 */
class Budget extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'amount',
        'month',
        'year',
        // Flag notified_90_at/notified_100_at sengaja tidak di $fillable: state internal
        // BudgetAlertService (ditulis via query builder), tak bisa dipalsukan via mass assignment.
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'month' => 'integer',
        'year' => 'integer',
        'notified_90_at' => 'datetime',
        'notified_100_at' => 'datetime',
    ];

    /**
     * Global scope OwnedByUserScope (query hanya budget user login) + hook creating
     * yang memaksa user_id ke user login saat terautentikasi — anti-spoofing,
     * pola sama dengan Expense.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByUserScope);

        static::creating(function (Budget $budget): void {
            if (Auth::check()) {
                $budget->user_id = Auth::id();
            }
        });
    }

    /**
     * Daftar nama bulan Indonesia untuk dropdown form dan label tabel.
     */
    public static function monthOptions(): array
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(related: User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(related: Category::class);
    }

    /**
     * Total pengeluaran terpakai dari anggaran ini: expense pemilik budget pada periode
     * bulan+tahun yang sama (date_shopping), difilter kategori bila budget khusus kategori.
     *
     * Angka ini dihitung lewat spentAmountsFor() — method yang sama dipakai kolom
     * "Terpakai" pada tabel Budgets — supaya nilai di tabel, di halaman View Category,
     * dan di notifikasi budget tidak mungkin berbeda satu rupiah pun.
     */
    public function spentAmount(): float
    {
        return (float) (self::spentAmountsFor(collect([$this]))[$this->getKey()] ?? 0.0);
    }

    /**
     * Nominal terpakai untuk SEKOLLEKSI budget dengan SATU query agregat — bukan
     * satu query per baris seperti implementasi lama.
     *
     * Cara kerja: satu query GROUP BY (user_id, category_id) dengan satu kolom
     * SUM() kondisional untuk SETIAP periode bulan/tahun yang muncul di halaman.
     * Jadi berapa pun jumlah baris tabel, jumlah query tetap satu.
     *
     * Perbandingan periode memakai rentang tanggal (BETWEEN awal–akhir bulan)
     * sehingga kondisi WHERE tetap sargable dan dapat memakai index
     * expenses(user_id, date_shopping). Versi lama memakai whereYear()/whereMonth()
     * yang membungkus kolom tanggal di dalam fungsi — index tidak terpakai.
     *
     * @param  Collection<int, Budget>  $budgets  budget yang sedang ditampilkan
     * @return array<int|string, float>  peta id budget => nominal terpakai
     */
    public static function spentAmountsFor(Collection $budgets): array
    {
        if ($budgets->isEmpty()) {
            return [];
        }

        // Periode unik yang muncul di halaman; setiap periode jadi satu kolom SUM.
        $periods = $budgets
            ->map(fn (Budget $budget): string => sprintf('%04d-%02d', $budget->year, $budget->month))
            ->unique()
            ->values();

        $selects = [];
        $bindings = [];

        foreach ($periods as $index => $period) {
            $month = Carbon::createFromFormat('Y-m', $period);

            $selects[] = 'SUM(CASE WHEN date_shopping BETWEEN ? AND ? THEN amount ELSE 0 END) AS spent_'.$index;
            $bindings[] = $month->copy()->startOfMonth()->toDateString();
            $bindings[] = $month->copy()->endOfMonth()->toDateString();
        }

        $rows = Expense::query()
            ->whereIn('user_id', $budgets->pluck('user_id')->unique()->values()->all())
            // Expense dengan amount NULL (parsing gagal total) tidak ikut dihitung.
            ->whereNotNull('amount')
            // Hanya expense bertanggal (date_shopping) yang bisa masuk periode budget.
            ->whereNotNull('date_shopping')
            ->select('user_id', 'category_id')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->groupBy('user_id', 'category_id')
            ->get();

        // [user_id][category_id] => baris agregat. category_id NULL (expense tanpa
        // kategori) ikut punya barisnya sendiri karena GROUP BY nullable.
        $byCategory = [];

        foreach ($rows as $row) {
            $byCategory[(int) $row->user_id][$row->category_id === null ? null : (int) $row->category_id] = $row;
        }

        $spent = [];

        foreach ($budgets as $budget) {
            $period = sprintf('%04d-%02d', $budget->year, $budget->month);
            $periodIndex = $periods->search($period);

            if ($periodIndex === false) {
                $spent[$budget->getKey()] = 0.0;

                continue;
            }

            $column = 'spent_'.$periodIndex;
            $userCategories = $byCategory[(int) $budget->user_id] ?? [];

            $spent[$budget->getKey()] = $budget->category_id !== null
                // Budget khusus kategori: hanya baris kategori itu.
                ? (float) ($userCategories[(int) $budget->category_id]->{$column} ?? 0)
                // Budget umum ("Semua kategori"): jumlah SEMUA kategori pada
                // periode itu — himpunan barisnya sama persis dengan query lama.
                : array_sum(array_map(
                    fn (object $row): float => (float) ($row->{$column} ?? 0),
                    array_values($userCategories),
                ));
        }

        return $spent;
    }
}
