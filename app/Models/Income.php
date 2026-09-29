<?php

namespace App\Models;

use App\Models\Scopes\OwnedByUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Catatan pemasukan (uang masuk): sumber, nominal, dan tanggal diterima.
 *
 * Kebalikan dari Expense — belum ada relasi/perhitungan gabungan dengan
 * pengeluaran di sini; fondasi "Kas Arus" (income - expense) dibangun
 * terpisah di atas model ini.
 *
 * Isolasi data per-user memakai global scope OwnedByUserScope + hook
 * creating yang menimpa user_id dengan user login (anti-spoofing) —
 * pola sama dengan Expense, Budget, dan Debt, sehingga aman untuk mode
 * personal maupun UMKM multi-user.
 */
class Income extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'amount',
        'date_received',
        'notes',
    ];

    protected $casts = [
        // decimal:2 menjaga nominal tetap presisi 2 desimal saat dibaca;
        // konsisten dengan pola amount di Budget & Debt.
        'amount' => 'decimal:2',
        // Kolom date → akses via Eloquent selalu berupa instance Carbon.
        'date_received' => 'date',
    ];

    /**
     * Global scope OwnedByUserScope (query hanya pemasukan user login) +
     * hook creating yang memaksa user_id ke user login saat terautentikasi
     * (anti-spoofing mass assignment); di console/queue tanpa auth, user_id
     * eksplisit (seeder/test) dipertahankan — pola sama dengan Expense.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByUserScope);

        static::creating(function (Income $income): void {
            if (Auth::check()) {
                $income->user_id = Auth::id();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(related: User::class);
    }

    /**
     * Total pemasukan (SUM amount) SELURUH waktu tanpa filter tanggal —
     * dipakai kartu statistik Dashboard "Total Pemasukan", mengikuti gaya
     * StatsOverview yang juga menghitung all-time. Query memakai model yang
     * sudah ter-scope OwnedByUserScope, jadi angkanya selalu per-user.
     */
    public static function totalAllTime(): float
    {
        return (float) static::query()->sum('amount');
    }
}
