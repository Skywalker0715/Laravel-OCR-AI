<?php

namespace App\Models;

use App\Models\Scopes\OwnedByUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Anggaran (budget) belanja untuk satu periode bulan + tahun.
 *
 * Budget bisa bersifat umum (category_id NULL, mencakup semua kategori)
 * atau khusus untuk satu kategori. Sama seperti Expense, isolasi datanya
 * per-user lewat global scope OwnedByUserScope sehingga mode personal
 * maupun UMKM (multi-user) sama-sama aman.
 */
class Budget extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'amount',
        'month',
        'year',
        // Flag notifikasi ambang budget (notified_90_at / notified_100_at,
        // migration 2026_09_04_000001) SENGAJA tidak ada di $fillable: itu
        // state internal BudgetAlertService yang ditulis lewat query builder
        // (UPDATE ... WHERE flag IS NULL) — bukan lewat mass assignment —
        // sehingga tidak mungkin dipalsukan dari input manapun.
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'month' => 'integer',
        'year' => 'integer',
        'notified_90_at' => 'datetime',
        'notified_100_at' => 'datetime',
    ];

    /**
     * Registrasi global scope + anti-spoofing kepemilikan (pola sama dengan
     * Expense): semua query otomatis hanya menyentuh budget milik user
     * yang sedang login, dan hook creating memaksa ownership: di konteks
     * terautentikasi, user_id dari input selalu ditimpa dengan user yang
     * sedang login.
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
     * Total pengeluaran yang sudah terpakai dari anggaran ini.
     *
     * Dihitung dari expense milik pemilik budget pada periode bulan+tahun
     * yang sama (berdasarkan date_shopping). Jika budget punya kategori,
     * hanya expense dengan kategori itu yang dihitung; jika tidak, semua
     * expense pada periode tersebut dihitung.
     *
     * Catatan sadar-trade-off: query ini dijalankan per baris tabel.
     * Daftar budget realistis hanya belasan baris (12 bulan x beberapa
     * kategori), jadi biayanya kecil dibanding kompleksitas subquery SQL
     * lintas-database (PostgreSQL/MySQL).
     */
    public function spentAmount(): float
    {
        return (float) Expense::query()
            ->where('user_id', $this->user_id)
            // Hanya jumlahkan expense yang benar-benar punya nominal. Expense
            // yang parsingnya gagal total (amount NULL) tidak boleh ikut
            // didata sebagai "sudah terpakai".
            ->whereNotNull('amount')
            ->when(
                $this->category_id !== null,
                // Budget khusus kategori: hanya hitung expense kategori tsb yang
                // category_id-nya terisi.
                fn ($query) => $query
                    ->where('category_id', $this->category_id)
                    ->whereNotNull('category_id'),
            )
            // Hanya expense bertanggal di periode budget tsb (date_shopping).
            ->whereNotNull('date_shopping')
            ->whereYear('date_shopping', $this->year)
            ->whereMonth('date_shopping', $this->month)
            ->sum('amount');
    }
}
