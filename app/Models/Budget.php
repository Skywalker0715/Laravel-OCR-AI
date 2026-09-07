<?php

namespace App\Models;

use App\Models\Scopes\OwnedByUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * Dihitung per baris — daftar budget realistis hanya belasan baris, biayanya kecil.
     */
    public function spentAmount(): float
    {
        return (float) Expense::query()
            ->where('user_id', $this->user_id)
            // Expense dengan amount NULL (parsing gagal total) tidak ikut dihitung.
            ->whereNotNull('amount')
            ->when(
                $this->category_id !== null,
                // Budget khusus kategori: hanya expense kategori tsb yang terhitung.
                fn ($query) => $query
                    ->where('category_id', $this->category_id)
                    ->whereNotNull('category_id'),
            )
            // Hanya expense bertanggal dalam periode budget (date_shopping).
            ->whereNotNull('date_shopping')
            ->whereYear('date_shopping', $this->year)
            ->whereMonth('date_shopping', $this->month)
            ->sum('amount');
    }
}
